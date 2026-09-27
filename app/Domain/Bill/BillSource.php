<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use InvalidArgumentException;

/**
 * SPMS — Canonical Bill and Measurement Source Vocabulary.
 *
 * Distinguishes the originating system or format:
 * - ELECTRICITY_BILL: Physical / scanned / PDF utility bill.
 * - APSPDCL_EXPORT: Direct utility statement / spreadsheet export.
 * - USER_MANUAL: Direct human verified entry.
 * - GOODWE_SEMS: Live inverter telemetry (never a bill source).
 */
final class BillSource
{
    public const ELECTRICITY_BILL = 'ELECTRICITY_BILL';
    public const APSPDCL_EXPORT = 'APSPDCL_EXPORT';
    public const USER_MANUAL = 'USER_MANUAL';
    public const GOODWE_SEMS = 'GOODWE_SEMS';

    public const ALL = [
        self::ELECTRICITY_BILL,
        self::APSPDCL_EXPORT,
        self::USER_MANUAL,
        self::GOODWE_SEMS,
    ];

    public const BILL_SOURCES = [
        self::ELECTRICITY_BILL,
        self::APSPDCL_EXPORT,
        self::USER_MANUAL,
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }

    public static function isBillSource(string $source): bool
    {
        return in_array($source, self::BILL_SOURCES, true);
    }

    public static function assertBillSource(string $source): void
    {
        if (!self::isBillSource($source)) {
            throw new InvalidArgumentException('Invalid bill source: ' . $source);
        }
    }
}
