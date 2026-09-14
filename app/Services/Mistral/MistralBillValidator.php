<?php

declare(strict_types=1);

namespace App\Services\Mistral;

/**
 * Controlled Mistral OCR test — deterministic relationship validation.
 *
 * This validator NEVER modifies, repairs, or "corrects" model output.
 * It compares AI-extracted values with exact arithmetic relationships
 * and reports PASS / NOT_CHECKED / CONFLICT plus the compared values
 * so a CONFLICT is fully inspectable. Missing or non-numeric inputs
 * are NOT_CHECKED — never guessed into a result.
 *
 * Relationships validated (all from values the document itself
 * reported; none computed as a substitute for extraction):
 *   meter: present_meter_reading − previous_meter_reading = consumption_kwh
 *   solar: present_solar_reading − previous_solar_reading = solar_units_kwh
 *   net:   consumption_kwh − solar_units_kwh              = net_units_kwh
 */
final class MistralBillValidator
{
    public const PASS = 'PASS';
    public const NOT_CHECKED = 'NOT_CHECKED';
    public const CONFLICT = 'CONFLICT';

    /** Tolerance for float comparison of decimal bill figures. */
    private const EPSILON = 0.001;

    private function __construct()
    {
    }

    /**
     * @param array<mixed>|null $annotation decoded document_annotation
     * @return array{meter: array<string, mixed>, solar: array<string, mixed>, net: array<string, mixed>}
     */
    public static function validate(?array $annotation): array
    {
        $number = static function (string $section, string $key) use ($annotation): ?float {
            $value = $annotation[$section][$key] ?? null;

            return is_numeric($value) ? (float) $value : null;
        };

        return [
            'meter' => self::difference(
                $number('meter_evidence', 'previous_meter_reading'),
                $number('meter_evidence', 'present_meter_reading'),
                $number('consumption', 'consumption_kwh'),
                'previous_meter_reading',
                'present_meter_reading',
                'consumption_kwh',
            ),
            'solar' => self::difference(
                $number('solar', 'previous_solar_reading'),
                $number('solar', 'present_solar_reading'),
                $number('solar', 'solar_units_kwh'),
                'previous_solar_reading',
                'present_solar_reading',
                'solar_units_kwh',
            ),
            'net' => self::difference(
                $number('solar', 'solar_units_kwh'),
                $number('consumption', 'consumption_kwh'),
                $number('solar', 'net_units_kwh'),
                'consumption_kwh',
                'solar_units_kwh',
                'net_units_kwh',
            ),
        ];
    }

    /**
     * minuend − subtrahend = difference, checked against the extracted
     * value without ever replacing it.
     *
     * @return array<string, mixed>
     */
    private static function difference(
        ?float $subtrahend,
        ?float $minuend,
        ?float $extracted,
        string $subtrahendName,
        string $minuendName,
        string $extractedName
    ): array {
        if ($subtrahend === null || $minuend === null || $extracted === null) {
            return [
                'status' => self::NOT_CHECKED,
                'reason' => 'one or more values are missing or non-numeric',
                $subtrahendName => $subtrahend,
                $minuendName => $minuend,
                $extractedName => $extracted,
            ];
        }

        $expected = $minuend - $subtrahend;
        $result = [
            'status' => abs($expected - $extracted) <= self::EPSILON ? self::PASS : self::CONFLICT,
            $subtrahendName => $subtrahend,
            $minuendName => $minuend,
            $extractedName => $extracted,
            'expected' => $expected,
        ];

        if ($result['status'] === self::CONFLICT) {
            $result['reason'] = $extractedName . ' must equal '
                . $minuendName . ' - ' . $subtrahendName;
        }

        return $result;
    }
}
