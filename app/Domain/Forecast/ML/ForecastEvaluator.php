<?php

declare(strict_types=1);

namespace App\Domain\Forecast\ML;

/**
 * F3 ML — Forecast Evaluator & Backtesting Engine (docs/01 §14, docs/02 §2, docs/10 D-040).
 *
 * Implements chronological backtesting, error metric calculation (MAE, RMSE, safe MAPE),
 * residual variance estimation for uncertainty intervals, and baseline comparison.
 *
 * Hard Rules:
 *  - Chronological splitting: NEVER randomly shuffle time-series data across splits.
 *  - Safe MAPE: MAPE is only computed when actual y >= epsilon to prevent division by zero or explosive percentages.
 *  - Objective selection: An ML model is selected over the deterministic baseline only if backtesting
 *    satisfies sample size requirements and proves lower or equal MAE.
 */
final class ForecastEvaluator
{
    /** Minimum observations required for Solar backtesting evaluation (approx 2 daylight days) */
    public const MIN_SOLAR_EVAL_SAMPLES = 24;

    /** Minimum verified bills required for Consumption backtesting evaluation */
    public const MIN_CONSUMPTION_EVAL_SAMPLES = 3;

    /**
     * Chronologically split dataset into train and test sets.
     *
     * @template T
     * @param list<T> $dataset Chronologically sorted observations
     * @param float $trainRatio Fraction of earlier data for training (e.g. 0.70)
     * @return array{train: list<T>, test: list<T>, split_index: int}
     */
    public function splitChronological(array $dataset, float $trainRatio = 0.70): array
    {
        $n = count($dataset);
        if ($n === 0) {
            return ['train' => [], 'test' => [], 'split_index' => 0];
        }

        $trainCount = (int) max(1, min($n - 1, floor($n * $trainRatio)));
        $train = array_slice($dataset, 0, $trainCount);
        $test = array_slice($dataset, $trainCount);

        return [
            'train' => $train,
            'test' => $test,
            'split_index' => $trainCount,
        ];
    }

    /**
     * Compute backtest error metrics: MAE, RMSE, and safe MAPE.
     *
     * @param list<float> $actuals
     * @param list<float> $predictions
     * @param float $mapeEpsilon Threshold below which MAPE terms are excluded (prevents zero-division)
     * @return array{
     *     mae: float,
     *     rmse: float,
     *     mape: ?float,
     *     sample_count: int,
     *     residual_std: float,
     *     r_squared: float
     * }
     */
    public function computeMetrics(
        array $actuals,
        array $predictions,
        float $mapeEpsilon = 0.05
    ): array {
        $n = min(count($actuals), count($predictions));
        if ($n === 0) {
            return [
                'mae' => 0.0,
                'rmse' => 0.0,
                'mape' => null,
                'sample_count' => 0,
                'residual_std' => 0.0,
                'r_squared' => 0.0,
            ];
        }

        $absErrors = [];
        $sqErrors = [];
        $residuals = [];
        $mapeTerms = [];
        $actualSum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $y = (float) $actuals[$i];
            $yHat = (float) $predictions[$i];
            $actualSum += $y;

            $error = $y - $yHat;
            $absError = abs($error);
            $sqError = $error * $error;

            $absErrors[] = $absError;
            $sqErrors[] = $sqError;
            $residuals[] = $error;

            // Safe MAPE: denominator must exceed threshold
            if ($y >= $mapeEpsilon) {
                $mapeTerms[] = ($absError / $y) * 100.0;
            }
        }

        $mae = array_sum($absErrors) / $n;
        $rmse = sqrt(array_sum($sqErrors) / $n);
        $mape = $mapeTerms !== [] ? (array_sum($mapeTerms) / count($mapeTerms)) : null;

        // Residual standard deviation
        $meanResidual = array_sum($residuals) / $n;
        $resVarianceSum = 0.0;
        foreach ($residuals as $res) {
            $resVarianceSum += ($res - $meanResidual) ** 2;
        }
        $residualStd = $n > 1 ? sqrt($resVarianceSum / ($n - 1)) : $rmse;

        // R-squared (coefficient of determination)
        $actualMean = $actualSum / $n;
        $ssTotal = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $ssTotal += ($actuals[$i] - $actualMean) ** 2;
        }
        $ssResidual = array_sum($sqErrors);
        $rSquared = $ssTotal > 1e-9 ? max(-1.0, 1.0 - ($ssResidual / $ssTotal)) : 0.0;

        return [
            'mae' => round($mae, 4),
            'rmse' => round($rmse, 4),
            'mape' => $mape !== null ? round($mape, 2) : null,
            'sample_count' => $n,
            'residual_std' => round($residualStd, 4),
            'r_squared' => round($rSquared, 4),
        ];
    }

    /**
     * Compare candidate ML model against deterministic baseline.
     *
     * @param array{mae: float, rmse: float, mape: ?float, sample_count: int, residual_std: float, r_squared: float} $mlMetrics
     * @param array{mae: float, rmse: float, mape: ?float, sample_count: int, residual_std: float, r_squared: float} $baselineMetrics
     * @param int $minSamples
     * @return array{
     *     select_ml: bool,
     *     status: string,
     *     reason: string,
     *     improvement_pct: float
     * }
     */
    public function compareWithBaseline(
        array $mlMetrics,
        array $baselineMetrics,
        int $minSamples
    ): array {
        if ($mlMetrics['sample_count'] < $minSamples) {
            return [
                'select_ml' => false,
                'status' => 'ML_INSUFFICIENT_DATA',
                'reason' => sprintf(
                    'Evaluation samples (%d) below minimum threshold (%d); falling back to deterministic baseline.',
                    $mlMetrics['sample_count'],
                    $minSamples
                ),
                'improvement_pct' => 0.0,
            ];
        }

        // Selection rule: ML MAE must not exceed Baseline MAE
        $baselineMae = $baselineMetrics['mae'];
        $mlMae = $mlMetrics['mae'];

        if ($baselineMae <= 0.0) {
            $improvementPct = 0.0;
        } else {
            $improvementPct = round((($baselineMae - $mlMae) / $baselineMae) * 100.0, 2);
        }

        if ($mlMae <= $baselineMae) {
            return [
                'select_ml' => true,
                'status' => 'ACTIVE',
                'reason' => sprintf(
                    'ML model outperformed baseline (ML MAE: %.3f vs Baseline MAE: %.3f, %.1f%% improvement).',
                    $mlMae,
                    $baselineMae,
                    $improvementPct
                ),
                'improvement_pct' => $improvementPct,
            ];
        }

        return [
            'select_ml' => false,
            'status' => 'ML_EVALUATION_FAILED',
            'reason' => sprintf(
                'ML model MAE (%.3f) did not beat deterministic baseline MAE (%.3f); falling back to baseline.',
                $mlMae,
                $baselineMae
            ),
            'improvement_pct' => $improvementPct,
        ];
    }
}
