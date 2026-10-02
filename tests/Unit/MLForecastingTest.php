<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillStatus;
use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Forecast\ConsumptionForecastModel;
use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use App\Domain\Forecast\ForecastConfidence;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Forecast\ForecastSnapshot;
use App\Domain\Forecast\ML\ForecastEvaluator;
use App\Domain\Forecast\ML\ForecastFeatureBuilder;
use App\Domain\Forecast\ML\ForecastModelRegistry;
use App\Domain\Forecast\ML\LinearRegressionSolver;
use App\Domain\Forecast\ML\MLConsumptionForecastModel;
use App\Domain\Forecast\ML\MLForecastService;
use App\Domain\Forecast\ML\MLSolarForecastModel;
use App\Domain\Forecast\SolarForecastModel;
use App\Domain\Intelligence\Detectors\DataLimitationDetector;
use App\Domain\Intelligence\InsightConfidence;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\SignificanceModel;
use App\Domain\Intelligence\ConfidenceModel;
use App\Domain\Intelligence\InsightEngine;
use App\Domain\Recommendation\RecommendationEngine;
use App\Integrations\Weather\FakeWeatherProvider;
use App\Repositories\BillRepository;
use App\Repositories\ForecastRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class MLForecastingTest
{
    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    public static function run(): array
    {
        return [
            // 1. Feature generation
            TestResult::check('ML: FeatureBuilder generates diurnal and weather features without leakage', function (): void {
                $builder = new ForecastFeatureBuilder();

                $readings = [
                    ['measurement_time' => '2026-06-01 07:00:00', 'power_w' => 500.0, 'temperature_c' => 28.0],
                    ['measurement_time' => '2026-06-01 12:00:00', 'power_w' => 3800.0, 'temperature_c' => 35.0],
                    ['measurement_time' => '2026-06-01 17:00:00', 'power_w' => 600.0, 'temperature_c' => 31.0],
                    ['measurement_time' => '2026-06-01 22:00:00', 'power_w' => 0.0, 'temperature_c' => 26.0], // Nighttime -> filtered out
                ];

                $dataset = $builder->buildSolarDataset($readings, 4000.0, [
                    '2026-06-01T12:00:00Z' => [
                        'clear_sky_index' => 0.95,
                        'direct_normal_irradiance_wm2' => 850.0,
                        'temperature_c' => 35.0,
                    ],
                ]);

                // Nighttime reading (22:00) must be excluded from daylight regression
                Assert::same(3, count($dataset));
                Assert::same(7, $dataset[0]['hour']);
                Assert::same(12, $dataset[1]['hour']);
                Assert::same(17, $dataset[2]['hour']);

                // Diurnal sine peak at solar noon (hour 12)
                $noonFeatures = $dataset[1]['features'];
                Assert::true($noonFeatures[1] > 0.9, 'Noon diurnal sine must be near 1.0');
                Assert::same(3800.0, $dataset[1]['target']);
            }),

            // 2. Chronological splitting & No future leakage
            TestResult::check('ML: Chronological splitting strictly preserves temporal order with zero future leakage', function (): void {
                $evaluator = new ForecastEvaluator();

                $timeSeries = [];
                for ($day = 1; $day <= 10; $day++) {
                    $timeSeries[] = [
                        'timestamp' => sprintf('2026-06-%02d 12:00:00', $day),
                        'features' => [1.0, (float) $day],
                        'target' => $day * 100.0,
                    ];
                }

                $split = $evaluator->splitChronological($timeSeries, 0.70);
                $train = $split['train'];
                $test = $split['test'];

                Assert::same(7, count($train));
                Assert::same(3, count($test));

                // Earliest observation must be in train, latest must be in test
                Assert::same('2026-06-01 12:00:00', $train[0]['timestamp']);
                Assert::same('2026-06-07 12:00:00', $train[6]['timestamp']);
                Assert::same('2026-06-08 12:00:00', $test[0]['timestamp']);
                Assert::same('2026-06-10 12:00:00', $test[2]['timestamp']);

                // Train timestamps must all precede test timestamps (no leakage)
                foreach ($train as $trainSample) {
                    foreach ($test as $testSample) {
                        Assert::true(
                            strcmp($trainSample['timestamp'], $testSample['timestamp']) < 0,
                            'Training observation must strictly precede test observation'
                        );
                    }
                }
            }),

            // 3. Insufficient solar data fallback
            TestResult::check('ML: Insufficient solar telemetry falls back cleanly to deterministic model', function (): void {
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];

                $weather = new FakeWeatherProvider();
                $mlService = new MLForecastService(
                    readings: new SolarReadingRepository(),
                    weather: $weather,
                    bills: new BillRepository()
                );

                $baselineModel = new SolarForecastModel(
                    new SolarReadingRepository(),
                    $weather
                );

                // No readings inserted -> count is 0 (< 24 required)
                $result = $mlService->generateSolarForecast(
                    $hhId,
                    ForecastHorizon::DAY_AHEAD,
                    new DateTimeImmutable('2026-06-15 10:00:00', new DateTimeZone('UTC')),
                    null,
                    $baselineModel
                );

                Assert::same('ML_INSUFFICIENT_DATA', $result['status']);
                Assert::same('DETERMINISTIC', $result['forecast_source']);
                Assert::true($result['fallback_reason'] !== null);
                Assert::contains('Insufficient solar telemetry', $result['fallback_reason']);
                Assert::true($result['snapshot'] instanceof ForecastSnapshot);
            }),

            // 4. Insufficient consumption data honesty (0 bills -> null; < 3 bills -> fallback)
            TestResult::check('ML: Insufficient consumption history never fabricates values and falls back honestly', function (): void {
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];

                $weather = new FakeWeatherProvider();
                $mlService = new MLForecastService(
                    readings: new SolarReadingRepository(),
                    weather: $weather,
                    bills: new BillRepository()
                );

                $baselineModel = new ConsumptionForecastModel(
                    new BillRepository(),
                    new SolarReadingRepository()
                );

                // 0 verified bills -> FORECAST_UNAVAILABLE, predicted_value = null
                $result = $mlService->generateConsumptionForecast(
                    $hhId,
                    ForecastHorizon::DAY_AHEAD,
                    new DateTimeImmutable('2026-06-15 10:00:00', new DateTimeZone('UTC')),
                    $baselineModel
                );

                Assert::same('FORECAST_UNAVAILABLE', $result['status']);
                Assert::same('DETERMINISTIC', $result['forecast_source']);
                Assert::same(null, $result['snapshot']->predictedValue());
                Assert::same(ForecastConfidence::INSUFFICIENT, $result['snapshot']->confidence());
            }),

            // 5. Physical constraints: Nighttime solar = 0.0 W, clamped to inverter capacity, no negatives
            TestResult::check('ML: Enforces nighttime 0W, capacity clamp, and non-negative generation', function (): void {
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];
                $db = Database::connection();

                // Insert 30 daylight readings spanning 3 days to satisfy sample threshold
                $stmt = $db->prepare(<<<'SQL'
                    INSERT INTO solar_readings (
                        id, household_id, station_id, inverter_sn,
                        measurement_time, received_at, power_w,
                        daily_generation_kwh, total_generation_kwh,
                        data_nature, data_status, data_quality,
                        sync_batch_id, created_at
                    ) VALUES (
                        :id, :household_id, 'station-ml', 'GW3000-DNS',
                        :mtime, :rtime, :power,
                        5.0, 100.0,
                        'ACTUAL', 'FRESH', 'GOOD',
                        'batch-1', NOW()
                    )
                SQL);

                for ($d = 1; $d <= 3; $d++) {
                    for ($h = 7; $h <= 17; $h++) {
                        $mtime = sprintf('2026-06-%02d %02d:00:00', $d, $h);
                        $diurnalSin = sin((($h - 6) / 12.0) * M_PI);
                        $simPower = round(2800.0 * $diurnalSin, 1);

                        $stmt->execute([
                            'id' => self::uuid(),
                            'household_id' => $hhId,
                            'mtime' => $mtime,
                            'rtime' => $mtime,
                            'power' => $simPower,
                        ]);
                    }
                }

                $weather = new FakeWeatherProvider();
                $solarModel = new MLSolarForecastModel(
                    readings: new SolarReadingRepository(),
                    weather: $weather
                );

                $baselineModel = new SolarForecastModel(
                    new SolarReadingRepository(),
                    $weather
                );

                $result = $solarModel->generate(
                    $hhId,
                    ForecastHorizon::DAY_AHEAD,
                    new DateTimeImmutable('2026-06-04 05:00:00', new DateTimeZone('UTC')),
                    ['inverter_sn' => 'GW3000-DNS'],
                    $baselineModel
                );

                $snapshot = $result['snapshot'];
                $points = $snapshot->timeSeriesPoints();

                foreach ($points as $point) {
                    $hour = $point['hour'];
                    $powerW = $point['predicted_w'];

                    // Physical constraint 1: Nighttime (h < 6 or h > 18) must be strictly 0.0 W
                    if ($hour < 6 || $hour > 18) {
                        Assert::same(0.0, $powerW, "Nighttime hour {$hour} must have 0.0 W generation");
                        Assert::same(0.0, $point['lower_w']);
                        Assert::same(0.0, $point['upper_w']);
                    } else {
                        // Physical constraint 2: Daytime power cannot exceed rated capacity (3000 W)
                        Assert::true($powerW <= 3000.0, "Daytime power {$powerW} W must not exceed 3000 W inverter capacity");
                        // Physical constraint 3: No negative generation
                        Assert::true($powerW >= 0.0, "Daytime power {$powerW} W must not be negative");
                    }
                }
            }),

            // 6. Evaluation metrics computation (MAE, RMSE, safe MAPE)
            TestResult::check('ML: Evaluator computes accurate MAE, RMSE and safely excludes zero-denominators in MAPE', function (): void {
                $evaluator = new ForecastEvaluator();

                $actuals = [0.0, 100.0, 200.0, 300.0];
                $predictions = [10.0, 110.0, 190.0, 330.0];

                $metrics = $evaluator->computeMetrics($actuals, $predictions, 50.0);

                // Errors: |0-10|=10, |100-110|=10, |200-190|=10, |300-330|=30 -> Mean = 60 / 4 = 15.0
                Assert::same(15.0, $metrics['mae']);

                // Squared errors: 100 + 100 + 100 + 900 = 1200 / 4 = 300 -> sqrt(300) = 17.3205
                Assert::same(17.3205, $metrics['rmse']);

                // MAPE: actual=0.0 is excluded (< 50.0). Terms: |10/100|=10%, |10/200|=5%, |30/300|=10% -> Mean = 25/3 = 8.33%
                Assert::same(8.33, $metrics['mape']);
            }),

            // 7. ML vs Baseline model selection
            TestResult::check('ML: Evaluator selects ML only when backtest MAE beats or matches baseline', function (): void {
                $evaluator = new ForecastEvaluator();

                // Case A: ML beats baseline -> select ML
                $mlMetricsBetter = ['mae' => 12.5, 'rmse' => 18.0, 'mape' => 5.2, 'sample_count' => 30, 'residual_std' => 15.0, 'r_squared' => 0.85];
                $baseMetrics = ['mae' => 16.0, 'rmse' => 22.0, 'mape' => 7.8, 'sample_count' => 30, 'residual_std' => 20.0, 'r_squared' => 0.70];

                $decisionA = $evaluator->compareWithBaseline($mlMetricsBetter, $baseMetrics, 20);
                Assert::true($decisionA['select_ml']);
                Assert::same('ACTIVE', $decisionA['status']);

                // Case B: ML worse than baseline -> reject ML and fall back
                $mlMetricsWorse = ['mae' => 20.0, 'rmse' => 25.0, 'mape' => 10.0, 'sample_count' => 30, 'residual_std' => 22.0, 'r_squared' => 0.50];
                $decisionB = $evaluator->compareWithBaseline($mlMetricsWorse, $baseMetrics, 20);
                Assert::false($decisionB['select_ml']);
                Assert::same('ML_EVALUATION_FAILED', $decisionB['status']);
            }),

            // 8. F3 -> F4 Energy Balance integration
            TestResult::check('ML: F3 forecast feeds directly into F4 Energy Balance pipeline', function (): void {
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];

                $forecastService = new ForecastService();
                $forecast = $forecastService->generateForecast(
                    $hhId,
                    ForecastHorizon::DAY_AHEAD,
                    new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC'))
                );

                Assert::true(isset($forecast['energy_balance_projection']));
                $balance = $forecast['energy_balance_projection'];

                // F4 classification state must be valid
                Assert::true(in_array($balance['classification'], ['POTENTIAL_SURPLUS', 'POTENTIAL_DEFICIT', 'BALANCED', 'UNAVAILABLE'], true));
                Assert::same('POTENTIAL', $balance['measurement_context']);
            }),

            // 9. F4 -> F6 Insights & F7 Recommendations coherence
            TestResult::check('ML: Forecast limitations propagate to F6 DataLimitation and F7 Recommendations', function (): void {
                $fixture = AuthTesting::householdFixture();
                $hhId = $fixture['household_id'];

                $forecastService = new ForecastService();
                $forecast = $forecastService->generateForecast(
                    $hhId,
                    ForecastHorizon::DAY_AHEAD,
                    new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC'))
                );

                // Zero bills -> Forecast has consumption limitation
                Assert::true(!empty($forecast['limitations']));

                // F6 Insights Engine test
                $detector = new DataLimitationDetector();
                $balanceResult = [
                    'analysis' => ['type' => 'AGGREGATE', 'period' => $forecast['period']],
                    'scope' => ['household_id' => $hhId],
                    'inputs' => null,
                    'result' => [
                        'state' => $forecast['energy_balance_projection']['state'],
                        'classification' => $forecast['energy_balance_projection']['state'] === 'CLASSIFIED' ? $forecast['energy_balance_projection']['classification'] : null,
                        'data_status' => 'FRESH',
                        'data_nature' => 'PREDICTED',
                        'explanation' => $forecast['energy_balance_projection']['explanation'],
                    ],
                ];

                $evidence = InsightEvidenceInput::fromArray([
                    'household_id' => $hhId,
                    'balance' => [$balanceResult],
                    'quality' => [
                        'overall_quality' => 'PARTIAL',
                        'overall_status' => 'DEGRADED',
                    ],
                ]);

                $candidates = $detector->detect($evidence);
                Assert::true(count($candidates) > 0, 'F6 must produce DATA_LIMITATION candidate when evidence is limited');

                // F7 Recommendation Engine test
                $recEngine = new RecommendationEngine();
                $recs = $recEngine->evaluate($forecast, null, [], [], new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));
                Assert::true(is_array($recs), 'F7 recommendation engine produces candidate list from forecast');
            }),

            // 10. Household isolation in ForecastModelRegistry
            TestResult::check('ML: Model evaluation registry enforces strict household isolation', function (): void {
                $registry = new ForecastModelRegistry();

                $hhA = self::uuid();
                $hhB = self::uuid();

                // Create households in test db
                $db = Database::connection();
                $db->prepare("INSERT INTO households (id, label, timezone, location_label, created_at, updated_at) VALUES (:id, 'Household A', 'Asia/Kolkata', 'Tirupati', NOW(), NOW())")->execute(['id' => $hhA]);
                $db->prepare("INSERT INTO households (id, label, timezone, location_label, created_at, updated_at) VALUES (:id, 'Household B', 'Asia/Kolkata', 'Nellore', NOW(), NOW())")->execute(['id' => $hhB]);

                $registry->saveEvaluation([
                    'household_id' => $hhA,
                    'model_type' => 'SOLAR',
                    'model_name' => 'ML_SOLAR_RIDGE_V1',
                    'model_version' => '1.0.0',
                    'forecast_horizon' => 'DAY_AHEAD',
                    'training_samples' => 50,
                    'evaluation_samples' => 15,
                    'mae' => 14.2,
                    'rmse' => 18.5,
                    'mape' => 6.2,
                    'status' => 'ACTIVE',
                    'feature_set' => 'SOLAR_FEATURES_V1',
                ]);

                // Household A can see its evaluation
                $evalA = $registry->findLatestActive($hhA, 'SOLAR', 'DAY_AHEAD');
                Assert::true($evalA !== null);
                Assert::same('ML_SOLAR_RIDGE_V1', $evalA['model_name']);

                // Household B CANNOT see Household A's evaluation
                $evalB = $registry->findLatestActive($hhB, 'SOLAR', 'DAY_AHEAD');
                Assert::same(null, $evalB, 'Household B must not access Household A evaluations');
            }),
        ];
    }
}
