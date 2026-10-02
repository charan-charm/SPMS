<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillExtractionDraft;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillLifecycle;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\BillValidation;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\ProvenanceSource;
use App\Domain\Bill\QualityCode;
use App\Domain\Bill\ServiceNumber;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * B1 — Bill domain unit checks (docs/10 D-067): lifecycle table, field
 * whitelist (the data-minimization boundary), NULL/zero honesty,
 * chronology, draft immutability semantics, validation/confidence rules.
 * Pure PHP — no database, no network.
 */
final class BillDomainTest
{
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-14 10:00:00', new DateTimeZone('UTC'));
    }

    private static function bill(): Bill
    {
        return Bill::create('hh-1', '2026-08-01', '2026-09-01', self::now());
    }

    public static function run(): array
    {
        return [
            TestResult::check('a new bill enters at UPLOADED completely empty (NULL = unknown, never 0)', function (): void {
                $bill = Bill::create('hh-1', null, null, self::now());
                Assert::same(BillStatus::UPLOADED, $bill->status());
                Assert::same(null, $bill->verifiedAt());

                foreach (BillField::ALL as $field) {
                    Assert::same(null, $bill->value($field), $field . ' must start NULL');
                }
            }),

            TestResult::check('lifecycle: the happy chain and the review branch are all legal', function (): void {
                Assert::true(BillLifecycle::can(BillStatus::UPLOADED, BillStatus::PROCESSING));
                Assert::true(BillLifecycle::can(BillStatus::PROCESSING, BillStatus::EXTRACTED));
                Assert::true(BillLifecycle::can(BillStatus::EXTRACTED, BillStatus::NEEDS_REVIEW));
                Assert::true(BillLifecycle::can(BillStatus::EXTRACTED, BillStatus::VERIFIED));
                Assert::true(BillLifecycle::can(BillStatus::NEEDS_REVIEW, BillStatus::VERIFIED));
                Assert::true(BillLifecycle::can(BillStatus::PROCESSING, BillStatus::NOT_A_BILL));
                Assert::true(BillLifecycle::can(BillStatus::PROCESSING, BillStatus::UNREADABLE));
                Assert::true(BillLifecycle::can(BillStatus::PROCESSING, BillStatus::UNCERTAIN));
                Assert::true(BillLifecycle::can(BillStatus::PROCESSING, BillStatus::PROCESSING_FAILED));
            }),

            TestResult::check('lifecycle: fabricated or regressive transitions are rejected', function (): void {
                Assert::false(BillLifecycle::can(BillStatus::VERIFIED, BillStatus::PROCESSING), 'VERIFIED -> PROCESSING');
                Assert::false(BillLifecycle::can(BillStatus::VERIFIED, BillStatus::EXTRACTED), 'VERIFIED -> EXTRACTED');
                Assert::false(BillLifecycle::can(BillStatus::NOT_A_BILL, BillStatus::VERIFIED), 'NOT_A_BILL -> VERIFIED');
                Assert::false(BillLifecycle::can(BillStatus::UPLOADED, BillStatus::VERIFIED), 'UPLOADED -> VERIFIED');
                Assert::false(BillLifecycle::can(BillStatus::PROCESSING, BillStatus::VERIFIED), 'PROCESSING -> VERIFIED');
                Assert::false(BillLifecycle::can(BillStatus::EXTRACTED, BillStatus::PROCESSING_FAILED));
                Assert::false(BillLifecycle::can('DONE', BillStatus::VERIFIED), 'no vague DONE state exists');
                Assert::false(in_array('DONE', BillStatus::ALL, true));
            }),

            TestResult::check('transitionTo enforces the table on the entity and keeps ownership fixed', function (): void {
                $bill = self::bill();
                $processing = $bill->transitionTo(BillStatus::PROCESSING, self::now());
                Assert::same(BillStatus::PROCESSING, $processing->status());
                Assert::same($bill->id(), $processing->id());
                Assert::same($bill->householdId(), $processing->householdId());

                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => $bill->transitionTo(BillStatus::VERIFIED, self::now()),
                    'jump to VERIFIED without extraction data must be rejected'
                );
                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => $processing->transitionTo(BillStatus::UPLOADED, self::now())
                );
            }),

            TestResult::check('verify() is the only producer of VERIFIED and stamps verified_at atomically', function (): void {
                $bill = self::bill()
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now());

                $verified = $bill->verify(self::now());
                Assert::same(BillStatus::VERIFIED, $verified->status());
                Assert::same('2026-09-14 10:00:00', $verified->verifiedAt());
                Assert::true($verified->isAuthoritative());
                Assert::same(null, $bill->verifiedAt(), 'the pre-verify object is untouched (immutability)');

                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => $bill->transitionTo(BillStatus::VERIFIED, self::now()),
                    'transitionTo can never forge VERIFIED'
                );
            }),

            TestResult::check('terminal states stay terminal through the entity API', function (): void {
                $dead = self::bill()
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::NOT_A_BILL, self::now());

                Assert::throws(InvalidArgumentException::class, static fn () => $dead->transitionTo(BillStatus::PROCESSING, self::now()));
                Assert::throws(InvalidArgumentException::class, static fn () => $dead->verify(self::now()));
            }),

            TestResult::check('field whitelist: PII-shaped or raw-response keys are structurally impossible', function (): void {                foreach ([
                    'customer_name', 'name', 'aadhaar_number', 'mobile', 'account_number', 'usc',
                    'bill_number', 'meter_serial', 'dtr_code', 'qr_payload',
                    'raw_ocr_text', 'raw_response', 'document_annotation', 'all_extracted_data',
                    'image', 'file_path', 'page_count',
                ] as $key) {
                    Assert::false(BillField::isPersistable($key), $key . ' must not be persistable');
                    Assert::throws(
                        InvalidArgumentException::class,
                        static fn () => BillField::validate($key, 'x'),
                        $key . ' must be refused by the validator'
                    );
                }

                $bill = self::bill();
                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => $bill->withValues(['customer_name' => 'RAMESH'], self::now()),
                    'a correction payload cannot smuggle a non-whitelisted key'
                );
            }),

            TestResult::check('values are strings-or-null verbatim: no coercion, no truncation, zero != NULL', function (): void {
                $bill = self::bill();

                Assert::throws(InvalidArgumentException::class, static fn () => $bill->withValues(['consumption_kwh' => 145.5], self::now()), 'floats must be refused');
                Assert::throws(InvalidArgumentException::class, static fn () => $bill->withValues(['consumption_kwh' => '145.12345'], self::now()), 'over-precise decimals must be rejected, never silently truncated');
                Assert::throws(InvalidArgumentException::class, static fn () => $bill->withValues(['bill_amount' => '1e2'], self::now()), 'exponent notation is not a decimal string');
                Assert::throws(InvalidArgumentException::class, static fn () => $bill->withValues(['total_due' => ''], self::now()), 'empty string is not a value (NULL means unknown)');

                $kept = $bill->withValues(
                    ['consumption_kwh' => '145.000', 'net_units_kwh' => '-177.000', 'fixed_charges' => '0.00', 'arrears' => '-105.00'],
                    self::now()
                );
                Assert::same('145.000', $kept->value('consumption_kwh'));
                Assert::same('-177.000', $kept->value('net_units_kwh'), 'negative net units preserved exactly');
                Assert::same('0.00', $kept->value('fixed_charges'), 'explicit zero stays zero');
                Assert::same('-105.00', $kept->value('arrears'));
                Assert::same(null, $kept->value('total_due'), 'untouched fields keep their NULL');

                $cleared = $kept->withValues(['consumption_kwh' => null], self::now());
                Assert::same(null, $cleared->value('consumption_kwh'));
                Assert::same('0.00', $cleared->value('fixed_charges'), 'clearing one field never touches another');
            }),

            TestResult::check('contracted load is non-negative, text context is bounded', function (): void {
                $bill = self::bill();
                Assert::same('3.00', $bill->withValues(['contracted_load' => '3.00'], self::now())->value('contracted_load'));
                Assert::throws(InvalidArgumentException::class, static fn () => $bill->withValues(['contracted_load' => '-3.00'], self::now()));
                Assert::throws(InvalidArgumentException::class, static fn () => $bill->withValues(['utility_provider' => str_repeat('x', 121)], self::now()));
            }),

            TestResult::check('billing chronology: reversed periods are rejected at every door, never reordered', function (): void {
                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => Bill::create('hh-1', '2026-09-01', '2026-08-01', self::now()),
                    'creation with start > end must fail'
                );

                $bill = self::bill();
                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => $bill->withValues(
                        [BillField::BILLING_PERIOD_START => '2026-09-02', BillField::BILLING_PERIOD_END => '2026-08-30'],
                        self::now()
                    ),
                    'a corrected pair must satisfy the chronology too'
                );

                // One-sided changes keep the stored other side: still checked against it.
                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => $bill->withValues([BillField::BILLING_PERIOD_START => '2027-01-01'], self::now()),
                    'moving start past the existing end must fail'
                );
                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => Bill::create('hh-1', '2026-13-45', null, self::now()),
                    'strict Y-m-d only'
                );
            }),

            TestResult::check('drafts are normalized-only records; document confidence stands alone', function (): void {
                $draft = BillExtractionDraft::create(
                    'bill-1',
                    'mistral',
                    'mistral-ocr-latest',
                    self::now(),
                    DocumentAssessment::VALID,
                    null,
                    '0.9900',
                    ['consumption_kwh' => '145.000', 'net_units_kwh' => '-177.000', 'total_due' => null],
                    // explicit per-field confidences for ONE field only:
                    ['consumption_kwh' => '0.95']
                );

                Assert::same('0.9900', $draft->documentConfidence());
                Assert::same('145.000', $draft->values()['consumption_kwh']);
                Assert::same(null, $draft->values()['total_due'], 'draft NULL means the provider did not extract it');
                Assert::same('0.95', $draft->confidences()['consumption_kwh']);
                Assert::false(array_key_exists('net_units_kwh', $draft->confidences()), 'absent field confidence stays ABSENT (never copied from document confidence)');

                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => BillExtractionDraft::create('bill-1', 'mistral', null, self::now(), DocumentAssessment::VALID, null, '0.9', ['customer_name' => 'x']),
                    'a draft cannot carry a non-whitelisted field'
                );
                Assert::throws(
                    InvalidArgumentException::class,
                    static fn () => BillExtractionDraft::create('bill-1', 'mistral', null, self::now(), 'MAYBE', null, null, []),
                    'unknown assessment must fail'
                );
            }),

            TestResult::check('confidence validation: no fabrication, no out-of-range, no truncation', function (): void {
                $withConf = function (array $confidences) {
                    return BillExtractionDraft::create(
                        'bill-1', 'p', null, self::now(), DocumentAssessment::VALID, null, null, [], $confidences
                    );
                };

                Assert::same('0.0000', $withConf(['solar_units_kwh' => '0.0000'])->confidences()['solar_units_kwh'], 'explicit zero confidence survives');
                Assert::same('1', $withConf(['solar_units_kwh' => '1'])->confidences()['solar_units_kwh']);

                foreach (['1.0001', '-0.5', '0.12345', 'high', '99'] as $bad) {
                    Assert::throws(
                        InvalidArgumentException::class,
                        static fn () => $withConf(['solar_units_kwh' => $bad]),
                        'confidence ' . $bad . ' must be refused'
                    );
                }
            }),

            TestResult::check('validation VO: every outcome carries the numbers that explain it (and only those)', function (): void {
                $conflict = BillValidation::create(
                    ValidationRule::NET_UNITS,
                    ValidationStatus::CONFLICT,
                    '-177.000',
                    '177.000'
                );
                Assert::same('-177.000', $conflict->expectedValue(), 'expected keeps its sign');
                Assert::same('177.000', $conflict->extractedValue(), 'extracted is stored as extracted, uncorrected');

                $pass = BillValidation::create(ValidationRule::METER_CONSUMPTION, ValidationStatus::PASS, '145.000', '145.000');
                Assert::same(ValidationStatus::PASS, $pass->status());

                $notChecked = BillValidation::create(
                    ValidationRule::SOLAR_PRODUCTION,
                    ValidationStatus::NOT_CHECKED,
                    null,
                    '322.000',
                    ValidationStatus::REASON_MISSING_OPERANDS
                );
                Assert::same(null, $notChecked->expectedValue(), 'a missing expectation stays NULL');

                // shape guards
                Assert::throws(InvalidArgumentException::class, static fn () => BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::PASS, '145.000', null));
                Assert::throws(InvalidArgumentException::class, static fn () => BillValidation::create('SOMETHING', ValidationStatus::PASS, '1.000', '1.000'));
                Assert::throws(InvalidArgumentException::class, static fn () => BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::NOT_CHECKED, '1.000', '1.000', ValidationStatus::REASON_MISSING_OPERANDS));
                Assert::throws(InvalidArgumentException::class, static fn () => BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::NOT_CHECKED, null, '1.000'));
                Assert::throws(InvalidArgumentException::class, static fn () => BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::PASS, '145.0005', '145.000'), 'validation decimals match energy precision (no truncation)');
            }),

            TestResult::check('quality flags and provenance sources are closed vocabularies', function (): void {
                $flag = BillQualityFlag::create(QualityCode::MISSING_CONSUMPTION, 'consumption_kwh');
                Assert::same(QualityCode::MISSING_CONSUMPTION, $flag->code());
                Assert::same('consumption_kwh', $flag->fieldName());
                Assert::same(null, BillQualityFlag::create(QualityCode::MISSING_BILLING_PERIOD)->fieldName(), 'document-wide flags have no field');

                Assert::throws(InvalidArgumentException::class, static fn () => BillQualityFlag::create('SOMETHING_VAGUE'));
                Assert::throws(InvalidArgumentException::class, static fn () => BillQualityFlag::create(QualityCode::UNREADABLE_FIELD, 'customer_name'));
                Assert::throws(InvalidArgumentException::class, static fn () => BillQualityFlag::create(QualityCode::UNREADABLE_FIELD, ''));

                Assert::true(ProvenanceSource::isValid(ProvenanceSource::AI_EXTRACTION));
                Assert::true(ProvenanceSource::isValid(ProvenanceSource::USER_CORRECTION));
                Assert::false(ProvenanceSource::isValid('AUTO_ACCEPTED'));

                // D-071: the ownership/evidence codes joined the closed
                // vocabulary; nothing else may.
                foreach (
                    [
                        QualityCode::NO_BILL_EVIDENCE,
                        QualityCode::SERVICE_NUMBER_MISMATCH,
                        QualityCode::SERVICE_NUMBER_MISSING,
                        QualityCode::HOUSEHOLD_SERVICE_NOT_CONFIGURED,
                        QualityCode::INCOMPLETE_HISTORY,
                        QualityCode::BILLING_GAP_PRECEDING,
                    ] as $code
                ) {
                    Assert::true(QualityCode::isValid($code), $code);
                }
                Assert::false(QualityCode::isValid('OWNERSHIP_PRETTY_MUCH_CONFIRMED'));
            }),

            TestResult::check('D-071 service-number canonicalization is conservative, deterministic, and never fuzzy', function (): void {
                // Decorative separators and case fold; content stays.
                Assert::same('10002000300', ServiceNumber::canonicalize('1000-2000-300'));
                Assert::same('10002000300', ServiceNumber::canonicalize(' 1000 2000 300 '));
                Assert::same('SN1234AB', ServiceNumber::canonicalize('sn/1234.ab'));

                // Leading zeros, length and ORDER are content — preserved.
                Assert::same('0123456', ServiceNumber::canonicalize(' 0123-456 '));
                Assert::true(ServiceNumber::canonicalize('123456') !== ServiceNumber::canonicalize('0123456'),
                    'zero-padded identifiers must not collapse');
                Assert::true(ServiceNumber::canonicalize('1234AB') !== ServiceNumber::canonicalize('AB1234'),
                    'reordering is never equivalence');

                // Refusals: too short, too long, non-alphanumeric residue.
                Assert::same(null, ServiceNumber::canonicalize(null));
                Assert::same(null, ServiceNumber::canonicalize('   '));
                Assert::same(null, ServiceNumber::canonicalize('12'));
                Assert::same(null, ServiceNumber::canonicalize('1 2 3'));
                Assert::same(null, ServiceNumber::canonicalize('1234 56#89'));
                Assert::same(null, ServiceNumber::canonicalize(str_repeat('9', 25)));

                // Matching is canonical equality only.
                Assert::true(ServiceNumber::matches('1000-2000-300', '10002000300'));
                Assert::true(ServiceNumber::matches('1000-2000-300', '1000-2000-300'));
                Assert::false(ServiceNumber::matches('10002000300', '1000200030'));
                Assert::false(ServiceNumber::matches(null, '10002000300'), 'missing never matches');
                Assert::false(ServiceNumber::matches('10002000300', null));
                Assert::false(ServiceNumber::matches('123456', '654321'));
                Assert::false(ServiceNumber::matches('99', '99'), 'a too-short pair never matches');

                // assertCanonical: fail-closed for registration input.
                Assert::same('456780123', ServiceNumber::assertCanonical('456-780123'));
                Assert::same(null, ServiceNumber::assertCanonical(null));
                Assert::throws(InvalidArgumentException::class, static fn () => ServiceNumber::assertCanonical('nope!!'));

                // Masking keeps only the tail for non-owner contexts.
                Assert::same(str_repeat('•', 7) . '0300', ServiceNumber::mask('10002000300'));
                Assert::same(str_repeat('•', 4), ServiceNumber::mask('1234'));
                Assert::same(null, ServiceNumber::mask(null));
            }),

            TestResult::check('storage-row round-trips are lossless (strings verbatim, NULLs honest)', function (): void {
                $bill = self::bill()->withValues(
                    [
                        'consumption_kwh' => '145.000',
                        'net_units_kwh' => '-177.000',
                        'bill_amount' => '180.00',
                        'arrears' => '-105.00',
                        'total_due' => '75.00',
                        'utility_provider' => 'DEMO GRID',
                    ],
                    self::now()
                );

                $row = $bill->toStorageRow();
                $row['household_id'] = 'hh-1';
                $restored = Bill::fromStorageRow($row);

                Assert::same($bill->id(), $restored->id());
                Assert::same($bill->status(), $restored->status());
                Assert::same('145.000', $restored->value('consumption_kwh'));
                Assert::same('-177.000', $restored->value('net_units_kwh'));
                Assert::same('180.00', $restored->value('bill_amount'));
                Assert::same('-105.00', $restored->value('arrears'));
                Assert::same('75.00', $restored->value('total_due'));
                Assert::same(null, $restored->value('fixed_charges'));
                Assert::same('DEMO GRID', $restored->value('utility_provider'));

                // toStorageRow exposes only whitelisted + structural columns
                $expectedKeys = array_merge(BillField::ALL, ['id', 'household_id', 'status', 'fingerprint', 'verified_at', 'created_at', 'updated_at']);
                sort($expectedKeys);
                $actualKeys = array_keys($row);
                sort($actualKeys);
                Assert::same($expectedKeys, $actualKeys, 'the bill row shape is the ratified schema shape');
            }),
        ];
    }
}
