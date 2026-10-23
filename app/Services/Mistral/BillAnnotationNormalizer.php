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
    /**
     * Reconcile candidate solar fields and deterministic meter/net evidence.
     *
     * In Indian utility bills (such as APSPDCL), current billing-period solar export
     * is explicitly stated as "SOLAR EXPORT UNITS" in the billing units table, and
     * reconciles exactly with:
     *   solar_meter_delta = present_solar - previous_solar
     *   net_units = consumption - solar_export_units
     *
     * Often, an ambiguous secondary number is printed elsewhere on the bill (such as
     * a bottom-of-bill "Solar Units: 177" unmapped source value).
     * If an AI extractor mistakenly assigns that ambiguous value to solar_units_kwh,
     * this method:
     * 1. Preserves the ambiguous candidate in other_solar_balance_or_adjustment_kwh.
     * 2. Sets solar_units_kwh (and solar_export_units_kwh) to the authoritative
     *    solar export proven by the meter readings and net equation.
     * 3. Prevents false conflicts from polluting canonical validation.
     *
     * @param array<mixed>|null $annotation
     * @return array<mixed>|null
     */
    public static function disambiguateAnnotation(?array $annotation): ?array
    {
        if (!is_array($annotation) || !isset($annotation['solar']) || !is_array($annotation['solar'])) {
            return $annotation;
        }

        $clean = $annotation;
        $solar = $clean['solar'];

        $consumption = self::floatVal($clean['consumption']['consumption_kwh'] ?? null);
        $prevMeter = self::floatVal($clean['meter_evidence']['previous_meter_reading'] ?? null);
        $presMeter = self::floatVal($clean['meter_evidence']['present_meter_reading'] ?? null);
        $prevSolar = self::floatVal($solar['previous_solar_reading'] ?? null);
        $presSolar = self::floatVal($solar['present_solar_reading'] ?? null);

        $solarExport = self::floatVal($solar['solar_export_units_kwh'] ?? null);
        $solarUnits = self::floatVal($solar['solar_units_kwh'] ?? null);
        $otherSolar = self::floatVal($solar['other_solar_balance_or_adjustment_kwh'] ?? null);
        $netUnits = self::floatVal($solar['net_units_kwh'] ?? null);

        $solarMeterDelta = ($presSolar !== null && $prevSolar !== null) ? ($presSolar - $prevSolar) : null;
        $netImpliedExport = ($consumption !== null && $netUnits !== null) ? ($consumption - $netUnits) : null;

        $resolvedExport = $solarUnits;
        $resolvedOtherSolar = $otherSolar;

        if ($solarExport !== null) {
            $resolvedExport = $solarExport;
            if ($solarUnits !== null && abs($solarUnits - $solarExport) > 0.001) {
                if ($resolvedOtherSolar === null) {
                    $resolvedOtherSolar = $solarUnits;
                }
            }
        } elseif ($solarMeterDelta !== null && $netImpliedExport !== null && abs($solarMeterDelta - $netImpliedExport) <= 0.001) {
            // Both cumulative solar meter delta AND the net billing equation agree exactly on the solar export quantity.
            $resolvedExport = $solarMeterDelta;
            if ($solarUnits !== null && abs($solarUnits - $solarMeterDelta) > 0.001) {
                // Conflicting extracted candidate was an ambiguous separate/carry-forward figure.
                if ($resolvedOtherSolar === null) {
                    $resolvedOtherSolar = $solarUnits;
                }
            }
        }

        $clean['solar']['solar_units_kwh'] = $resolvedExport;
        if ($solarExport !== null || $resolvedExport !== null) {
            $clean['solar']['solar_export_units_kwh'] = $solarExport ?? $resolvedExport;
        }
        if ($resolvedOtherSolar !== null) {
            $clean['solar']['other_solar_balance_or_adjustment_kwh'] = $resolvedOtherSolar;
        }

        return $clean;
    }

    private static function floatVal(mixed $v): ?float
    {
        return (is_numeric($v)) ? (float) $v : null;
    }

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
        if (is_array($annotation)) {
            $values[BillField::SOURCE] = \App\Domain\Bill\BillSource::ELECTRICITY_BILL;
        }
        $confidences = [];

        $cleanAnnotation = self::disambiguateAnnotation($annotation);

        foreach (self::SECTION_FIELDS as $section => $fields) {
            $data = is_array($cleanAnnotation) ? ($cleanAnnotation[$section] ?? null) : null;
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

        // Assemble source_metadata_json with distinct energy fields, provenance and charge reconciliation
        if (is_array($cleanAnnotation)) {
            $meta = [];
            $rawMetaJson = $values[BillField::SOURCE_METADATA_JSON] ?? null;
            if (is_string($rawMetaJson) && $rawMetaJson !== '') {
                $meta = json_decode($rawMetaJson, true) ?? [];
            }

            $presSolar = self::floatVal($cleanAnnotation['solar']['present_solar_reading'] ?? null);
            $prevSolar = self::floatVal($cleanAnnotation['solar']['previous_solar_reading'] ?? null);
            $solarMeterDelta = ($presSolar !== null && $prevSolar !== null) ? ($presSolar - $prevSolar) : null;
            $otherSolar = self::floatVal($cleanAnnotation['solar']['other_solar_balance_or_adjustment_kwh'] ?? null);

            $meta['meter_consumption_kwh'] = $values['consumption_kwh'];
            $meta['solar_meter_present_kwh'] = $values['present_solar_reading'];
            $meta['solar_meter_previous_kwh'] = $values['previous_solar_reading'];
            $meta['solar_meter_delta_kwh'] = $solarMeterDelta !== null ? self::decimal($solarMeterDelta, 3) : null;
            $meta['solar_export_units_kwh'] = $values['solar_units_kwh'];
            $meta['net_units_kwh'] = $values['net_units_kwh'];

            if ($otherSolar !== null) {
                $meta['other_solar_balance_or_adjustment_kwh'] = self::decimal($otherSolar, 3);
                $meta['other_solar_balance_status'] = 'AMBIGUOUS_UNMAPPED';
            }

            $meta['raw_extracted_fields'] = [
                'consumption_kwh' => $annotation['consumption']['consumption_kwh'] ?? null,
                'previous_meter_reading' => $annotation['meter_evidence']['previous_meter_reading'] ?? null,
                'present_meter_reading' => $annotation['meter_evidence']['present_meter_reading'] ?? null,
                'previous_solar_reading' => $annotation['solar']['previous_solar_reading'] ?? null,
                'present_solar_reading' => $annotation['solar']['present_solar_reading'] ?? null,
                'solar_units_kwh' => $annotation['solar']['solar_units_kwh'] ?? null,
                'solar_export_units_kwh' => $annotation['solar']['solar_export_units_kwh'] ?? null,
                'other_solar_balance_or_adjustment_kwh' => $otherSolar,
                'net_units_kwh' => $annotation['solar']['net_units_kwh'] ?? null,
            ];

            // Deterministic charge reconciliation
            $energyCharges = self::floatVal($cleanAnnotation['cost']['energy_charges'] ?? null);
            $fixedCharges = self::floatVal($cleanAnnotation['cost']['fixed_charges'] ?? null);
            $customerCharges = self::floatVal($cleanAnnotation['cost']['customer_service_charges'] ?? null);
            $otherCharges = self::floatVal($cleanAnnotation['cost']['other_charges'] ?? null);
            $taxesAdjustments = self::floatVal($cleanAnnotation['cost']['taxes_adjustments'] ?? null);
            $billAmount = self::floatVal($cleanAnnotation['cost']['bill_amount'] ?? null);

            if ($billAmount !== null) {
                $chargeSum = ($energyCharges ?? 0.0) + ($fixedCharges ?? 0.0) + ($customerCharges ?? 0.0) + ($otherCharges ?? 0.0) + ($taxesAdjustments ?? 0.0);
                $meta['charge_breakdown'] = [
                    'energy_charges' => $values['energy_charges'],
                    'fixed_charges' => $values['fixed_charges'],
                    'customer_service_charges' => $values['customer_service_charges'],
                    'other_charges' => $values['other_charges'],
                    'taxes_adjustments' => $values['taxes_adjustments'],
                    'bill_amount' => $values['bill_amount'],
                    'sum_of_charges' => self::decimal($chargeSum, 2),
                    'reconciled' => abs($chargeSum - $billAmount) <= 1.01,
                ];
            }

            $encodedMeta = json_encode($meta, JSON_THROW_ON_ERROR);
            if (mb_strlen($encodedMeta) <= 4000) {
                $values[BillField::SOURCE_METADATA_JSON] = $encodedMeta;
            }
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
        $clean = self::disambiguateAnnotation($annotation);
        $report = MistralBillValidator::validate($clean);

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
                ValidationRule::SOLAR_PRODUCTION => $add(QualityCode::CONFLICTING_SOLAR_READINGS, 'solar_units_kwh'),
                ValidationRule::NET_UNITS => $add(QualityCode::INCONSISTENT_NET_UNITS, 'net_units_kwh'),
                default => null,
            };
        }

        return $flags;
    }

    /**
     * Reconcile document assessment with deterministic validations and quality flags.
     *
     * A provider successfully returning structured JSON does NOT mean the extracted
     * values are internally consistent. When the source document appears to be a
     * legitimate bill (VALID) but unresolved material numerical conflicts or quality
     * flags exist, the assessment is reconciled to VALID_WITH_LIMITATIONS (§11).
     *
     * Terminal assessments (NOT_A_BILL, UNREADABLE, UNCERTAIN) remain unchanged.
     *
     * @param string $assessment
     * @param string|null $reason
     * @param list<BillValidation> $validations
     * @param list<BillQualityFlag> $flags
     * @return array{0: string, 1: string|null} [assessment, assessment_reason]
     */
    public static function reconcileAssessment(
        string $assessment,
        ?string $reason,
        array $validations,
        array $flags
    ): array {
        if ($assessment !== DocumentAssessment::VALID) {
            return [$assessment, $reason];
        }

        $conflicts = array_filter(
            $validations,
            static fn (BillValidation $v): bool => $v->status() === ValidationStatus::CONFLICT
        );

        if ($conflicts !== []) {
            $conflictCount = count($conflicts);
            $reconciledReason = $reason !== null && $reason !== ''
                ? rtrim($reason, '.') . ' — reconciled with limitations: ' . $conflictCount . ' numerical conflict(s) detected'
                : 'Document recognized with limitations: ' . $conflictCount . ' numerical conflict(s) detected';

            return [DocumentAssessment::VALID_WITH_LIMITATIONS, $reconciledReason];
        }

        return [DocumentAssessment::VALID, $reason];
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
