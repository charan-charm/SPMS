<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use InvalidArgumentException;

/**
 * B1 — Controlled data-quality/limitation codes (docs/10 D-067 §12).
 *
 * Honest statements about what is wrong or missing in a bill's data.
 * A closed vocabulary: codes exist for the limitation classes the bill
 * pipeline actually observes; anything unobserved has no code and cannot
 * be invented at runtime (no free-text "reason" reaches the database).
 *
 * Recording a flag NEVER changes a value: a missing consumption stays
 * NULL (unknown), it is never defaulted to zero — the flag documents the
 * gap, the NULL honestly carries it (§12/§15).
 */
final class QualityCode
{
    public const MISSING_CONSUMPTION = 'MISSING_CONSUMPTION';
    public const MISSING_BILLING_PERIOD = 'MISSING_BILLING_PERIOD';
    public const MISSING_METER_READINGS = 'MISSING_METER_READINGS';
    public const MISSING_SOLAR_READINGS = 'MISSING_SOLAR_READINGS';
    public const UNREADABLE_FIELD = 'UNREADABLE_FIELD';
    public const AMBIGUOUS_AMOUNT = 'AMBIGUOUS_AMOUNT';
    public const CONFLICTING_METER_READINGS = 'CONFLICTING_METER_READINGS';
    public const INCONSISTENT_NET_UNITS = 'INCONSISTENT_NET_UNITS';

    public const ALL = [
        self::MISSING_CONSUMPTION,
        self::MISSING_BILLING_PERIOD,
        self::MISSING_METER_READINGS,
        self::MISSING_SOLAR_READINGS,
        self::UNREADABLE_FIELD,
        self::AMBIGUOUS_AMOUNT,
        self::CONFLICTING_METER_READINGS,
        self::INCONSISTENT_NET_UNITS,
    ];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::ALL, true);
    }

    public static function assertValid(string $code): void
    {
        if (!self::isValid($code)) {
            throw new InvalidArgumentException('unknown quality code: ' . $code);
        }
    }
}
