<?php

declare(strict_types=1);

namespace App\Domain\Forecast\ML;

/**
 * Lightweight, numerically stable Regularized Ridge Regression Solver in pure PHP.
 * Solves (X^T * X + lambda * I) * beta = X^T * y via Gaussian elimination with partial pivoting.
 */
final class LinearRegressionSolver
{
    /**
     * Fit regularized linear model.
     *
     * @param list<list<float>> $X Feature matrix (n samples x p features)
     * @param list<float> $y Target vector (n samples)
     * @param float $lambda Ridge regularization penalty
     * @return list<float> Fitted weights beta (length p)
     */
    public static function fit(array $X, array $y, float $lambda = 0.1): array
    {
        $n = count($X);
        if ($n === 0) {
            return [];
        }

        $p = count($X[0]);
        if ($p === 0 || count($y) !== $n) {
            return [];
        }

        // 1. Compute Gram matrix A = X^T * X + lambda * I (p x p)
        $A = array_fill(0, $p, array_fill(0, $p, 0.0));
        for ($j = 0; $j < $p; $j++) {
            for ($k = $j; $k < $p; $k++) {
                $sum = 0.0;
                for ($i = 0; $i < $n; $i++) {
                    $sum += $X[$i][$j] * $X[$i][$k];
                }
                $A[$j][$k] = $sum;
                $A[$k][$j] = $sum;
            }
            $A[$j][$j] += $lambda; // L2 Ridge regularization
        }

        // 2. Compute b = X^T * y (p x 1)
        $b = array_fill(0, $p, 0.0);
        for ($j = 0; $j < $p; $j++) {
            $sum = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $sum += $X[$i][$j] * $y[$i];
            }
            $b[$j] = $sum;
        }

        // 3. Solve A * beta = b via Gaussian elimination with partial pivoting
        $beta = self::solveGaussian($A, $b, $p);

        return $beta ?? array_fill(0, $p, 0.0);
    }

    /**
     * Predict target for a single feature vector.
     *
     * @param list<float> $features
     * @param list<float> $weights
     */
    public static function predict(array $features, array $weights): float
    {
        $p = min(count($features), count($weights));
        $val = 0.0;
        for ($j = 0; $j < $p; $j++) {
            $val += $features[$j] * $weights[$j];
        }

        return $val;
    }

    /**
     * Gaussian elimination with partial pivoting.
     *
     * @param list<list<float>> $A
     * @param list<float> $b
     * @return list<float>|null
     */
    private static function solveGaussian(array $A, array $b, int $n): ?array
    {
        // Augmented matrix [A | b]
        for ($i = 0; $i < $n; $i++) {
            $A[$i][$n] = $b[$i];
        }

        for ($k = 0; $k < $n; $k++) {
            // Find pivot row
            $maxRow = $k;
            $maxVal = abs($A[$k][$k]);
            for ($i = $k + 1; $i < $n; $i++) {
                $absVal = abs($A[$i][$k]);
                if ($absVal > $maxVal) {
                    $maxVal = $absVal;
                    $maxRow = $i;
                }
            }

            if ($maxVal < 1e-12) {
                // Singular or near-singular
                return null;
            }

            // Swap rows
            if ($maxRow !== $k) {
                $temp = $A[$k];
                $A[$k] = $A[$maxRow];
                $A[$maxRow] = $temp;
            }

            // Normalize pivot row
            $pivot = $A[$k][$k];
            for ($j = $k; $j <= $n; $j++) {
                $A[$k][$j] /= $pivot;
            }

            // Eliminate other rows
            for ($i = 0; $i < $n; $i++) {
                if ($i !== $k) {
                    $factor = $A[$i][$k];
                    for ($j = $k; $j <= $n; $j++) {
                        $A[$i][$j] -= $factor * $A[$k][$j];
                    }
                }
            }
        }

        $x = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $x[$i] = $A[$i][$n];
        }

        return $x;
    }
}
