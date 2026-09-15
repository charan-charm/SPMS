<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bill\BillField;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\QualityCode;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use App\Services\Mistral\BillAnnotationNormalizer;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * B3 — Annotation normalizer checks (docs/10 D-069).
 *
 * Pure mapper tests over the EXACT shapes the ratified live-bill round
 * trips produced (synthetic values only — no real bill content, no
 * network). Covers: canonical decimal/date casting, the three distinct
 * cost fields with total_amount collapse refused, sign preservation,
 * section-confidence-to-field mapping (never document-confidence fan-out),
 * reversed-date and oversized-text discards, and the controlled
 * validation/quality derivations.
 */
final class BillExtractionNormalizerTest
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function liveShape(array $overrides = []): array
    {
        $annotation = [
            'document_assessment' => ['status' => 'VALID', 'reason' => 'clear bill', 'confidence' => 0.99],
            'billing_period' => ['start' => '2026-08-01', 'end' => '2026-09-01', 'confidence' => 0.95],
            'consumption' => ['consumption_kwh' => 145, 'confidence' => 0.98],
            'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460, 'confidence' => 0.99],
            'solar' => [
                'previous_solar_reading' => 1878,
                'present_solar_reading' => 2200,
                'solar_units_kwh' => 322,
                'net_units_kwh' => -177,
                'confidence' => 0.97,
            ],
            'cost' => [
                'bill_amount' => null,
                'arrears' => null,
                'total_due' => null,
                'energy_charges' => null,
                'fixed_charges' => 30,
                'customer_service_charges' => 25,
                'other_charges' => null,
                'taxes_adjustments' => null,
                'confidence' => 0.9,
            ],
            'context' => [
                'utility_provider' => 'DEMO GRID UTILITY LTD',
                'tariff_category' => 'DOMESTIC (A)',
                'meter_type' => 'SMART SOLAR',
                'contracted_load' => '3',
                'confidence' => 0.95,
            ],
        ];

        foreach ($overrides as $section => $value) {
            $annotation[$section] = $value;
        }

        return $annotation;
    }

    public static function run(): array
    {
        return [
            TestResult::check('clean live-shape annotation normalizes to canonical B1 values with sign preserved', function (): void {
                $n = BillAnnotationNormalizer::normalize(self::liveShape());

                Assert::same(DocumentAssessment::VALID, $n['assessment']);
                Assert::same('clear bill', $n['assessment_reason']);
                Assert::same('0.9900', $n['document_confidence']);

                $v = $n['values'];
                Assert::same('2026-08-01', $v['billing_period_start']);
                Assert::same('2026-09-01', $v['billing_period_end']);
                Assert::same('145.000', $v['consumption_kwh']);
                Assert::same('1315.000', $v['previous_meter_reading']);
                Assert::same('1460.000', $v['present_meter_reading']);
                Assert::same('1878.000', $v['previous_solar_reading']);
                Assert::same('2200.000', $v['present_solar_reading']);
                Assert::same('322.000', $v['solar_units_kwh']);
                Assert::same('-177.000', $v['net_units_kwh'], 'negative net preserved exactly');
                Assert::same('30.00', $v['fixed_charges']);
                Assert::same('25.00', $v['customer_service_charges']);
                Assert::same('3.00', $v['contracted_load']);
                Assert::same(null, $v['energy_charges'], 'absent stays NULL');
                Assert::same(null, $v['total_due']);
                Assert::same('DEMO GRID UTILITY LTD', $v['utility_provider']);

                // every key is a persistable B1 field, nothing else
                foreach (array_keys($v) as $field) {
                    Assert::true(BillField::isPersistable($field), $field);
                }
            }),

            TestResult::check('field confidence comes from its own section — never the document number', function (): void {
                $n = BillAnnotationNormalizer::normalize(self::liveShape());

                Assert::same('0.9800', $n['confidences']['consumption_kwh'], 'section confidence applied to its field');
                Assert::same('0.9700', $n['confidences']['net_units_kwh']);
                Assert::same('0.9000', $n['confidences']['fixed_charges']);
                Assert::false(array_key_exists('energy_charges', $n['confidences']), 'no confidence for a value that does not exist');
                Assert::same('0.9900', $n['document_confidence'], 'document confidence lives only on its own field...');
                Assert::true(
                    array_unique($n['confidences']) !== [ '0.9900' ],
                    '...and is demonstrably NOT the section numbers'
                );

                // A section that states no confidence yields none (not the doc-level 0.99).
                $n2 = BillAnnotationNormalizer::normalize(self::liveShape([
                    'consumption' => ['consumption_kwh' => 145],
                ]));
                Assert::false(array_key_exists('consumption_kwh', $n2['confidences']), 'no section confidence => no field confidence (§8)');
            }),

            TestResult::check('the three cost concepts stay three fields; total_amount is structurally ignored', function (): void {
                // The test-02 physical bill shape.
                $n = BillAnnotationNormalizer::normalize(self::liveShape([
                    'cost' => [
                        'bill_amount' => 180,
                        'arrears' => -105,
                        'total_due' => 75,
                        'energy_charges' => null,
                        'fixed_charges' => 30,
                        'customer_service_charges' => 25,
                        'other_charges' => 45,
                        'taxes_adjustments' => 108.56,
                        'confidence' => 0.85,
                    ],
                ]));

                Assert::same('180.00', $n['values']['bill_amount']);
                Assert::same('-105.00', $n['values']['arrears'], 'arrears credit sign preserved');
                Assert::same('75.00', $n['values']['total_due'], 'total_due stored as its own extracted figure');
                Assert::same('45.00', $n['values']['other_charges']);
                Assert::same('108.56', $n['values']['taxes_adjustments'], 'paise precision preserved');

                // A stray provider "total_amount" (legacy shape) cannot leak:
                // it is not in the field map, and bill_amount stays NULL.
                $stray = self::liveShape();
                $stray['cost']['total_amount'] = 99999.99;
                unset($stray['cost']['bill_amount']);
                $n2 = BillAnnotationNormalizer::normalize($stray);
                Assert::same(null, $n2['values']['bill_amount'], 'total_amount is never collapsed into bill_amount (§9/N)');
                Assert::false(array_key_exists('total_amount', $n2['values']), 'no schema field for it exists at all');
            }),

            TestResult::check('provider garbage (PII-ish or raw-text keys) is dropped by the closed field map', function (): void {
                $junk = self::liveShape();
                $junk['customer_name'] = 'SOME REAL PERSON';
                $junk['aadhaar_number'] = '123412341234';
                $junk['mobile'] = '9876500000';
                $junk['raw_ocr'] = 'RAWMARKER-NEVER-STORE';
                $junk['cost']['bill_number'] = 'B/2026/001';

                $n = BillAnnotationNormalizer::normalize($junk);

                $serialized = json_encode($n) ?: '';
                foreach (['SOME REAL PERSON', '123412341234', '9876500000', 'RAWMARKER-NEVER-STORE', 'B/2026/001'] as $forbidden) {
                    Assert::notContains($forbidden, $serialized);
                }
            }),

            TestResult::check('casting is honest: invalid numbers, oversized text, bogus dates and negative load become NULL', function (): void {
                $n = BillAnnotationNormalizer::normalize([
                    'document_assessment' => ['status' => 'VALID_WITH_LIMITATIONS', 'reason' => "weird\x00control\x07chars", 'confidence' => 1.5],
                    'billing_period' => ['start' => '2026-8-1', 'end' => '01.09.26', 'confidence' => null],
                    'consumption' => ['consumption_kwh' => 'not-a-number', 'confidence' => 0.5],
                    'meter_evidence' => ['previous_meter_reading' => true, 'present_meter_reading' => 1460, 'confidence' => 0.5],
                    'solar' => ['net_units_kwh' => 0, 'confidence' => 0.5],
                    'cost' => ['bill_amount' => '1e2', 'arrears' => 0, 'total_due' => false, 'confidence' => 0.5],
                    'context' => [
                        'utility_provider' => str_repeat('x', 121),
                        'tariff_category' => '   ',
                        'meter_type' => 'PREPAID',
                        'contracted_load' => '-3',
                        'confidence' => 0.5,
                    ],
                ]);

                Assert::same(DocumentAssessment::VALID_WITH_LIMITATIONS, $n['assessment']);
                Assert::same('weird control chars', $n['assessment_reason'], 'control characters neutralized');
                Assert::same(null, $n['document_confidence'], 'out-of-range confidence is dropped, never clamped');
                Assert::same('2026-08-01', $n['values']['billing_period_start'], 'valid one-sided date kept and canonicalized');
                Assert::same(null, $n['values']['billing_period_end'], 'DD.MM.YY is NOT guessed (§13: interpretation already failed)');
                Assert::same(null, $n['values']['consumption_kwh'], 'non-numeric becomes NULL');
                Assert::same(null, $n['values']['previous_meter_reading'], 'bool is never coerced to a number');
                Assert::same('0.000', $n['values']['net_units_kwh'], 'an explicit zero stays an explicit zero');
                Assert::same('100.00', $n['values']['bill_amount'], 'numeric-string exponent canonicalized to decimal (parsed value, not guessed)');
                Assert::same('0.00', $n['values']['arrears']);
                Assert::same(null, $n['values']['total_due']);
                Assert::same(null, $n['values']['utility_provider'], 'over-cap text is dropped, not truncated');
                Assert::same(null, $n['values']['tariff_category'], 'blank text is NULL');
                Assert::same('PREPAID', $n['values']['meter_type']);
                Assert::same(null, $n['values']['contracted_load'], 'negative load is untrusted, dropped');

                foreach ($n['confidences'] as $field => $c) {
                    Assert::same('0.5000', $c);
                }
            }),

            TestResult::check('reversed period pair is discarded (never reordered here), chronology-safe', function (): void {
                $n = BillAnnotationNormalizer::normalize(self::liveShape([
                    'billing_period' => ['start' => '2026-09-01', 'end' => '2026-08-01', 'confidence' => 0.9],
                ]));

                Assert::same(null, $n['values']['billing_period_start']);
                Assert::same(null, $n['values']['billing_period_end']);
                Assert::false(array_key_exists('billing_period_start', $n['confidences']));
            }),

            TestResult::check('absent or out-of-vocabulary assessment normalizes honestly to UNCERTAIN', function (): void {
                $n = BillAnnotationNormalizer::normalize(null);
                Assert::same(DocumentAssessment::UNCERTAIN, $n['assessment']);
                Assert::true($n['assessment_reason'] !== null && $n['assessment_reason'] !== '');
                foreach (BillField::ALL as $field) {
                    Assert::same(null, $n['values'][$field]);
                }

                $n2 = BillAnnotationNormalizer::normalize(['document_assessment' => ['status' => 'PROBABLY_FINE', 'confidence' => 0.9]]);
                Assert::same(DocumentAssessment::UNCERTAIN, $n2['assessment'], 'invented statuses cannot pass through');
            }),

            TestResult::check('validations: PASS rows carry expected and extracted at 3 dp (H, J)', function (): void {
                $validations = BillAnnotationNormalizer::validations(self::liveShape());
                Assert::same(3, count($validations));

                $byRule = [];
                foreach ($validations as $v) {
                    $byRule[$v->rule()] = $v;
                }

                Assert::same(ValidationStatus::PASS, $byRule[ValidationRule::METER_CONSUMPTION]->status());
                Assert::same('145.000', $byRule[ValidationRule::METER_CONSUMPTION]->expectedValue());
                Assert::same('145.000', $byRule[ValidationRule::METER_CONSUMPTION]->extractedValue());
                Assert::same(ValidationStatus::PASS, $byRule[ValidationRule::SOLAR_PRODUCTION]->status(), '2200-1878=322');
                Assert::same(ValidationStatus::PASS, $byRule[ValidationRule::NET_UNITS]->status(), '145-322=-177');
            }),

            TestResult::check('validations: +177 vs expected -177 is CONFLICT stored with BOTH values (L, I/K)', function (): void {
                $validations = BillAnnotationNormalizer::validations(self::liveShape([
                    'solar' => [
                        'previous_solar_reading' => 1878,
                        'present_solar_reading' => 2200,
                        'solar_units_kwh' => 322,
                        'net_units_kwh' => 177, // the observed physical-bill sign flip
                        'confidence' => 0.97,
                    ],
                ]));

                $net = null;
                foreach ($validations as $v) {
                    if ($v->rule() === ValidationRule::NET_UNITS) {
                        $net = $v;
                    }
                }
                Assert::true($net instanceof \App\Domain\Bill\BillValidation);
                Assert::same(ValidationStatus::CONFLICT, $net->status());
                Assert::same('-177.000', $net->expectedValue(), 'expected keeps the computed sign');
                Assert::same('177.000', $net->extractedValue(), 'the AI value is stored as extracted — never rewritten (§11)');

                // A meter CONFLICT (extracted 200 vs 145 expected) likewise.
                $vs = BillAnnotationNormalizer::validations(self::liveShape([
                    'consumption' => ['consumption_kwh' => 200, 'confidence' => 0.98],
                ]));
                foreach ($vs as $v) {
                    if ($v->rule() === ValidationRule::METER_CONSUMPTION) {
                        Assert::same(ValidationStatus::CONFLICT, $v->status());
                        Assert::same('145.000', $v->expectedValue());
                        Assert::same('200.000', $v->extractedValue());
                    }
                }
            }),

            TestResult::check('validations: missing operands produce NOT_CHECKED with a reason, never a guess (F)', function (): void {
                $validations = BillAnnotationNormalizer::validations(self::liveShape([
                    'meter_evidence' => ['previous_meter_reading' => null, 'present_meter_reading' => 1460, 'confidence' => 0.99],
                    'solar' => ['previous_solar_reading' => 1878, 'present_solar_reading' => 2200, 'solar_units_kwh' => 322, 'net_units_kwh' => null, 'confidence' => 0.97],
                ]));

                $byRule = [];
                foreach ($validations as $v) {
                    $byRule[$v->rule()] = $v;
                }
                Assert::same(ValidationStatus::NOT_CHECKED, $byRule[ValidationRule::METER_CONSUMPTION]->status());
                Assert::same(null, $byRule[ValidationRule::METER_CONSUMPTION]->expectedValue(), 'uncomputable expectation stays NULL');
                Assert::same(ValidationStatus::REASON_MISSING_OPERANDS, $byRule[ValidationRule::METER_CONSUMPTION]->reasonCode());
                Assert::same(ValidationStatus::NOT_CHECKED, $byRule[ValidationRule::NET_UNITS]->status());
                Assert::same(ValidationStatus::PASS, $byRule[ValidationRule::SOLAR_PRODUCTION]->status());
            }),

            TestResult::check('quality flags are the controlled vocabulary only, derived deterministically', function (): void {
                $clean = BillAnnotationNormalizer::normalize(self::liveShape());
                Assert::same([], BillAnnotationNormalizer::qualityFlags($clean, BillAnnotationNormalizer::validations(self::liveShape())));

                // Missing consumption + missing both meter readings.
                $sparse = BillAnnotationNormalizer::normalize(self::liveShape([
                    'consumption' => ['consumption_kwh' => null, 'confidence' => 0.1],
                    'meter_evidence' => ['previous_meter_reading' => null, 'present_meter_reading' => null, 'confidence' => 0.1],
                ]));
                $codes = array_map(
                    static fn ($f): string => $f->code() . '@' . ($f->fieldName() ?? '*'),
                    BillAnnotationNormalizer::qualityFlags($sparse, BillAnnotationNormalizer::validations(self::liveShape([
                        'consumption' => ['consumption_kwh' => null],
                        'meter_evidence' => ['previous_meter_reading' => null, 'present_meter_reading' => null],
                    ])))
                );
                sort($codes);
                Assert::same(
                    ['MISSING_CONSUMPTION@consumption_kwh', 'MISSING_METER_READINGS@previous_meter_reading'],
                    $codes
                );

                // The net CONFLICT from the physical-bill replay raises exactly one review flag.
                $flipShape = self::liveShape([
                    'solar' => [
                        'previous_solar_reading' => 1878, 'present_solar_reading' => 2200,
                        'solar_units_kwh' => 322, 'net_units_kwh' => 177, 'confidence' => 0.97,
                    ],
                ]);
                $conflictFlags = array_map(
                    static fn ($f): string => $f->code(),
                    BillAnnotationNormalizer::qualityFlags(
                        BillAnnotationNormalizer::normalize($flipShape),
                        BillAnnotationNormalizer::validations($flipShape)
                    )
                );
                Assert::same([QualityCode::INCONSISTENT_NET_UNITS], $conflictFlags);

                // Missing period alone.
                $noPeriod = self::liveShape(['billing_period' => ['start' => null, 'end' => null, 'confidence' => null]]);
                $npFlags = BillAnnotationNormalizer::qualityFlags(
                    BillAnnotationNormalizer::normalize($noPeriod),
                    BillAnnotationNormalizer::validations($noPeriod)
                );
                Assert::same(1, count($npFlags));
                Assert::same(QualityCode::MISSING_BILLING_PERIOD, $npFlags[0]->code());

                // Solar absence on a non-solar bill is NOT a defect flag (legitimate shape).
                $noSolar = self::liveShape(['solar' => ['previous_solar_reading' => null, 'present_solar_reading' => null, 'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null]]);
                $nsFlags = array_map(
                    static fn ($f): string => $f->code(),
                    BillAnnotationNormalizer::qualityFlags(BillAnnotationNormalizer::normalize($noSolar), BillAnnotationNormalizer::validations($noSolar))
                );
                Assert::same([], $nsFlags, 'missing solar alone never manufactures review pressure');
            }),
        ];
    }
}
