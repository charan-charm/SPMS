<?php

declare(strict_types=1);

namespace App\Domain\Forecast;

use InvalidArgumentException;

/**
 * F3 — Forecast Confidence Model (docs/01 §14, §26; docs/10 D-040, D-044).
 *
 * Enforces standardized confidence levels:
 * - HIGH: Robust multi-month historical evidence, recent verified bills, active real-time telemetry.
 * - MODERATE: Several historical weeks/cycles of evidence, stable patterns.
 * - LOW: Limited historical records (e.g. single bill, short telemetry), wide uncertainty bounds.
 * - VERY_LOW: Sparse or heuristic baseline data, minimal evidence.
 * - INSUFFICIENT: Missing mandatory evidence; cannot produce a numerically credible prediction.
 */
final class ForecastConfidence
{
    public const HIGH = 'HIGH';
    public const MODERATE = 'MODERATE';
    public const LOW = 'LOW';
    public const VERY_LOW = 'VERY_LOW';
    public const INSUFFICIENT = 'INSUFFICIENT';

    public const ALL = [
        self::HIGH,
        self::MODERATE,
        self::LOW,
        self::VERY_LOW,
        self::INSUFFICIENT,
    ];

    /** Severity score: lower is more confident, higher is less confident / more severe uncertainty. */
    private const SEVERITY = [
        self::HIGH => 0,
        self::MODERATE => 1,
        self::LOW => 2,
        self::VERY_LOW => 3,
        self::INSUFFICIENT => 4,
    ];

    public static function validate(string $confidence): string
    {
        $normalized = strtoupper(trim($confidence));
        if (!in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid forecast confidence "%s". Allowed: %s.',
                $confidence,
                implode(', ', self::ALL)
            ));
        }

        return $normalized;
    }

    /**
     * Worst-of aggregation rule: the least confident component determines the combined confidence.
     */
    public static function worstOf(string ...$confidences): string
    {
        if ($confidences === []) {
            return self::INSUFFICIENT;
        }

        $worst = self::HIGH;
        $worstScore = self::SEVERITY[$worst];

        foreach ($confidences as $c) {
            $valid = self::validate($c);
            $score = self::SEVERITY[$valid];
            if ($score > $worstScore) {
                $worstScore = $score;
                $worst = $valid;
            }
        }

        return $worst;
    }
}
