<?php

declare(strict_types=1);

namespace App\Services\Mistral;

use App\Domain\Bill\BillField;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillValidation;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\QualityCode;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use DateTimeImmutable;

/**
 * B3 — The Mistral annotation → SPMS normalized-shape mapper (docs/10 D-069).
 *
 * Anti-corruption layer: the ONLY place that knows both the provider's
 * annotation section layout and the B1 persisted vocabulary. Nothing
 * provider-shaped flows onward: the workflow receives canonical B1
 * values (decimal strings or NULL), provenance confidences, a
 * controlled assessment status, deterministic BillValidation objects
 * and quality flags — all validated by the B1 domain on the way in.
 *
 * Hard rules:
 * - Extraction never calculates and this mapper never "fixes": a
 *   provider-stated +177 for a validator-expected -177 survives
 *   verbatim and the CONFLICT is reported (§11/§15 of the B3 brief).
 * - Unknown sections/keys (a stray total_amount, a customer name, a
 *   QR payload — anything) are structurally DROPPED: mapping happens
 *   only through the explicit section→field table below, so data not
 *   in the B1 schema cannot reach persistence through any annotation
 *   shape (§24).
 * - Absent, non-numeric, or non-finite numbers become NULL — never 0,
 *   never guessed, never truncated into existence.
 * - Field confidence is the enclosing SECTION's provider-stated
 *   confidence applied only to fields that actually have values — the
 *   document-level confidence is never fanned out (§8/§14 of B1's
 *   invariant); sections that state no confidence yield no confidence.
 * - Reversed billing periods are discarded to NULL (flagged), never
 *   silently reordered at this layer beyond what the prompt already
 *   required — a still-reversed pair means the model violated its
 *   contract, which is review evidence, not something to hide.
 */
final class BillAnnotationNormalizer
{
    /** section => [bill field => [extraction key, kind]] */
    private const SECTION_FIELDS = [
        'billing_period' => [
            'billing_period_start' => ['start', 'date'],
            'billing_period_end' => ['end', 'date'],
        ],
        'consumption' => [
            'consumption_kwh' => ['consumption_kwh', 'energy'],
        ],
        'meter_evidence' => [
            'previous_meter_reading' => ['previous_meter_reading', 'energy'],
            'present_meter_reading' => ['present_meter_reading', 'energy'],
        ],
        'solar' => [
            'previous_solar_reading' => ['previous_solar_reading', 'energy'],
            'present_solar_reading' => ['present_solar_reading', 'energy'],
            'solar_units_kwh' => ['solar_units_kwh', 'energy'],
            'net_units_kwh' => ['net_units_kwh', 'energy'],
        ],
        'cost' => [
            'bill_amount' => ['bill_amount', 'money'],
            'arrears' => ['arrears', 'money'],
            'total_due' => ['total_due', 'money'],
            'energy_charges' => ['energy_charges', 'money'],
            'fixed_charges' => ['fixed_charges', 'money'],
            'customer_service_charges' => ['customer_service_charges', 'money'],
            'other_charges' => ['other_charges', 'money'],
            'taxes_adjustments' => ['taxes_adjustments', 'money'],
        ],
        'context' => [
            'utility_provider' => ['utility_provider', 'text'],
            'tariff_category' => ['tariff_category', 'text'],
            'meter_type' => ['meter_type', 'text'],
            'contracted_load' => ['contracted_load', 'load'],
            // D-071: captured verbatim (text-cleaned only) into the immutable
            // draft; ownership canonicalization happens later, in the
            // persistence layer — never here, so the snapshot stays honest.
            'service_number' => ['service_number', 'text'],
        ],
    ];

    /**
     * @param array<mixed>|null $annotation exactly the parsed document_annotation
     * @return array{
     *     assessment: string,
     *     assessment_reason: string|null,
     *     document_confidence: string|null,
     *     values: array<string, string|null>,
     *     confidences: array<string, string|null>
     * }
     */
    public static function normalize(?array $annotation): array
    {
        $assessment = DocumentAssessment::UNCERTAIN;
        $reason = 'structured annotation absent or undecodable';
        $documentConfidence = null;

        if (is_array($annotation)) {
            $meta = $annotation['document_assessment'] ?? null;
            if (is_array($meta)) {
                $status = $meta['status'] ?? null;
                if (is_string($status) && DocumentAssessment::isValid($status)) {
                    $assessment = $status;
                    $reason = self::sanitizedReason($meta['reason'] ?? null);
                } else {
                    $reason = 'document_assessment.status missing or outside the controlled vocabulary';
                }

                $documentConfidence = self::confidence($meta['confidence'] ?? null);
            } else {
                $reason = 'document_assessment section absent from the annotation';
            }
        }

        $values = array_fill_keys(BillField::ALL, null);
        $confidences = [];

        foreach (self::SECTION_FIELDS as $section => $fields) {
            $data = $annotation[$section] ?? null;
            if (!is_array($data)) {
                continue; // absent section: every member stays NULL honestly
            }

            $sectionConfidence = self::confidence($data['confidence'] ?? null);

            foreach ($fields as $field => [$key, $kind]) {
                $value = self::cast($data[$key] ?? null, $kind);
                if ($value === null) {
                    continue; // stays NULL — no defaulting
                }

                // Text caps: an over-long label is untrusted data, not a
                // value to truncate (BillField would reject it at draft
                // construction; the honest outcome here is NULL).
                if (is_string($value) && !self::fitsSchema($field, $value)) {
                    continue;
                }

                $values[$field] = $value;
                if ($sectionConfidence !== null) {
                    $confidences[$field] = $sectionConfidence;
                }
            }
        }

        // Chronology safety (B1 §13): the pair is either ordered or gone.
        $start = $values['billing_period_start'];
        $end = $values['billing_period_end'];
        if ($start !== null && $end !== null && $start > $end) {
            $values['billing_period_start'] = null;
            $values['billing_period_end'] = null;
            unset($confidences['billing_period_start'], $confidences['billing_period_end']);
        }

        return [
            'assessment' => $assessment,
            'assessment_reason' => $reason,
            'document_confidence' => $documentConfidence,
            'values' => $values,
            'confidences' => $confidences,
        ];
    }

    /**
     * Run the ratified deterministic validator and convert its report to
     * B1 BillValidation value objects (operands formatted to the stored
     * energy precision; signs kept exactly).
     *
     * @param array<mixed>|null $annotation
     * @return list<BillValidation>
     */
    public static function validations(?array $annotation): array
    {
        $report = MistralBillValidator::validate($annotation);

        $extractedKey = [
            ValidationRule::METER_CONSUMPTION => 'consumption_kwh',
            ValidationRule::SOLAR_PRODUCTION => 'solar_units_kwh',
            ValidationRule::NET_UNITS => 'net_units_kwh',
        ];

        $out = [];
        foreach ([
            ValidationRule::METER_CONSUMPTION => 'meter',
            ValidationRule::SOLAR_PRODUCTION => 'solar',
            ValidationRule::NET_UNITS => 'net',
        ] as $rule => $reportKey) {
            $check = $report[$reportKey];
            $status = $check['status'];
            $expected = self::decimal3($check['expected'] ?? null);
            $extracted = self::decimal3($check[$extractedKey[$rule]] ?? null);

            if ($status === ValidationStatus::NOT_CHECKED) {
                // B1 VO shape: at most one operand known, reason required.
                $out[] = BillValidation::create(
                    $rule,
                    ValidationStatus::NOT_CHECKED,
                    null,
                    $extracted,
                    ValidationStatus::REASON_MISSING_OPERANDS
                );

                continue;
            }

            if ($expected === null || $extracted === null) {
                // Defensive: never fabricate a comparison that cannot be shown.
                $out[] = BillValidation::create(
                    $rule,
                    ValidationStatus::NOT_CHECKED,
                    $expected,
                    null,
                    ValidationStatus::REASON_MISSING_OPERANDS
                );

                continue;
            }

            // PASS or CONFLICT — recorded exactly as the validator decided.
            $out[] = BillValidation::create($rule, $status, $expected, $extracted);
        }

        return $out;
    }

    /**
     * Conservative, controlled-vocabulary quality derivation (B3 §16).
     * Only deterministic gaps/conflicts observed in the normalized data
     * are flagged; nothing free-form. Missing SOLAR data is deliberately
     * NOT flagged: a non-solar bill is legitimately solar-less, and the
     * SPMS vocabulary keeps flags for genuinely review-relevant facts.
     *
     * @param array{values: array<string,string|null>, ...} $normalized
     * @param list<BillValidation> $validations
     * @return list<BillQualityFlag>
     */
    public static function qualityFlags(array $normalized, array $validations): array
    {
        // D-071: a terminal assessment (NOT_A_BILL / UNREADABLE /
        // UNCERTAIN) adopts no values at all — "this bill is missing its
        // consumption" would be a statement about data that was never
        // claimed. For those documents the assessment itself is the
        // finding; data-gap flags only describe adopted bills.
        if (!DocumentAssessment::isUsable($normalized['assessment'])) {
            return [];
        }

        $values = $normalized['values'];

        $flags = [];
        $add = static function (string $code, ?string $field) use (&$flags): void {
            $flags[] = BillQualityFlag::create($code, $field);
        };

        if ($values['consumption_kwh'] === null) {
            $add(QualityCode::MISSING_CONSUMPTION, 'consumption_kwh');
        }

        if ($values['billing_period_start'] === null || $values['billing_period_end'] === null) {
            $add(QualityCode::MISSING_BILLING_PERIOD, $values['billing_period_start'] === null
                ? 'billing_period_start'
                : 'billing_period_end');
        }

        if ($values['previous_meter_reading'] === null && $values['present_meter_reading'] === null) {
            $add(QualityCode::MISSING_METER_READINGS, 'previous_meter_reading');
        }

        foreach ($validations as $validation) {
            if ($validation->status() !== ValidationStatus::CONFLICT) {
                continue;
            }

            match ($validation->rule()) {
                ValidationRule::METER_CONSUMPTION => $add(QualityCode::CONFLICTING_METER_READINGS, 'consumption_kwh'),
                ValidationRule::NET_UNITS => $add(QualityCode::INCONSISTENT_NET_UNITS, 'net_units_kwh'),
                default => null,
            };
        }

        return $flags;
    }

    // ------------------------------------------------------------------

    private static function cast(mixed $raw, string $kind): ?string
    {
        return match ($kind) {
            'energy' => self::decimal($raw, 3),
            'money' => self::decimal($raw, 2),
            'load' => self::decimal($raw, 2, nonNegative: true),
            'date' => self::date($raw),
            'text' => self::text($raw),
            default => null,
        };
    }

    private static function decimal(mixed $raw, int $scale, bool $nonNegative = false): ?string
    {
        if (is_bool($raw) || $raw === null || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        if (!is_finite($value)) {
            return null;
        }

        if ($nonNegative && $value < 0) {
            return null; // storage type cannot hold it; honest NULL over coercion
        }

        return number_format($value, $scale, '.', '');
    }

    /** Validator floats to the persisted energy precision (3 dp). */
    private static function decimal3(mixed $raw): ?string
    {
        return self::decimal($raw, 3);
    }

    private static function confidence(mixed $raw): ?string
    {
        if (is_bool($raw) || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        if (!is_finite($value) || $value < 0 || $value > 1) {
            return null; // out-of-range is treated as absent, never clamped
        }

        return number_format($value, 4, '.', '');
    }

    private static function date(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));

        return $date === false ? null : $date->format('Y-m-d');
    }

    private static function text(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw) ?? '');

        return $text === '' ? null : $text;
    }

    /** Provider-supplied reason, reduced to safe printable text. */
    private static function sanitizedReason(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        if ($clean === '' || preg_match('/^[\x20-\x7E]+$/', $clean) !== 1) {
            return null;
        }

        return substr($clean, 0, 255);
    }

    /** Does the value fit the B1 column shape (BillField caps)? */
    private static function fitsSchema(string $field, string $value): bool
    {
        try {
            BillField::validate($field, $value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
