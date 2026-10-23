<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * B1 — The complete persistable bill field vocabulary (docs/10 D-067).
 *
 * This whitelist IS the data-minimization boundary: exactly the fields
 * the bills schema may carry — nothing else. A name outside it (a
 * customer name, account number, meter serial, raw OCR text, provider
 * response, document path, ...) cannot be persisted through ANY of the
 * Bill domain objects, not because a column is missing but because the
 * domain refuses the key at every entry point.
 *
 * Every value carries its storage kind so the same validation rules
 * apply to bills, drafts, corrections and confidence alike:
 * - ENERGY  DECIMAL(14,3): signed (net units may be negative), at most
 *   three decimal places — MORE precision is rejected, never truncated
 *   ("do not silently coerce", persistence must not lie about numbers).
 * - MONEY   DECIMAL(12,2): fixed-precision decimal strings, signed
 *   (arrears credits); never floats, never collapsed into one total.
 * - LOAD    DECIMAL(8,2): non-negative contracted capacity.
 * - DATE    strict Y-m-d, chronology checked by the aggregate.
 * - TEXT    bounded display/context labels, never empty strings.
 *
 * NULL is the ONLY representation of "unknown / missing / not extracted"
 * (§15). This class has no default-to-zero path anywhere.
 */
final class BillField
{
    // Period and issue dates
    public const BILLING_PERIOD_START = 'billing_period_start';
    public const BILLING_PERIOD_END = 'billing_period_end';
    public const BILL_DATE = 'bill_date';
    public const DUE_DATE = 'due_date';

    // Energy / meter evidence (signed)
    public const CONSUMPTION_KWH = 'consumption_kwh';
    public const PREVIOUS_METER_READING = 'previous_meter_reading';
    public const PRESENT_METER_READING = 'present_meter_reading';
    public const PREVIOUS_SOLAR_READING = 'previous_solar_reading';
    public const PRESENT_SOLAR_READING = 'present_solar_reading';
    public const SOLAR_UNITS_KWH = 'solar_units_kwh';
    public const NET_UNITS_KWH = 'net_units_kwh';
    public const OPENING_KVAH = 'opening_kvah';
    public const CLOSING_KVAH = 'closing_kvah';
    public const CONSUMPTION_KVAH = 'consumption_kvah';

    public const ENERGY = [
        self::CONSUMPTION_KWH,
        self::PREVIOUS_METER_READING,
        self::PRESENT_METER_READING,
        self::PREVIOUS_SOLAR_READING,
        self::PRESENT_SOLAR_READING,
        self::SOLAR_UNITS_KWH,
        self::NET_UNITS_KWH,
        self::OPENING_KVAH,
        self::CLOSING_KVAH,
        self::CONSUMPTION_KVAH,
    ];

    // Money — conceptually distinct, stored separately (§6)
    public const BILL_AMOUNT = 'bill_amount';
    public const ARREARS = 'arrears';
    public const TOTAL_DUE = 'total_due';
    public const ENERGY_CHARGES = 'energy_charges';
    public const FIXED_CHARGES = 'fixed_charges';
    public const CUSTOMER_SERVICE_CHARGES = 'customer_service_charges';
    public const OTHER_CHARGES = 'other_charges';
    public const TAXES_ADJUSTMENTS = 'taxes_adjustments';

    public const MONEY = [
        self::BILL_AMOUNT,
        self::ARREARS,
        self::TOTAL_DUE,
        self::ENERGY_CHARGES,
        self::FIXED_CHARGES,
        self::CUSTOMER_SERVICE_CHARGES,
        self::OTHER_CHARGES,
        self::TAXES_ADJUSTMENTS,
    ];

    public const CONTRACTED_LOAD = 'contracted_load';
    public const LOAD = [self::CONTRACTED_LOAD];

    public const DATES = [
        self::BILLING_PERIOD_START,
        self::BILLING_PERIOD_END,
        self::BILL_DATE,
        self::DUE_DATE,
    ];

    /** D-071 — electricity service/USC identity (bill ownership anchor). */
    public const SERVICE_NUMBER = 'service_number';

    public const SOURCE = 'source';
    public const SOURCE_METADATA_JSON = 'source_metadata_json';
    public const UTILITY_PROVIDER = 'utility_provider';
    public const TARIFF_CATEGORY = 'tariff_category';
    public const METER_TYPE = 'meter_type';

    public const TEXT = [
        self::SOURCE,
        self::UTILITY_PROVIDER,
        self::TARIFF_CATEGORY,
        self::METER_TYPE,
        self::SERVICE_NUMBER,
        self::SOURCE_METADATA_JSON,
    ];

    public const ALL = [
        ...self::DATES,
        ...self::ENERGY,
        ...self::MONEY,
        ...self::LOAD,
        ...self::TEXT,
    ];

    private const MAX_TEXT_LENGTH = [
        'source' => 32,
        'utility_provider' => 120,
        'tariff_category' => 60,
        'meter_type' => 60,
        // D-071: the draft snapshot carries the extracted (text-cleaned)
        // value; bills.household registration stores the canonical form.
        'service_number' => 32,
        'source_metadata_json' => 4000,
    ];

    public const KIND_DATE = 'date';
    public const KIND_ENERGY = 'energy';
    public const KIND_MONEY = 'money';
    public const KIND_LOAD = 'load';
    public const KIND_TEXT = 'text';

    public static function kind(string $field): string
    {
        return match (true) {
            in_array($field, self::DATES, true) => self::KIND_DATE,
            in_array($field, self::ENERGY, true) => self::KIND_ENERGY,
            in_array($field, self::MONEY, true) => self::KIND_MONEY,
            in_array($field, self::LOAD, true) => self::KIND_LOAD,
            in_array($field, self::TEXT, true) => self::KIND_TEXT,
            default => throw new InvalidArgumentException(
                'bill field is not persistable: ' . $field
            ),
        };
    }

    public static function isPersistable(string $field): bool
    {
        return in_array($field, self::ALL, true);
    }

    /**
     * Canonical Field Policy (Requirement 8):
     * Factual bill fields directly verifiable from the physical/digital
     * document that a user may inspect and correct.
     */
    public const USER_EDITABLE = [
        ...self::DATES,
        ...self::ENERGY,
        ...self::MONEY,
        ...self::LOAD,
        'utility_provider',
        'tariff_category',
        'meter_type',
        self::SERVICE_NUMBER,
    ];

    /**
     * Read-only system and metadata fields. Direct manual edits are rejected;
     * these are managed through deterministic provenance and ingestion adapters.
     */
    public const READ_ONLY = [
        self::SOURCE,
        self::SOURCE_METADATA_JSON,
    ];

    public static function isEditable(string $field): bool
    {
        return in_array($field, self::USER_EDITABLE, true);
    }

    /**
     * Canonicalize + validate one field value against its storage kind.
     * null passes through untouched (NULL = unknown, §15); an invalid
     * value is REJECTED (never coerced, never truncated, never zeroed).
     */
    public static function validate(string $field, mixed $value): ?string
    {
        $kind = self::kind($field);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                $field . ' must be null or a canonical decimal/date/text string (never ' . get_debug_type($value) . ').'
            );
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($field . ' must be null or a non-empty string.');
        }

        return match ($kind) {
            self::KIND_DATE => self::validateDate($field, $value),
            self::KIND_ENERGY => self::validateDecimal($field, $value, '/^-?\d{1,11}(\.\d{1,3})?$/', 3),
            self::KIND_MONEY => self::validateDecimal($field, $value, '/^-?\d{1,10}(\.\d{1,2})?$/', 2),
            self::KIND_LOAD => self::validateDecimal($field, $value, '/^\d{1,6}(\.\d{1,2})?$/', 2),
            self::KIND_TEXT => self::validateText($field, $value),
        };
    }

    /**
     * Field-level extraction confidence (0..1, at most 4 decimal places)
     * or NULL when the provider stated none — absence is NEVER filled in
     * from a document-level number (§8, §14).
     */
    public static function validateConfidence(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/', trim($value)) !== 1) {
            throw new InvalidArgumentException(
                $field . ' confidence must be null or a decimal between 0 and 1 with at most 4 decimal places.'
            );
        }

        return trim($value);
    }

    /**
     * Persistence-side chronology rule (§13): when both ends are known,
     * start must be on or before end. Reversed dates are REJECTED, never
     * silently reordered.
     */
    public static function assertChronology(?string $start, ?string $end): void
    {
        if ($start !== null && $end !== null && $start > $end) {
            throw new InvalidArgumentException(
                'billing_period_start must be on or before billing_period_end.'
            );
        }
    }

    private static function validateDate(string $field, string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field . ' must be a strict Y-m-d date string.');
        }

        return $value;
    }

    private static function validateDecimal(string $field, string $value, string $pattern, int $maxDecimals): string
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(
                $field . ' must be a decimal string with at most ' . $maxDecimals
                . ' decimal place(s), no exponent form, no silent rounding.'
            );
        }

        return $value;
    }

    private static function validateText(string $field, string $value): string
    {
        $max = self::MAX_TEXT_LENGTH[$field];

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException($field . ' must be null or a string of at most ' . $max . ' characters.');
        }

        return $value;
    }
}
