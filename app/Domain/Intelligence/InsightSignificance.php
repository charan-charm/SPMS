<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Insight significance levels (docs/10 D-044).
 *
 * Significance is an evidence-weighted judgement produced by the
 * deterministic significance model. It is never a frontend decoration and
 * never derived from confidence alone.
 */
final class InsightSignificance
{
    public const LOW = 'LOW';
    public const MODERATE = 'MODERATE';
    public const HIGH = 'HIGH';

    public const LEVELS = [self::LOW, self::MODERATE, self::HIGH];

    public static function isValid(string $level): bool
    {
        return in_array($level, self::LEVELS, true);
    }

    /** @return 1|2|3 */
    public static function rank(string $level): int
    {
        return match ($level) {
            self::LOW => 1,
            self::MODERATE => 2,
            self::HIGH => 3,
        };
    }

    public static function fromRank(int $rank): string
    {
        return match ($rank) {
            3 => self::HIGH,
            2 => self::MODERATE,
            default => self::LOW,
        };
    }
}
