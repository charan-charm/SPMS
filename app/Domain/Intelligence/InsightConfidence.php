<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Insight confidence levels (docs/10 D-045).
 *
 * Insight confidence is F6's own independent judgement about how well the
 * available evidence supports the insight. It is never copied from the
 * upstream F4/F5 confidence values and never fabricated as a numeric score.
 */
final class InsightConfidence
{
    public const HIGH = 'HIGH';
    public const MODERATE = 'MODERATE';
    public const LOW = 'LOW';
    public const VERY_LOW = 'VERY_LOW';
    public const INSUFFICIENT = 'INSUFFICIENT';

    public const LEVELS = [
        self::HIGH,
        self::MODERATE,
        self::LOW,
        self::VERY_LOW,
        self::INSUFFICIENT,
    ];

    public static function isValid(string $level): bool
    {
        return in_array($level, self::LEVELS, true);
    }

    /** @return 5|4|3|2|1 — higher means more confident; INSUFFICIENT is the floor. */
    public static function rank(string $level): int
    {
        return match ($level) {
            self::HIGH => 5,
            self::MODERATE => 4,
            self::LOW => 3,
            self::VERY_LOW => 2,
            self::INSUFFICIENT => 1,
        };
    }

    public static function fromRank(int $rank): string
    {
        return match ($rank) {
            5 => self::HIGH,
            4 => self::MODERATE,
            3 => self::LOW,
            2 => self::VERY_LOW,
            default => self::INSUFFICIENT,
        };
    }
}
