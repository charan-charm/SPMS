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
     * @param array<mixed>|null $annotation decoded structured extraction
     *        (exactly the parsed document_annotation — no substitution,
     *        no fallback, no merge; each field is read from its one
     *        documented location)
     * @return array{meter: array<string, mixed>, solar: array<string, mixed>, net: array<string, mixed>}
     */
    public static function validate(?array $annotation): array
    {
        // The ONLY source for each validator input is the exact
        // structured-extraction location named below. consumption_kwh
        // can only ever come from consumption/consumption_kwh;
        // net_units_kwh can only ever come from solar/net_units_kwh.
        $number = static function (string $section, string $key) use ($annotation): ?float {
            $value = $annotation[$section][$key] ?? null;

            return is_numeric($value) ? (float) $value : null;
        };

        // Each operand is a [field-name, value] pair — names travel WITH
        // their values so a reported key can never describe another
        // field (the positional value/name misalignment that once made
        // solar_units=322 surface under the consumption_kwh label is
        // now structurally impossible).
        $field = static function (string $section, string $key) use ($number): array {
            return [$key, $number($section, $key)];
        };

        $solarUnitsValue = $number('solar', 'solar_export_units_kwh') ?? $number('solar', 'solar_units_kwh');

        return [
            'meter' => self::difference(
                $field('meter_evidence', 'previous_meter_reading'),
                $field('meter_evidence', 'present_meter_reading'),
                $field('consumption', 'consumption_kwh'),
            ),
            'solar' => self::difference(
                $field('solar', 'previous_solar_reading'),
                $field('solar', 'present_solar_reading'),
                ['solar_units_kwh', $solarUnitsValue],
            ),
            'net' => self::difference(
                ['solar_units_kwh', $solarUnitsValue],
                $field('consumption', 'consumption_kwh'),
                $field('solar', 'net_units_kwh'),
            ),
        ];
    }

    /**
     * minuend − subtrahend = extracted-difference.
     *
     * @param array{0: string, 1: float|null} $subtrahend [field name, value]
     * @param array{0: string, 1: float|null} $minuend    [field name, value]
     * @param array{0: string, 1: float|null} $extracted  [field name, value]
     * @return array<string, mixed>
     */
    private static function difference(array $subtrahend, array $minuend, array $extracted): array
    {
        [$subtrahendName, $subtrahendValue] = $subtrahend;
        [$minuendName, $minuendValue] = $minuend;
        [$extractedName, $extractedValue] = $extracted;

        if ($subtrahendValue === null || $minuendValue === null || $extractedValue === null) {
            return [
                'status' => self::NOT_CHECKED,
                'reason' => 'one or more values are missing or non-numeric',
                $subtrahendName => $subtrahendValue,
                $minuendName => $minuendValue,
                $extractedName => $extractedValue,
            ];
        }

        $expected = $minuendValue - $subtrahendValue;

        $result = [
            'status' => abs($expected - $extractedValue) <= self::EPSILON ? self::PASS : self::CONFLICT,
            $subtrahendName => $subtrahendValue,
            $minuendName => $minuendValue,
            $extractedName => $extractedValue,
            'expected' => $expected,
        ];

        if ($result['status'] === self::CONFLICT) {
            $result['reason'] = $extractedName . ' must equal '
                . $minuendName . ' - ' . $subtrahendName;
        }

        return $result;
    }
}
