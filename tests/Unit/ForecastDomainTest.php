<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Forecast\ConsumptionForecastModel;
use App\Domain\Forecast\ForecastConfidence;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Forecast\ForecastSnapshot;
use App\Domain\Forecast\SolarForecastModel;
use App\Domain\Intelligence\Detectors\DataLimitationDetector;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightTaxonomy;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Solar\DTO\SolarCredentials;
use App\Integrations\Weather\FakeWeatherProvider;
use App\Repositories\BillRepository;
use App\Repositories\ForecastRepository;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Database;
use App\Support\Security\Encryption;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class ForecastDomainTest
{
    /** @var list<string> */
    private static array $createdBills = [];

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    private static function verifiedBill(string $hhId, string $start, string $end, string $consumptionKwh): Bill
    {
        $bill = Bill::fromStorageRow([
            'id' => self::uuid(),
            'household_id' => $hhId,
            'status' => BillStatus::VERIFIED,
            'billing_period_start' => $start,
            'billing_period_end' => $end,
            'consumption_kwh' => $consumptionKwh,
            'verified_at' => '2026-08-01 10:00:00',
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
        self::$createdBills[] = $bill->id();

        return $bill;
    }

    public static function run(): array
    {
        AuthTesting::configure();

        return [
            TestResult::check('ForecastHorizon validates horizons and computes correct time windows', function (): void {
                Assert::same(ForecastHorizon::DAY_AHEAD, ForecastHorizon::validate('day_ahead'));
                Assert::same(ForecastHorizon::SEVEN_DAY, ForecastHorizon::validate('SEVEN_DAY'));
                Assert::same(ForecastHorizon::MONTHLY, ForecastHorizon::validate('monthly'));

                $ref = new DateTimeImmutable('2026-09-22 10:00:00', new DateTimeZone('UTC'));
                $dayWindow = ForecastHorizon::computeTimeWindow(ForecastHorizon::DAY_AHEAD, $ref);
                Assert::same(24, $dayWindow['hours']);
                Assert::same(ForecastHorizon::RESOLUTION_HOURLY, $dayWindow['resolution']);

                $sevenWindow = ForecastHorizon::computeTimeWindow(ForecastHorizon::SEVEN_DAY, $ref);
                Assert::same(7, $sevenWindow['days']);
                Assert::same(ForecastHorizon::RESOLUTION_DAILY, $sevenWindow['resolution']);

                $monthWindow = ForecastHorizon::computeTimeWindow(ForecastHorizon::MONTHLY, $ref);
                Assert::same(30, $monthWindow['days']);
            }),

            TestResult::check('ForecastConfidence calculates worst-of aggregation correctly', function (): void {
                Assert::same(ForecastConfidence::HIGH, ForecastConfidence::worstOf(ForecastConfidence::HIGH));
                Assert::same(ForecastConfidence::LOW, ForecastConfidence::worstOf(ForecastConfidence::HIGH, ForecastConfidence::LOW));
                Assert::same(ForecastConfidence::INSUFFICIENT, ForecastConfidence::worstOf(ForecastConfidence::MODERATE, ForecastConfidence::INSUFFICIENT));
            }),

            TestResult::check('SolarForecastModel generates low-confidence baseline when 0 solar readings exist', function (): void {
                $context = AuthTesting::householdFixture('fc-zero-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $readingsRepo = new SolarReadingRepository();
                $fakeWeather = new FakeWeatherProvider();
                $model = new SolarForecastModel($readingsRepo, $fakeWeather);

                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref, null);

                Assert::same('SOLAR', $snapshot->forecastType());
                Assert::same(ForecastConfidence::LOW, $snapshot->confidence());
                Assert::same(ForecastSnapshot::NATURE_ESTIMATED, $snapshot->dataNature());
                Assert::true(count($snapshot->limitations()) > 0, 'Must contain limitations');
                Assert::true(str_contains(implode(' ', $snapshot->limitations()), 'No historical solar telemetry'), 'Explains baseline heuristic');
            }),

            TestResult::check('SolarForecastModel improves confidence as historical records accumulate', function (): void {
                $context = AuthTesting::householdFixture('fc-high-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $readingsRepo = new SolarReadingRepository();
                $now = new DateTimeImmutable('2026-09-22 12:00:00', new DateTimeZone('UTC'));

                // Seed 550 readings into solar_readings for this household
                $batchId = self::uuid();
                $pdo = Database::connection();
                $insertStmt = $pdo->prepare(<<<'SQL'
                    INSERT INTO solar_readings (
                        id, household_id, station_id, inverter_sn,
                        measurement_time, received_at, power_w,
                        daily_generation_kwh, inverter_status, data_nature,
                        data_status, data_quality, sync_batch_id, created_at
                    ) VALUES (
                        :id, :household_id, :station_id, :inverter_sn,
                        :measurement_time, :received_at, :power_w,
                        :daily_kwh, 'NORMAL', 'ACTUAL', 'FRESH', 'GOOD', :batch_id, :created_at
                    )
                SQL);

                for ($i = 0; $i < 550; $i++) {
                    $mTime = $now->modify(sprintf('-%d minutes', $i * 5));
                    $insertStmt->execute([
                        'id' => self::uuid(),
                        'household_id' => $hhId,
                        'station_id' => 'st-001',
                        'inverter_sn' => 'GW5000',
                        'measurement_time' => $mTime->format('Y-m-d H:i:s'),
                        'received_at' => $mTime->format('Y-m-d H:i:s'),
                        'power_w' => '4200.00',
                        'daily_kwh' => '18.500',
                        'batch_id' => $batchId,
                        'created_at' => $mTime->format('Y-m-d H:i:s'),
                    ]);
                }

                $fakeWeather = new FakeWeatherProvider();
                $model = new SolarForecastModel($readingsRepo, $fakeWeather);

                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $now, [
                    'connection_status' => 'CONNECTED',
                ]);

                Assert::same(ForecastConfidence::HIGH, $snapshot->confidence());
                Assert::same(ForecastSnapshot::NATURE_PREDICTED, $snapshot->dataNature());
                Assert::same(ForecastSnapshot::QUALITY_GOOD, $snapshot->dataQuality());
                $range = $snapshot->uncertaintyRange();
                Assert::true($range['lower'] > 0, 'Lower bound must be non-negative');
                Assert::true($range['upper'] > $snapshot->predictedValue(), 'Upper bound must exceed predicted value');
            }),

            TestResult::check('SolarForecastModel degrades gracefully with explicit limitation on weather provider failure', function (): void {
                $context = AuthTesting::householdFixture('fc-weather-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $readingsRepo = new SolarReadingRepository();
                $fakeWeather = new FakeWeatherProvider();
                $fakeWeather->setSimulateFailure(true); // Weather service down

                $model = new SolarForecastModel($readingsRepo, $fakeWeather);
                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref, [
                    'connection_status' => 'CONNECTED',
                ]);

                Assert::same(ForecastConfidence::LOW, $snapshot->confidence());
                $limitationText = implode(' ', $snapshot->limitations());
                Assert::true(str_contains($limitationText, 'External weather provider unavailable'), 'Explicit limitation recorded');
                Assert::false($snapshot->provenance()['weather_available'], 'Provenance notes weather unavailability');
            }),

            TestResult::check('ConsumptionForecastModel produces honest INSUFFICIENT state when 0 verified bills exist', function (): void {
                $context = AuthTesting::householdFixture('fc-no-bills-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $billsRepo = new BillRepository();
                $readingsRepo = new SolarReadingRepository();

                $model = new ConsumptionForecastModel($billsRepo, $readingsRepo);
                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref);

                Assert::same('CONSUMPTION', $snapshot->forecastType());
                Assert::same(ForecastConfidence::INSUFFICIENT, $snapshot->confidence());
                Assert::same(null, $snapshot->predictedValue());
                Assert::same(null, $snapshot->uncertaintyRange()['lower']);
                Assert::same(null, $snapshot->uncertaintyRange()['upper']);
                $limitationText = implode(' ', $snapshot->limitations());
                Assert::true(str_contains($limitationText, 'Insufficient historical consumption data'), 'Notes insufficient data');
                Assert::true(str_contains($limitationText, 'Upload and verify your recent electricity bills'), 'Explains required next action');
            }),

            TestResult::check('0 verified bills → no fabricated consumption → no false surplus → no false future cost', function (): void {
                $context = AuthTesting::householdFixture('fc-zero-bills-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $service = new ForecastService(
                    solarReadings: new SolarReadingRepository(),
                    bills: new BillRepository(),
                    solarConnections: new SolarConnectionRepository(),
                    forecastRepository: new ForecastRepository(),
                    weather: new FakeWeatherProvider()
                );

                $result = $service->generateForecast($hhId, ForecastHorizon::DAY_AHEAD);

                // 1. No fabricated consumption
                $consumption = $result['consumption_forecast'];
                Assert::same(ForecastConfidence::INSUFFICIENT, $consumption['confidence']);
                Assert::same(null, $consumption['predicted_value']);
                Assert::same(null, $consumption['uncertainty_range']['lower']);
                Assert::same(null, $consumption['uncertainty_range']['upper']);

                // 2. No false surplus in energy balance
                $balance = $result['energy_balance_projection'];
                Assert::same('UNAVAILABLE', $balance['state']);
                Assert::same(null, $balance['balance_kwh']);
                Assert::same('UNAVAILABLE', $balance['classification']);
                Assert::same(ForecastConfidence::INSUFFICIENT, $balance['confidence']);

                // 3. No false future cost
                $cost = $result['energy_cost_projection'];
                Assert::same('INSUFFICIENT_EVIDENCE', $cost['state']);
                Assert::same(null, $cost['estimated_cost_inr']);
                Assert::same(null, $cost['cost_range']);
                Assert::same('FUTURE_ESTIMATE', $cost['cost_type']);

                // 4. Time-series balance is safe null (never solar - 0.0)
                foreach ($result['time_series'] as $point) {
                    Assert::same(null, $point['predicted_consumption_kwh']);
                    Assert::same(null, $point['projected_balance_kwh']);
                }

                // 5. Limitations explain that verified bills are needed
                $limitations = implode(' ', $result['limitations']);
                Assert::true(str_contains($limitations, 'Insufficient historical consumption data'));
            }),

            TestResult::check('ConsumptionForecastModel yields LOW confidence with wide uncertainty for single verified bill', function (): void {
                $context = AuthTesting::householdFixture('fc-one-bill-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $verifiedBill = self::verifiedBill($hhId, '2026-07-01', '2026-07-31', '360.000');

                $billsRepo = new BillRepository();
                $billsRepo->insert($verifiedBill);

                $model = new ConsumptionForecastModel($billsRepo, new SolarReadingRepository());
                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref);

                Assert::same(ForecastConfidence::LOW, $snapshot->confidence());
                Assert::same(ForecastSnapshot::NATURE_PREDICTED, $snapshot->dataNature());
                Assert::same(ForecastSnapshot::QUALITY_PARTIAL, $snapshot->dataQuality());
                Assert::true(abs($snapshot->predictedValue() - 12.0) < 0.5, 'Predicted day-ahead consumption matches daily avg');
                $limitationText = implode(' ', $snapshot->limitations());
                Assert::true(str_contains($limitationText, 'Single verified bill history'), 'Explains single-bill caveat');
            }),

            TestResult::check('ConsumptionForecastModel scales to HIGH confidence for multiple verified bills', function (): void {
                $context = AuthTesting::householdFixture('fc-multi-bills-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $billsRepo = new BillRepository();

                for ($i = 1; $i <= 5; $i++) {
                    $b = self::verifiedBill(
                        $hhId,
                        sprintf('2026-0%d-01', $i),
                        sprintf('2026-0%d-28', $i),
                        '300.000'
                    );
                    $billsRepo->insert($b);
                }

                $model = new ConsumptionForecastModel($billsRepo, new SolarReadingRepository());
                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref);

                Assert::same(ForecastConfidence::HIGH, $snapshot->confidence());
                Assert::same(ForecastSnapshot::QUALITY_GOOD, $snapshot->dataQuality());
            }),

            TestResult::check('ForecastService integrates with F4 EnergyBalance under PREDICTED/POTENTIAL semantics', function (): void {
                $context = AuthTesting::householdFixture('fc-f4-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $verifiedBill = self::verifiedBill($hhId, '2026-07-01', '2026-07-31', '300.000');

                $billsRepo = new BillRepository();
                $billsRepo->insert($verifiedBill);

                $service = new ForecastService(
                    solarReadings: new SolarReadingRepository(),
                    bills: $billsRepo,
                    solarConnections: new SolarConnectionRepository(),
                    forecastRepository: new ForecastRepository(),
                    weather: new FakeWeatherProvider()
                );

                $result = $service->generateForecast($hhId, ForecastHorizon::DAY_AHEAD);

                Assert::true(isset($result['energy_balance_projection']), 'Contains F4 projection');
                $f4 = $result['energy_balance_projection'];
                Assert::same('CLASSIFIED', $f4['state']);
                Assert::same('PREDICTED', $f4['data_nature']);
                Assert::same('POTENTIAL', $f4['measurement_context']);
                Assert::true(in_array($f4['classification'], ['POTENTIAL_SURPLUS', 'BALANCED', 'POTENTIAL_DEFICIT'], true));
            }),

            TestResult::check('ForecastService integrates with F5 EnergyCost as FUTURE_ESTIMATE with mandatory range', function (): void {
                $context = AuthTesting::householdFixture('fc-f5-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $verifiedBill = self::verifiedBill($hhId, '2026-07-01', '2026-07-31', '1200.000');

                $billsRepo = new BillRepository();
                $billsRepo->insert($verifiedBill);

                $service = new ForecastService(
                    solarReadings: new SolarReadingRepository(),
                    bills: $billsRepo,
                    solarConnections: new SolarConnectionRepository(),
                    forecastRepository: new ForecastRepository(),
                    weather: new FakeWeatherProvider()
                );

                $result = $service->generateForecast($hhId, ForecastHorizon::DAY_AHEAD);

                Assert::true(isset($result['energy_cost_projection']), 'Contains F5 projection');
                $f5 = $result['energy_cost_projection'];
                Assert::same(EnergyCostService::COST_TYPE_FUTURE_ESTIMATE, $f5['cost_type']);
                Assert::same(EnergyCostService::MEASUREMENT_CONTEXT_ESTIMATED, $f5['measurement_context']);
                Assert::same('INR', $f5['currency']);
                Assert::true($f5['cost_range'] !== null, 'Cost range is mandatory for non-ACTUAL input nature');
                Assert::true($f5['cost_range']['min_inr'] < $f5['cost_range']['max_inr']);
            }),

            TestResult::check('Forecast limitations trigger F6 DATA_LIMITATION insight candidate', function (): void {
                $detector = new DataLimitationDetector();
                $evidence = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-f6',
                    'quality' => [
                        'overall_status' => 'DEGRADED',
                    ],
                    'forecast' => [
                        [
                            'period' => ['start' => '2026-09-22T00:00:00', 'end' => '2026-09-22T23:59:59', 'resolution' => 'HOURLY'],
                            'value_kwh' => 15.0,
                            'data_nature' => 'PREDICTED',
                            'data_status' => 'DEGRADED',
                            'confidence_level' => 'LOW',
                        ],
                    ],
                ]);

                $candidates = $detector->detect($evidence);
                Assert::true(count($candidates) > 0, 'Must produce candidate for degraded forecast status');
                Assert::same(InsightTaxonomy::TYPE_DATA_LIMITATION, $candidates[0]->type);
            }),

            TestResult::check('Forecast outputs never overwrite actual telemetry and maintain clear separation', function (): void {
                $context = AuthTesting::householdFixture('fc-sep-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $readingsRepo = new SolarReadingRepository();
                $countBefore = $readingsRepo->countByHouseholdId($hhId);

                $fakeWeather = new FakeWeatherProvider();
                $model = new SolarForecastModel($readingsRepo, $fakeWeather);
                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref);

                $countAfter = $readingsRepo->countByHouseholdId($hhId);

                Assert::same(ForecastSnapshot::NATURE_ESTIMATED, $snapshot->dataNature());
                Assert::same($countBefore, $countAfter, 'Forecast generation must NEVER insert or alter solar readings');

                // Clean up any test bills created during ForecastDomainTest
                $pdo = Database::connection();
                $cleanStmt = $pdo->prepare('DELETE FROM bills WHERE id = :id');
                foreach (self::$createdBills as $cleanId) {
                    $cleanStmt->execute(['id' => $cleanId]);
                }
                self::$createdBills = [];
            }),

            TestResult::check('SolarForecastModel parses inverter capacity from GoodWe serial numbers and models', function (): void {
                Assert::same(3000.0, SolarForecastModel::parseCapacityFromInverterSn('53000N3A258L5685'));
                Assert::same(5000.0, SolarForecastModel::parseCapacityFromInverterSn('55000N1234567890'));
                Assert::same(4200.0, SolarForecastModel::parseCapacityFromInverterSn('54200N0000000000'));
                Assert::same(3600.0, SolarForecastModel::parseCapacityFromInverterSn('53600N0000000000'));
                Assert::same(6000.0, SolarForecastModel::parseCapacityFromInverterSn('56000N0000000000'));
                Assert::same(3000.0, SolarForecastModel::parseCapacityFromInverterSn('GW3000-DNS'));
                Assert::same(5000.0, SolarForecastModel::parseCapacityFromInverterSn('GW5000-DNS'));
                Assert::same(10000.0, SolarForecastModel::parseCapacityFromInverterSn('GW10K-ET'));
                Assert::same(3000.0, SolarForecastModel::parseCapacityFromInverterSn('GW3000'));
                Assert::same(null, SolarForecastModel::parseCapacityFromInverterSn('unknown_sn_123'));
                Assert::same(null, SolarForecastModel::parseCapacityFromInverterSn(''));
                Assert::same(null, SolarForecastModel::parseCapacityFromInverterSn(null));
            }),

            TestResult::check('SolarForecastModel resolves peak capacity through authoritative priority ladder', function (): void {
                $context = AuthTesting::householdFixture('fc-cap-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];
                $readingsRepo = new SolarReadingRepository();
                $billsRepo = new BillRepository();
                $fakeWeather = new FakeWeatherProvider();
                $model = new SolarForecastModel($readingsRepo, $fakeWeather, null, null, $billsRepo);

                // Priority 1: Inverter SN present
                [$cap1, $src1] = $model->resolveInverterPeakCapacityW($hhId, ['inverter_sn' => '53000N3A258L5685'], null);
                Assert::same(3000.0, $cap1);
                Assert::same('inverter_sn', $src1);

                // Priority 2: Inverter SN unknown, historical telemetry peak power present
                $batchId = self::uuid();
                $pdo = Database::connection();
                $insertStmt = $pdo->prepare(<<<'SQL'
                    INSERT INTO solar_readings (
                        id, household_id, station_id, inverter_sn,
                        measurement_time, received_at, power_w,
                        daily_generation_kwh, inverter_status, data_nature,
                        data_status, data_quality, sync_batch_id, created_at
                    ) VALUES (
                        :id, :household_id, 'st-test', 'UNKNOWN_MODEL',
                        '2026-09-22 12:00:00', '2026-09-22 12:00:00', '2400.00',
                        '12.000', 'NORMAL', 'ACTUAL', 'FRESH', 'GOOD', :batch_id, '2026-09-22 12:00:00'
                    )
                SQL);
                $insertStmt->execute([
                    'id' => self::uuid(),
                    'household_id' => $hhId,
                    'batch_id' => $batchId,
                ]);

                [$cap2, $src2] = $model->resolveInverterPeakCapacityW($hhId, null, null);
                Assert::same(3000.0, $cap2); // 2400 * 1.25 = 3000
                Assert::same('historical_telemetry', $src2);

                // Priority 3: Clean new household, no readings, verified bill has contracted_load = 3.00 kW
                $context2 = AuthTesting::householdFixture('fc-cap2-' . bin2hex(random_bytes(4)));
                $hhId2 = $context2['household_id'];
                $bill = Bill::fromStorageRow([
                    'id' => self::uuid(),
                    'household_id' => $hhId2,
                    'status' => BillStatus::VERIFIED,
                    'billing_period_start' => '2026-08-01',
                    'billing_period_end' => '2026-09-01',
                    'contracted_load' => '3.00',
                    'consumption_kwh' => '150.000',
                    'verified_at' => '2026-09-02 10:00:00',
                    'created_at' => '2026-09-02 10:00:00',
                    'updated_at' => '2026-09-02 10:00:00',
                ]);
                $billsRepo->insert($bill);
                self::$createdBills[] = $bill->id();

                [$cap3, $src3] = $model->resolveInverterPeakCapacityW($hhId2, null, null);
                Assert::same(3000.0, $cap3); // 3.00 kW * 1000 = 3000 W
                Assert::same('verified_bill_contracted_load', $src3);

                // Priority 4: No connection, no telemetry, no bill -> baseline 5000 W
                $context3 = AuthTesting::householdFixture('fc-cap3-' . bin2hex(random_bytes(4)));
                $hhId3 = $context3['household_id'];
                [$cap4, $src4] = $model->resolveInverterPeakCapacityW($hhId3, null, null);
                Assert::same(5000.0, $cap4);
                Assert::same('default_baseline', $src4);

                // Clean up inserted test bill
                $pdo->prepare('DELETE FROM bills WHERE id = :id')->execute(['id' => $bill->id()]);
            }),

            TestResult::check('SolarForecastModel generates correctly scaled forecast for 3 kW inverter', function (): void {
                $context = AuthTesting::householdFixture('fc-3kw-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];
                $readingsRepo = new SolarReadingRepository();
                $fakeWeather = new FakeWeatherProvider();
                $model = new SolarForecastModel($readingsRepo, $fakeWeather);

                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref, [
                    'connection_status' => 'CONNECTED',
                    'inverter_sn' => '53000N3A258L5685', // 3 kW GoodWe inverter
                ]);

                Assert::same(3000.0, $snapshot->provenance()['peak_capacity_w']);
                Assert::same('inverter_sn', $snapshot->provenance()['capacity_source']);

                // Verify peak hourly power is bounded by 3 kW inverter rating
                foreach ($snapshot->timeSeriesPoints() as $pt) {
                    Assert::true(
                        $pt['predicted_w'] <= 3000.0,
                        sprintf('Predicted power %f W exceeds 3 kW physical inverter capacity', $pt['predicted_w'])
                    );
                }
            }),
        ];
    }
}
