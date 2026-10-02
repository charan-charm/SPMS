<?php

declare(strict_types=1);

namespace App\Domain\Forecast;

use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Forecast\ML\MLForecastService;
use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use App\Integrations\Weather\FakeWeatherProvider;
use App\Integrations\Weather\OpenMeteoGeocodingClient;
use App\Integrations\Weather\OpenMeteoWeatherProvider;
use App\Domain\Bill\BillStatus;
use App\Repositories\BillRepository;
use App\Repositories\ForecastRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F3 — Forecasting Domain Service (docs/01 §13-15, docs/02 §2, docs/04 §16, docs/10 D-040, D-042).
 *
 * Primary coordinator for backend-authoritative solar and household electricity forecasting:
 * - Generates solar generation forecasts via ML/Deterministic model ladder.
 * - Generates consumption forecasts via ML/Deterministic model ladder.
 * - Produces aligned time-series projections.
 * - Evaluates projected energy balance via F4 (EnergyBalanceService) under PREDICTED/POTENTIAL semantics.
 * - Evaluates projected cost impact via F5 (EnergyCostService) with mandatory range factors.
 * - Aggregates data limitations for F6 insight generation.
 * - Caches and persists forecast snapshots via ForecastRepository.
 */
final class ForecastService
{
    private SolarForecastModel $solarModel;
    private ConsumptionForecastModel $consumptionModel;
    private MLForecastService $mlService;
    private EnergyBalanceService $energyBalanceService;
    private EnergyCostService $energyCostService;
    private ForecastRepository $forecastRepository;
    private SolarConnectionRepository $solarConnections;
    private SolarReadingRepository $solarReadings;
    private BillRepository $bills;
    private WeatherProviderInterface $weather;
    private HouseholdRepository $households;
    private OpenMeteoGeocodingClient $geocoding;

    public function __construct(
        ?SolarReadingRepository $solarReadings = null,
        ?BillRepository $bills = null,
        ?SolarConnectionRepository $solarConnections = null,
        ?ForecastRepository $forecastRepository = null,
        ?WeatherProviderInterface $weather = null,
        ?EnergyBalanceService $energyBalanceService = null,
        ?EnergyCostService $energyCostService = null,
        ?HouseholdRepository $households = null,
        ?OpenMeteoGeocodingClient $geocoding = null,
        ?MLForecastService $mlService = null
    ) {
        $this->solarReadings = $solarReadings ?? new SolarReadingRepository();
        $this->bills = $bills ?? new BillRepository();
        $this->solarConnections = $solarConnections ?? new SolarConnectionRepository();
        $this->forecastRepository = $forecastRepository ?? new ForecastRepository();
        $this->energyBalanceService = $energyBalanceService ?? new EnergyBalanceService();
        $this->energyCostService = $energyCostService ?? new EnergyCostService();
        $this->households = $households ?? new HouseholdRepository();
        $this->geocoding = $geocoding ?? new OpenMeteoGeocodingClient();

        if ($weather !== null) {
            $this->weather = $weather;
        } else {
            $weatherDriver = strtolower(trim((string) Config::get('WEATHER_PROVIDER', 'openmeteo')));
            $isDemo = Config::get('SPMS_DATA_ENVIRONMENT') === 'demo';

            if ($weatherDriver === 'fake' || $isDemo) {
                $this->weather = new FakeWeatherProvider();
            } else {
                $this->weather = new OpenMeteoWeatherProvider();
            }
        }

        $this->solarModel = new SolarForecastModel(
            $this->solarReadings,
            $this->weather,
            $this->households,
            $this->geocoding,
            $this->bills
        );
        $this->consumptionModel = new ConsumptionForecastModel($this->bills, $this->solarReadings);
        $this->mlService = $mlService ?? new MLForecastService(
            $this->solarReadings,
            $this->weather,
            $this->bills,
            $this->households,
            $this->geocoding
        );
    }

    /**
     * Get or generate a forecast for the given household and horizon.
     *
     * @param string $householdId
     * @param string $horizon 'DAY_AHEAD' | 'SEVEN_DAY' | 'MONTHLY'
     * @param DateTimeImmutable|null $referenceTime
     * @param bool $forceFresh
     * @return array<string, mixed>
     */
    public function getForecast(
        string $householdId,
        string $horizon = ForecastHorizon::DAY_AHEAD,
        ?DateTimeImmutable $referenceTime = null,
        bool $forceFresh = false
    ): array {
        $validHorizon = ForecastHorizon::validate($horizon);
        $refTime = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // If not forcing fresh, check persistence cache
        if (!$forceFresh) {
            $cached = $this->forecastRepository->findLatest($householdId, 'COMBINED', $validHorizon);
            if ($cached !== null) {
                // If cached less than 30 minutes ago, return cached payload
                $createdAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $cached['created_at']);
                if ($createdAt !== false && ($refTime->getTimestamp() - $createdAt->getTimestamp()) < 1800) {
                    $payload = json_decode((string) $cached['points_json'], true);
                    if (is_array($payload)) {
                        return $payload;
                    }
                }
            }
        }

        // Generate fresh forecast
        return $this->generateForecast($householdId, $validHorizon, $refTime);
    }

    /**
     * Generate fresh combined forecast and persist snapshot.
     *
     * @return array<string, mixed>
     */
    public function generateForecast(
        string $householdId,
        string $horizon = ForecastHorizon::DAY_AHEAD,
        ?DateTimeImmutable $referenceTime = null
    ): array {
        $validHorizon = ForecastHorizon::validate($horizon);
        $refTime = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $solarConn = $this->solarConnections->findByHouseholdId($householdId);

        // 1. Generate Solar Forecast via ML Service (with automatic baseline comparison & fallback)
        $solarResult = $this->mlService->generateSolarForecast(
            $householdId,
            $validHorizon,
            $refTime,
            $solarConn,
            $this->solarModel
        );
        $solarSnapshot = $solarResult['snapshot'];

        // 2. Generate Consumption Forecast via ML Service (with automatic baseline comparison & fallback)
        $consumptionResult = $this->mlService->generateConsumptionForecast(
            $householdId,
            $validHorizon,
            $refTime,
            $this->consumptionModel
        );
        $consumptionSnapshot = $consumptionResult['snapshot'];

        // 3. F4 Integration: Evaluate Projected Energy Balance (F4 remains sole authority for balance classification)
        $energyBalance = $this->evaluateEnergyBalance($solarSnapshot, $consumptionSnapshot, $householdId);

        // 4. F5 Integration: Evaluate Projected Energy Cost
        $energyCost = $this->evaluateEnergyCost($energyBalance, $solarSnapshot, $consumptionSnapshot, $householdId);

        // 5. Merge Time-Series Projections
        $mergedTimeSeries = $this->mergeTimeSeries(
            $solarSnapshot->timeSeriesPoints(),
            $consumptionSnapshot->timeSeriesPoints()
        );

        // 6. Aggregate All Limitations including structured fallback reasons
        $fallbackLimitations = [];
        if ($solarResult['fallback_reason'] !== null) {
            $fallbackLimitations[] = 'Solar forecasting: ' . $solarResult['fallback_reason'];
        }
        if ($consumptionResult['fallback_reason'] !== null && $consumptionResult['status'] !== 'FORECAST_UNAVAILABLE') {
            $fallbackLimitations[] = 'Consumption forecasting: ' . $consumptionResult['fallback_reason'];
        }

        $allLimitations = array_values(array_unique(array_merge(
            $solarSnapshot->limitations(),
            $consumptionSnapshot->limitations(),
            $fallbackLimitations
        )));

        // 7. Combined Confidence (worst-of rule)
        $combinedConfidence = ForecastConfidence::worstOf(
            $solarSnapshot->confidence(),
            $consumptionSnapshot->confidence()
        );

        // 8. Overall forecast source resolution
        $solarSource = $solarResult['forecast_source'];
        $consumptionSource = $consumptionResult['forecast_source'];
        $forecastSource = ($solarSource === 'ML' && $consumptionSource === 'ML')
            ? 'ML'
            : (($solarSource === 'ML' || $consumptionSource === 'ML') ? 'HYBRID_ML' : 'DETERMINISTIC');

        $household = $this->households->findById($householdId);
        $householdTz = $household?->timezone() ?? 'Asia/Kolkata';

        $payload = [
            'household_id' => $householdId,
            'horizon' => $validHorizon,
            'period' => $solarSnapshot->period(),
            'forecast_source' => $forecastSource,
            'combined_confidence' => $combinedConfidence,
            'solar_forecast' => $solarSnapshot->toArray(),
            'consumption_forecast' => $consumptionSnapshot->toArray(),
            'energy_balance_projection' => $energyBalance,
            'energy_cost_projection' => $energyCost,
            'time_series' => $mergedTimeSeries,
            'limitations' => $allLimitations,
            'weather' => $solarSnapshot->provenance()['weather'] ?? null,
            'ml_provenance' => [
                'forecast_source' => $forecastSource,
                'solar' => [
                    'source' => $solarSource,
                    'status' => $solarResult['status'],
                    'fallback_reason' => $solarResult['fallback_reason'],
                    'model' => $solarSnapshot->provenance()['model_name'] ?? $solarSnapshot->provenance()['model_version'] ?? 'DETERMINISTIC',
                    'mae' => $solarResult['evaluation']['mae'] ?? null,
                    'rmse' => $solarResult['evaluation']['rmse'] ?? null,
                    'baseline_mae' => $solarResult['evaluation']['baseline_mae'] ?? null,
                ],
                'consumption' => [
                    'source' => $consumptionSource,
                    'status' => $consumptionResult['status'],
                    'fallback_reason' => $consumptionResult['fallback_reason'],
                    'model' => $consumptionSnapshot->provenance()['model_name'] ?? $consumptionSnapshot->provenance()['model_version'] ?? 'DETERMINISTIC',
                    'mae' => $consumptionResult['evaluation']['mae'] ?? null,
                    'rmse' => $consumptionResult['evaluation']['rmse'] ?? null,
                    'baseline_mae' => $consumptionResult['evaluation']['baseline_mae'] ?? null,
                ],
            ],
            'data_nature' => ($solarSnapshot->dataNature() === ForecastSnapshot::NATURE_SIMULATED || $consumptionSnapshot->dataNature() === ForecastSnapshot::NATURE_SIMULATED)
                ? ForecastSnapshot::NATURE_SIMULATED
                : ForecastSnapshot::NATURE_PREDICTED,
            'reference_time' => $refTime->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $householdTz,
            'generated_at' => $refTime->format('Y-m-d\TH:i:s\Z'),
        ];

        // 9. Persist to repository
        $this->forecastRepository->save([
            'id' => self::uuid(),
            'household_id' => $householdId,
            'forecast_type' => 'COMBINED',
            'horizon' => $validHorizon,
            'forecast_start' => $solarSnapshot->period()['start'],
            'forecast_end' => $solarSnapshot->period()['end'],
            'resolution' => $solarSnapshot->period()['resolution'],
            'predicted_value' => $solarSnapshot->predictedValue(),
            'uncertainty_lower' => $solarSnapshot->uncertaintyRange()['lower'],
            'uncertainty_upper' => $solarSnapshot->uncertaintyRange()['upper'],
            'confidence' => $combinedConfidence,
            'data_nature' => ($solarSnapshot->dataNature() === ForecastSnapshot::NATURE_SIMULATED || $consumptionSnapshot->dataNature() === ForecastSnapshot::NATURE_SIMULATED)
                ? ForecastSnapshot::NATURE_SIMULATED
                : ForecastSnapshot::NATURE_PREDICTED,
            'data_quality' => $solarSnapshot->dataQuality(),
            'points_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'limitations_json' => json_encode($allLimitations, JSON_THROW_ON_ERROR),
            'provenance_json' => json_encode([
                'forecast_source' => $forecastSource,
                'solar' => $solarSnapshot->provenance(),
                'consumption' => $consumptionSnapshot->provenance(),
                'solar_status' => $solarResult['status'],
                'consumption_status' => $consumptionResult['status'],
                'solar_fallback_reason' => $solarResult['fallback_reason'],
                'consumption_fallback_reason' => $consumptionResult['fallback_reason'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $refTime->format('Y-m-d H:i:s'),
        ]);

        return $payload;
    }

    /**
     * Feed forecast outputs into the existing Energy Balance (F4) architecture.
     * Preserves D-040 tolerance semantics; result has data_nature: 'PREDICTED', measurement_context: 'POTENTIAL'.
     *
     * @return array<string, mixed>
     */
    private function evaluateEnergyBalance(
        ForecastSnapshot $solar,
        ForecastSnapshot $consumption,
        string $householdId
    ): array {
        // If consumption is INSUFFICIENT or null, energy balance cannot be computed accurately
        if ($consumption->confidence() === ForecastConfidence::INSUFFICIENT || $consumption->predictedValue() === null) {
            return [
                'state' => 'UNAVAILABLE',
                'balance_kwh' => null,
                'classification' => 'UNAVAILABLE',
                'data_nature' => ($solar->dataNature() === ForecastSnapshot::NATURE_SIMULATED || $consumption->dataNature() === ForecastSnapshot::NATURE_SIMULATED)
                    ? ForecastSnapshot::NATURE_SIMULATED
                    : ForecastSnapshot::NATURE_PREDICTED,
                'measurement_context' => EnergyBalanceService::MEASUREMENT_CONTEXT_POTENTIAL,
                'confidence' => ForecastConfidence::INSUFFICIENT,
                'explanation' => 'Energy balance projection is unavailable because household consumption history is insufficient.',
            ];
        }

        $inputs = [
            'solar' => [
                'value_kwh' => $solar->predictedValue(),
                'nature' => $solar->dataNature(),
                'status' => 'FRESH',
                'quality' => $solar->dataQuality(),
                'confidence_level' => $solar->confidence(),
            ],
            'consumption' => [
                'value_kwh' => $consumption->predictedValue(),
                'nature' => $consumption->dataNature(),
                'status' => 'FRESH',
                'quality' => $consumption->dataQuality(),
                'confidence_level' => $consumption->confidence(),
            ],
        ];

        $classified = $this->energyBalanceService->classify(
            $inputs,
            EnergyBalanceService::ANALYSIS_AGGREGATE,
            $solar->period(),
            $householdId
        );

        $res = $classified['result'];

        return [
            'state' => $res['state'],
            'balance_kwh' => $res['balance_kwh'],
            'classification' => $res['classification'],
            'explanation' => $res['explanation'],
            'data_nature' => $res['data_nature'], // PREDICTED
            'measurement_context' => $res['measurement_context'], // POTENTIAL
            'confidence' => $res['confidence_level'],
        ];
    }

    /**
     * Feed projected balance into Energy Cost (F5) architecture.
     * Future cost must remain explicitly ESTIMATED with mandatory range factor.
     *
     * @return array<string, mixed>
     */
    private function evaluateEnergyCost(
        array $energyBalance,
        ForecastSnapshot $solar,
        ForecastSnapshot $consumption,
        string $householdId
    ): array {
        if (
            ($energyBalance['state'] ?? '') !== 'CLASSIFIED'
            || $consumption->confidence() === ForecastConfidence::INSUFFICIENT
            || $consumption->predictedValue() === null
        ) {
            return [
                'state' => EnergyCostService::RESULT_STATE_INSUFFICIENT_EVIDENCE,
                'estimated_cost_inr' => null,
                'cost_range' => null,
                'pricing_basis' => EnergyCostService::BASIS_INSUFFICIENT_EVIDENCE,
                'pricing_source' => null,
                'fixed_charge' => null,
                'cost_type' => EnergyCostService::COST_TYPE_FUTURE_ESTIMATE,
                'currency' => EnergyCostService::CURRENCY_INR,
                'measurement_context' => EnergyCostService::MEASUREMENT_CONTEXT_ESTIMATED,
                'explanation' => 'Future cost projection requires verified consumption history and energy balance.',
            ];
        }

        $pricing = $this->resolvePricingEvidence($householdId);
        $fixedCharge = $this->resolveFixedChargeEvidence($householdId);

        $inputs = [
            'solar' => [
                'value_kwh' => $solar->predictedValue(),
                'nature' => $solar->dataNature(),
                'status' => 'FRESH',
                'quality' => $solar->dataQuality(),
                'confidence_level' => $solar->confidence(),
            ],
            'consumption' => [
                'value_kwh' => $consumption->predictedValue(),
                'nature' => $consumption->dataNature(),
                'status' => 'FRESH',
                'quality' => $consumption->dataQuality(),
                'confidence_level' => $consumption->confidence(),
            ],
            'pricing' => $pricing,
        ];

        if ($fixedCharge !== null) {
            $inputs['fixed_charge'] = $fixedCharge;
        }

        $costData = $this->energyCostService->estimate($inputs, $solar->period(), $householdId);
        $res = $costData['result'];

        return [
            'state' => $res['state'],
            'estimated_cost_inr' => $res['estimated_variable_cost'],
            'cost_range' => isset($res['range']) && $res['range'] !== null ? [
                'min_inr' => $res['range']['low'],
                'max_inr' => $res['range']['high'],
                'half_width_pct' => $res['range']['half_width_percent'],
                'triggers' => $res['range']['triggers'],
            ] : null,
            'pricing_basis' => $res['pricing_basis'] ?? null,
            'pricing_source' => $res['pricing_source'] ?? null,
            'fixed_charge' => $res['fixed_charge'] ?? null,
            'cost_type' => $res['cost_type'],
            'currency' => $res['currency'],
            'measurement_context' => $res['measurement_context'],
            'explanation' => $res['explanation'],
        ];
    }

    /**
     * Resolve pricing evidence according to the F5 ladder:
     * 1. Verified tariff schedule (none persisted yet in v1)
     * 2. Verified historical bill relationship (if verified bill with positive energy rate exists)
     * 3. Declared/standard rate (USER_PROVIDED_RATE fallback)
     *
     * @return array{basis: string, unit_rate: float, currency: string, source: string}
     */
    private function resolvePricingEvidence(string $householdId): array
    {
        try {
            $bills = $this->bills->listForHousehold($householdId);
            foreach ($bills as $bill) {
                if ($bill->status() !== BillStatus::VERIFIED) {
                    continue;
                }
                $ec = (float) ($bill->value('energy_charges') ?? 0);
                $units = (float) ($bill->value('net_units_kwh') ?? $bill->value('consumption_kwh') ?? 0);
                if ($ec > 0.0 && $units > 0.0) {
                    return [
                        'basis' => EnergyCostService::BASIS_HISTORICAL_BILL_RELATIONSHIP,
                        'unit_rate' => round($ec / $units, 4),
                        'currency' => EnergyCostService::CURRENCY_INR,
                        'source' => 'verified_bill_history',
                    ];
                }
            }
        } catch (\Throwable) {
            // Degrade gracefully to declared rate
        }

        return [
            'basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE,
            'unit_rate' => 7.50,
            'currency' => EnergyCostService::CURRENCY_INR,
            'source' => 'declared_standard_rate',
        ];
    }

    /**
     * Resolve separate fixed charge evidence from latest verified/recorded bill.
     * Fixed charges are kept strictly separate from variable energy cost.
     *
     * @return array{amount: float, currency: string, label: string}|null
     */
    private function resolveFixedChargeEvidence(string $householdId): ?array
    {
        try {
            $bills = $this->bills->listForHousehold($householdId);
            foreach ($bills as $bill) {
                $fc = (float) ($bill->value('fixed_charges') ?? 0);
                if ($fc > 0.0) {
                    return [
                        'amount' => $fc,
                        'currency' => EnergyCostService::CURRENCY_INR,
                        'label' => 'Monthly fixed charge from electricity bill',
                    ];
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        return null;
    }

    /**
     * Merge solar points and consumption points into a synchronized time series.
     *
     * @param list<array<string, mixed>> $solarPoints
     * @param list<array<string, mixed>> $consumptionPoints
     * @return list<array<string, mixed>>
     */
    private function mergeTimeSeries(array $solarPoints, array $consumptionPoints): array
    {
        $merged = [];
        $byTimestamp = [];

        foreach ($solarPoints as $sp) {
            $ts = $sp['timestamp'];
            $byTimestamp[$ts] = [
                'timestamp' => $ts,
                'hour' => $sp['hour'] ?? null,
                'date' => $sp['date'] ?? null,
                'predicted_solar_kwh' => $sp['predicted_kwh'],
                'lower_solar_kwh' => $sp['lower_kwh'],
                'upper_solar_kwh' => $sp['upper_kwh'],
                'predicted_solar_w' => $sp['predicted_w'] ?? null,
                'lower_solar_w' => $sp['lower_w'] ?? null,
                'upper_solar_w' => $sp['upper_w'] ?? null,
                'predicted_consumption_kwh' => null,
                'lower_consumption_kwh' => null,
                'upper_consumption_kwh' => null,
                'predicted_consumption_w' => null,
                'lower_consumption_w' => null,
                'upper_consumption_w' => null,
                'projected_balance_kwh' => null,
                'solar_data_nature' => $sp['data_nature'],
                'consumption_data_nature' => null,
            ];
        }

        foreach ($consumptionPoints as $cp) {
            $ts = $cp['timestamp'];
            if (!isset($byTimestamp[$ts])) {
                $byTimestamp[$ts] = [
                    'timestamp' => $ts,
                    'hour' => $cp['hour'] ?? null,
                    'date' => $cp['date'] ?? null,
                    'predicted_solar_kwh' => null,
                    'lower_solar_kwh' => null,
                    'upper_solar_kwh' => null,
                    'predicted_solar_w' => null,
                    'lower_solar_w' => null,
                    'upper_solar_w' => null,
                    'predicted_consumption_kwh' => null,
                    'lower_consumption_kwh' => null,
                    'upper_consumption_kwh' => null,
                    'predicted_consumption_w' => null,
                    'lower_consumption_w' => null,
                    'upper_consumption_w' => null,
                    'projected_balance_kwh' => null,
                    'solar_data_nature' => null,
                    'consumption_data_nature' => null,
                ];
            }

            $byTimestamp[$ts]['predicted_consumption_kwh'] = $cp['predicted_kwh'];
            $byTimestamp[$ts]['lower_consumption_kwh'] = $cp['lower_kwh'];
            $byTimestamp[$ts]['upper_consumption_kwh'] = $cp['upper_kwh'];
            $byTimestamp[$ts]['predicted_consumption_w'] = $cp['predicted_w'] ?? null;
            $byTimestamp[$ts]['lower_consumption_w'] = $cp['lower_w'] ?? null;
            $byTimestamp[$ts]['upper_consumption_w'] = $cp['upper_w'] ?? null;
            $byTimestamp[$ts]['consumption_data_nature'] = $cp['data_nature'];

            if ($byTimestamp[$ts]['predicted_solar_kwh'] !== null && ($cp['predicted_kwh'] ?? null) !== null) {
                $byTimestamp[$ts]['projected_balance_kwh'] = round(
                    $byTimestamp[$ts]['predicted_solar_kwh'] - $cp['predicted_kwh'],
                    3
                );
            }
        }

        ksort($byTimestamp);

        return array_values($byTimestamp);
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }
}
