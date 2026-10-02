<?php

declare(strict_types=1);

namespace App\Domain\Forecast\ML;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Forecast\ConsumptionForecastModel;
use App\Domain\Forecast\ForecastConfidence;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastSnapshot;
use App\Repositories\BillRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * F3 ML — Production-Grade ML Household Electricity Consumption Forecast Model (docs/01 §14, docs/02 §2, docs/10 D-040).
 *
 * Implements trend-and-seasonal Ridge regression over authoritative VERIFIED electricity bills.
 *
 * Hard Rules:
 *  - Train ONLY from authoritative verified bill evidence (`bills` table with status `VERIFIED`).
 *  - NEVER treat: failed extraction, unverified bills, rejected bills, missing values, or fabricated values as training observations.
 *  - When verified bills < 3: returns structured limitation and falls back to deterministic model.
 *  - When 0 verified bills: predictedValue = null, never converts "no data" into zero consumption.
 */
final class MLConsumptionForecastModel
{
    public const MODEL_NAME = 'ML_CONSUMPTION_REGRESSION_V1';
    public const MODEL_VERSION = '1.0.0';

    private readonly ForecastFeatureBuilder $featureBuilder;
    private readonly ForecastEvaluator $evaluator;
    private readonly ForecastModelRegistry $registry;

    public function __construct(
        private readonly BillRepository $bills,
        private readonly SolarReadingRepository $readings,
        ?ForecastFeatureBuilder $featureBuilder = null,
        ?ForecastEvaluator $evaluator = null,
        ?ForecastModelRegistry $registry = null
    ) {
        $this->featureBuilder = $featureBuilder ?? new ForecastFeatureBuilder();
        $this->evaluator = $evaluator ?? new ForecastEvaluator();
        $this->registry = $registry ?? new ForecastModelRegistry();
    }

    /**
     * Generate ML consumption forecast with backtesting and verified bill evidence.
     *
     * @param string $householdId
     * @param string $horizon
     * @param DateTimeImmutable $referenceTime
     * @param ConsumptionForecastModel $baselineModel
     * @return array{
     *     snapshot: ForecastSnapshot,
     *     status: string,
     *     forecast_source: string,
     *     fallback_reason: ?string,
     *     evaluation: ?array<string, mixed>
     * }
     */
    public function generate(
        string $householdId,
        string $horizon,
        DateTimeImmutable $referenceTime,
        ConsumptionForecastModel $baselineModel
    ): array {
        $window = ForecastHorizon::computeTimeWindow($horizon, $referenceTime);
        $start = $window['start'];
        $end = $window['end'];
        $resolution = $window['resolution'];

        // 1. Gather all verified bills for the household
        $allBills = $this->bills->listForHousehold($householdId);
        $verifiedBills = array_values(array_filter(
            $allBills,
            static fn (Bill $b): bool => $b->status() === BillStatus::VERIFIED
        ));

        $verifiedBillCount = count($verifiedBills);

        // Case 1: ZERO verified bills -> Produce honest null-prediction snapshot (never convert to zero!)
        if ($verifiedBillCount === 0) {
            $baselineSnapshot = $baselineModel->generate($householdId, $horizon, $referenceTime);

            $this->registry->saveEvaluation([
                'household_id' => $householdId,
                'model_type' => 'CONSUMPTION',
                'model_name' => self::MODEL_NAME,
                'model_version' => self::MODEL_VERSION,
                'forecast_horizon' => $horizon,
                'training_samples' => 0,
                'evaluation_samples' => 0,
                'mae' => null,
                'rmse' => null,
                'mape' => null,
                'status' => 'FORECAST_UNAVAILABLE',
                'fallback_reason' => 'No verified electricity bills exist for this household.',
                'feature_set' => ForecastFeatureBuilder::CONSUMPTION_FEATURE_VERSION,
                'metrics_json' => ['reason' => 'Zero verified bills'],
            ]);

            return [
                'snapshot' => $baselineSnapshot,
                'status' => 'FORECAST_UNAVAILABLE',
                'forecast_source' => 'DETERMINISTIC',
                'fallback_reason' => 'No verified electricity bills exist for this household.',
                'evaluation' => null,
            ];
        }

        // Case 2: Insufficient bills for ML training (< 3 verified bills)
        if ($verifiedBillCount < ForecastEvaluator::MIN_CONSUMPTION_EVAL_SAMPLES) {
            $fallbackReason = sprintf(
                'Insufficient verified billing history (%d verified bills, %d required for ML modeling); using deterministic recency-weighted baseline.',
                $verifiedBillCount,
                ForecastEvaluator::MIN_CONSUMPTION_EVAL_SAMPLES
            );

            $baselineSnapshot = $baselineModel->generate($householdId, $horizon, $referenceTime);

            $this->registry->saveEvaluation([
                'household_id' => $householdId,
                'model_type' => 'CONSUMPTION',
                'model_name' => self::MODEL_NAME,
                'model_version' => self::MODEL_VERSION,
                'forecast_horizon' => $horizon,
                'training_samples' => $verifiedBillCount,
                'evaluation_samples' => 0,
                'mae' => null,
                'rmse' => null,
                'mape' => null,
                'status' => 'ML_INSUFFICIENT_DATA',
                'fallback_reason' => $fallbackReason,
                'feature_set' => ForecastFeatureBuilder::CONSUMPTION_FEATURE_VERSION,
                'metrics_json' => ['reason' => $fallbackReason],
            ]);

            return [
                'snapshot' => $baselineSnapshot,
                'status' => 'ML_INSUFFICIENT_DATA',
                'forecast_source' => 'DETERMINISTIC',
                'fallback_reason' => $fallbackReason,
                'evaluation' => null,
            ];
        }

        // 2. Build chronological consumption dataset
        $dataset = $this->featureBuilder->buildConsumptionDataset($verifiedBills);
        $datasetCount = count($dataset);

        if ($datasetCount < ForecastEvaluator::MIN_CONSUMPTION_EVAL_SAMPLES) {
            $fallbackReason = 'Verified bills lacked valid consumption kWh or billing period dates; falling back to deterministic baseline.';
            $baselineSnapshot = $baselineModel->generate($householdId, $horizon, $referenceTime);

            return [
                'snapshot' => $baselineSnapshot,
                'status' => 'ML_INSUFFICIENT_DATA',
                'forecast_source' => 'DETERMINISTIC',
                'fallback_reason' => $fallbackReason,
                'evaluation' => null,
            ];
        }

        // 3. Chronological train/test backtesting split
        // For consumption, reserve the latest verified bill(s) for test evaluation
        $split = $this->evaluator->splitChronological($dataset, 0.70);
        $trainSet = $split['train'];
        $testSet = $split['test'];

        $Xtrain = array_column($trainSet, 'features');
        $ytrain = array_column($trainSet, 'target');

        $Xtest = array_column($testSet, 'features');
        $ytest = array_column($testSet, 'target');

        // 4. Fit regularized regression model
        $weights = LinearRegressionSolver::fit($Xtrain, $ytrain, 0.2);

        // Baseline prediction for test set: weighted mean of training actuals
        $trainMean = array_sum($ytrain) / count($ytrain);

        $mlTestPreds = [];
        $baseTestPreds = [];

        foreach ($testSet as $testSample) {
            $rawPred = LinearRegressionSolver::predict($testSample['features'], $weights);
            $mlTestPreds[] = max(0.0, $rawPred);
            $baseTestPreds[] = $trainMean;
        }

        // 5. Evaluate metrics
        $mlMetrics = $this->evaluator->computeMetrics($ytest, $mlTestPreds, 0.5);
        $baseMetrics = $this->evaluator->computeMetrics($ytest, $baseTestPreds, 0.5);

        $comparison = $this->evaluator->compareWithBaseline(
            $mlMetrics,
            $baseMetrics,
            count($testSet)
        );

        $trainingStart = $trainSet[0]['period_start'] ?? null;
        $trainingEnd = end($trainSet)['period_end'] ?? null;
        $evalStart = $testSet[0]['period_start'] ?? null;
        $evalEnd = end($testSet)['period_end'] ?? null;

        $evaluationPayload = [
            'household_id' => $householdId,
            'model_type' => 'CONSUMPTION',
            'model_name' => self::MODEL_NAME,
            'model_version' => self::MODEL_VERSION,
            'forecast_horizon' => $horizon,
            'training_start' => $trainingStart,
            'training_end' => $trainingEnd,
            'evaluation_start' => $evalStart,
            'evaluation_end' => $evalEnd,
            'training_samples' => count($trainSet),
            'evaluation_samples' => count($testSet),
            'mae' => $mlMetrics['mae'],
            'rmse' => $mlMetrics['rmse'],
            'mape' => $mlMetrics['mape'],
            'baseline_mae' => $baseMetrics['mae'],
            'baseline_rmse' => $baseMetrics['rmse'],
            'status' => $comparison['status'],
            'fallback_reason' => $comparison['select_ml'] ? null : $comparison['reason'],
            'feature_set' => ForecastFeatureBuilder::CONSUMPTION_FEATURE_VERSION,
            'metrics_json' => [
                'ml' => $mlMetrics,
                'baseline' => $baseMetrics,
                'comparison' => $comparison,
                'weights' => array_map(static fn (float $w): float => round($w, 4), $weights),
            ],
        ];

        $this->registry->saveEvaluation($evaluationPayload);

        // Fall back if ML did not beat baseline
        if (!$comparison['select_ml']) {
            $baselineSnapshot = $baselineModel->generate($householdId, $horizon, $referenceTime);

            return [
                'snapshot' => $baselineSnapshot,
                'status' => $comparison['status'],
                'forecast_source' => 'DETERMINISTIC',
                'fallback_reason' => $comparison['reason'],
                'evaluation' => $evaluationPayload,
            ];
        }

        // 6. ML Model Selected! Predict future consumption
        $refMonth = (int) $referenceTime->format('n');
        $seasonSin = round(sin((2.0 * M_PI * $refMonth) / 12.0), 4);
        $seasonCos = round(cos((2.0 * M_PI * $refMonth) / 12.0), 4);

        $latestTarget = end($dataset)['target'];
        $futureFeatures = [
            1.0,
            $seasonSin,
            $seasonCos,
            1.0, // max recency
            1.0, // lag normalized
            1.0, // rolling normalized
            0.0, // standard 30 day cycle
        ];

        $predictedDailyKwh = max(1.0, round(LinearRegressionSolver::predict($futureFeatures, $weights), 3));

        $dataNature = ForecastSnapshot::NATURE_PREDICTED;
        try {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT a.mode FROM accounts a JOIN household_memberships hm ON hm.account_id = a.id WHERE hm.household_id = :hid LIMIT 1');
            $stmt->execute(['hid' => $householdId]);
            if ($stmt->fetchColumn() === 'DEMO_TEST') {
                $dataNature = ForecastSnapshot::NATURE_SIMULATED;
            }
        } catch (\Throwable) {
            // Non-fatal
        }

        $confidence = $verifiedBillCount >= 6 ? ForecastConfidence::HIGH : ForecastConfidence::MODERATE;
        $residualStdKwh = max(0.5, $mlMetrics['residual_std']);

        // Generate points across horizon
        $points = [];
        $totalPredictedKwh = 0.0;
        $current = $start;

        if ($resolution === ForecastHorizon::RESOLUTION_HOURLY) {
            while ($current < $end) {
                $hour = (int) $current->format('H');
                $timestampKey = $current->format('Y-m-d\TH:i:s\Z');

                $hourlyLoadFactor = self::residentialDiurnalLoadFactor($hour);
                $predKwh = round($predictedDailyKwh * $hourlyLoadFactor, 3);
                $predW = round($predKwh * 1000.0, 1);

                $uncKwh = 1.96 * ($residualStdKwh * $hourlyLoadFactor);
                $lowerKwh = max(0.0, round($predKwh - $uncKwh, 3));
                $upperKwh = round($predKwh + $uncKwh, 3);

                $lowerW = max(0.0, round($predW - $uncKwh * 1000.0, 1));
                $upperW = round($predW + $uncKwh * 1000.0, 1);

                $points[] = [
                    'timestamp' => $timestampKey,
                    'hour' => $hour,
                    'predicted_w' => $predW,
                    'lower_w' => $lowerW,
                    'upper_w' => $upperW,
                    'predicted_kwh' => $predKwh,
                    'lower_kwh' => $lowerKwh,
                    'upper_kwh' => $upperKwh,
                    'data_nature' => $dataNature,
                    'confidence' => $confidence,
                ];

                $totalPredictedKwh += $predKwh;
                $current = $current->modify('+1 hour');
            }
        } else {
            // Daily resolution
            while ($current < $end) {
                $dateKey = $current->format('Y-m-d');
                $timestampKey = $current->format('Y-m-d\T00:00:00\Z');

                $predKwh = $predictedDailyKwh;
                $uncKwh = 1.96 * $residualStdKwh;
                $lowerKwh = max(0.0, round($predKwh - $uncKwh, 3));
                $upperKwh = round($predKwh + $uncKwh, 3);

                $points[] = [
                    'timestamp' => $timestampKey,
                    'date' => $dateKey,
                    'predicted_kwh' => $predKwh,
                    'lower_kwh' => $lowerKwh,
                    'upper_kwh' => $upperKwh,
                    'data_nature' => $dataNature,
                    'confidence' => $confidence,
                ];

                $totalPredictedKwh += $predKwh;
                $current = $current->modify('+1 day');
            }
        }

        $totalPredictedKwh = round($totalPredictedKwh, 3);
        $totalLowerKwh = max(0.0, round($totalPredictedKwh - 1.96 * $residualStdKwh, 3));
        $totalUpperKwh = round($totalPredictedKwh + 1.96 * $residualStdKwh, 3);

        $limitations = [];
        if ($verifiedBillCount < 6) {
            $limitations[] = sprintf('ML consumption model trained on %d verified billing cycles: seasonal accuracy will improve with full annual cycle (12 bills).', $verifiedBillCount);
        }

        $provenance = [
            'forecast_source' => 'ML',
            'model_name' => self::MODEL_NAME,
            'model_version' => self::MODEL_VERSION,
            'feature_set' => ForecastFeatureBuilder::CONSUMPTION_FEATURE_VERSION,
            'training_samples' => count($trainSet),
            'evaluation_samples' => count($testSet),
            'training_period' => ['start' => $trainingStart, 'end' => $trainingEnd],
            'evaluation_period' => ['start' => $evalStart, 'end' => $evalEnd],
            'mae_kwh' => $mlMetrics['mae'],
            'rmse_kwh' => $mlMetrics['rmse'],
            'mape_pct' => $mlMetrics['mape'],
            'baseline_mae_kwh' => $baseMetrics['mae'],
            'improvement_pct' => $comparison['improvement_pct'],
            'residual_std_kwh' => $mlMetrics['residual_std'],
            'verified_bills_count' => $verifiedBillCount,
            'predicted_daily_kwh' => $predictedDailyKwh,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];

        $snapshot = new ForecastSnapshot(
            forecastType: 'CONSUMPTION',
            horizon: $horizon,
            period: [
                'start' => $start->format('Y-m-d\TH:i:s\Z'),
                'end' => $end->format('Y-m-d\TH:i:s\Z'),
                'resolution' => $resolution,
            ],
            predictedValue: $totalPredictedKwh,
            uncertaintyRange: [
                'lower' => $totalLowerKwh,
                'upper' => $totalUpperKwh,
            ],
            confidence: $confidence,
            dataNature: $dataNature,
            dataQuality: ForecastSnapshot::QUALITY_GOOD,
            provenance: $provenance,
            limitations: $limitations,
            timeSeriesPoints: $points
        );

        return [
            'snapshot' => $snapshot,
            'status' => 'ML_AVAILABLE',
            'forecast_source' => 'ML',
            'fallback_reason' => null,
            'evaluation' => $evaluationPayload,
        ];
    }

    private static function residentialDiurnalLoadFactor(int $hour): float
    {
        $factors = [
            0 => 0.025, 1 => 0.022, 2 => 0.020, 3 => 0.020, 4 => 0.022, 5 => 0.028,
            6 => 0.038, 7 => 0.055, 8 => 0.062, 9 => 0.050, 10 => 0.040, 11 => 0.038,
            12 => 0.040, 13 => 0.038, 14 => 0.036, 15 => 0.038, 16 => 0.042, 17 => 0.052,
            18 => 0.068, 19 => 0.075, 20 => 0.070, 21 => 0.058, 22 => 0.045, 23 => 0.036,
        ];

        return $factors[$hour] ?? (1.0 / 24.0);
    }
}
