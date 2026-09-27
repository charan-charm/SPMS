<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

use InvalidArgumentException;

/**
 * SPMS F7 — Recommendation Confidence Model (docs/01 §14, §26; docs/10 D-072).
 *
 * Enforces the core rule:
 * A recommendation cannot have higher confidence than the weakest critical input (worst-of aggregation).
 */
final class RecommendationConfidence
{
    public const HIGH = 'HIGH';
    public const MODERATE = 'MODERATE';
    public const LOW = 'LOW';
    public const VERY_LOW = 'VERY_LOW';
    public const INSUFFICIENT = 'INSUFFICIENT';

    public const CONFIDENCE_HIGH = self::HIGH;
    public const CONFIDENCE_MODERATE = self::MODERATE;
    public const CONFIDENCE_MEDIUM = self::MODERATE;
    public const CONFIDENCE_LOW = self::LOW;
    public const CONFIDENCE_VERY_LOW = self::VERY_LOW;
    public const CONFIDENCE_INSUFFICIENT = self::INSUFFICIENT;

    public const ALL = [
        self::HIGH,
        self::MODERATE,
        self::LOW,
        self::VERY_LOW,
        self::INSUFFICIENT,
    ];

    private const RANK = [
        self::HIGH => 5,
        self::MODERATE => 4,
        self::LOW => 3,
        self::VERY_LOW => 2,
        self::INSUFFICIENT => 1,
    ];

    public static function normalize(string $confidence): string
    {
        $upper = strtoupper(trim($confidence));
        if ($upper === 'MEDIUM' || $upper === 'PARTIAL' || $upper === 'STALE') {
            return self::MODERATE;
        }
        if ($upper === 'GOOD' || $upper === 'FRESH' || $upper === 'HIGH') {
            return self::HIGH;
        }
        if ($upper === 'DEGRADED' || $upper === 'QUESTIONABLE' || $upper === 'LOW') {
            return self::LOW;
        }
        if ($upper === 'POOR' || $upper === 'CORRUPTED' || $upper === 'VERY_LOW') {
            return self::VERY_LOW;
        }
        if ($upper === 'MISSING' || $upper === 'INSUFFICIENT') {
            return self::INSUFFICIENT;
        }

        if (!array_key_exists($upper, self::RANK)) {
            return self::INSUFFICIENT;
        }

        return $upper;
    }

    public static function rank(string $confidence): int
    {
        $normalized = self::normalize($confidence);
        return self::RANK[$normalized] ?? 1;
    }

    /**
     * @param string|array<string, mixed> ...$confidences
     */
    public static function worstOf(string|array ...$confidences): string
    {
        $flattened = [];
        foreach ($confidences as $c) {
            if (is_array($c)) {
                foreach ($c as $sub) {
                    if (is_string($sub)) {
                        $flattened[] = $sub;
                    }
                }
            } elseif (is_string($c)) {
                $flattened[] = $c;
            }
        }

        if (empty($flattened)) {
            return self::INSUFFICIENT;
        }

        $minRank = 5;
        $worst = self::HIGH;

        foreach ($flattened as $c) {
            $normalized = self::normalize($c);
            $rank = self::rank($normalized);
            if ($rank < $minRank) {
                $minRank = $rank;
                $worst = $normalized;
            }
        }

        return $worst;
    }

    public static function isActionable(string $confidence): bool
    {
        // Recommendations require at least LOW confidence to be actionable
        return self::rank($confidence) >= self::rank(self::LOW);
    }
}
