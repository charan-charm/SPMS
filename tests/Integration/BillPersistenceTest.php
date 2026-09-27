<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Account\Account;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillExtractionDraft;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\BillValidation;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\ProvenanceSource;
use App\Domain\Bill\QualityCode;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use App\Repositories\BillRepository;
use App\Support\AppException;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * B1 — Bill persistence + lifecycle behavior against the real database
 * (docs/10 D-067).
 *
 * Exercises the service/repository end to end with the SYNTHETIC
 * normalized values ratified for testing (2026-08-01→2026-09-01,
 * 145 kWh, meter 1315→1460, solar 1878→2200, 322, -177, ₹180/−₹105/₹75):
 * no real document, no provider call, no personal identifier anywhere in
 * these fixtures.
 */
final class BillPersistenceTest
{
    /** @var list<string> bill ids created by this run (cleaned up at the end) */
    private static array $createdBills = [];

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function service(): BillService
    {
        return new BillService();
    }

    private static function repo(): BillRepository
    {
        return new BillRepository();
    }

    private static function track(Bill $bill): Bill
    {
        self::$createdBills[] = $bill->id();

        return $bill;
    }

    /**
     * Synthetic canonical bill values (docs/10 D-067 test data; the
     * service number joins the set in D-071, matching the fixture
     * household's registered number so persistence tests exercise the
     * ownership-MATCH path unless a test states otherwise).
     *
     * @return array<string, string|null>
     */
    private static function canonicalValues(): array
    {
        return [
            'billing_period_start' => '2026-08-01',
            'billing_period_end' => '2026-09-01',
            'consumption_kwh' => '145.000',
            'previous_meter_reading' => '1315.000',
            'present_meter_reading' => '1460.000',
            'previous_solar_reading' => '1878.000',
            'present_solar_reading' => '2200.000',
            'solar_units_kwh' => '322.000',
            'net_units_kwh' => '-177.000',
            'bill_amount' => '180.00',
            'arrears' => '-105.00',
            'total_due' => '75.00',
            'fixed_charges' => '30.00',
            'customer_service_charges' => '25.00',
            'other_charges' => '45.00',
            'utility_provider' => 'DEMO GRID UTILITY LTD',
            'tariff_category' => 'DOMESTIC (A)',
            'meter_type' => 'SMART SOLAR',
            'contracted_load' => '3.00',
            'service_number' => '456780123',
        ];
    }

    /** D-071: every fixture household registers this canonical number. */
    private const FIXTURE_SERVICE = '456-780123';

    /**
     * @return array{0: Account, 1: string} the fixture account + household id
     */
    private static function fixture(): array
    {
        $context = AuthTesting::householdFixture('b1-test-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);

        return [$context['account'], $context['household_id']];
    }

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * A bill in PROCESSING (the only state that accepts extraction),
     * returned as [$service, $account, $bill].
     */
    private static function processingBill(?Account &$accountOut = null): array
    {
        [$account, ] = self::fixture();
        $service = self::service();

        $bill = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));
        $bill = $service->markProcessing($account, $bill->id(), self::now());
        $accountOut = $account;

        return [$service, $account, $bill];
    }

    public static function run(): array
    {
        $tests = [
            TestResult::check('a bill belongs to its household and is invisible to everyone else (structural scoping)', function (): void {
                [$accountA, $householdA] = self::fixture();
                [$accountB, $householdB] = self::fixture();
                $service = self::service();
                $repo = self::repo();

                $bill = self::track($service->create($accountA, null, null, self::now()));
                Assert::same($householdA, $bill->householdId());

                Assert::true($repo->find($bill->id(), $householdA) instanceof Bill, 'owning household reads its bill');
                Assert::same(null, $repo->find($bill->id(), $householdB), 'a foreign household cannot resolve this id at all');

                $idsA = array_map(static fn (Bill $b): string => $b->id(), $repo->listForHousehold($householdA));
                $idsB = array_map(static fn (Bill $b): string => $b->id(), $repo->listForHousehold($householdB));
                Assert::true(in_array($bill->id(), $idsA, true));
                Assert::false(in_array($bill->id(), $idsB, true));

                // The repository API has no unscoped lookup at all.
                Assert::false(method_exists(BillRepository::class, 'findById'));
                Assert::false(method_exists(BillRepository::class, 'findWithoutHousehold'));

                // Service-level: account B cannot address A's bill by id.
                Assert::same(null, $service->get($accountB, $bill->id()), 'a foreign bill reads as nonexistent');
                foreach ([
                    static fn () => $service->markProcessing($accountB, $bill->id(), self::now()),
                    static fn () => $service->verify($accountB, $bill->id(), self::now()),
                    static fn () => $service->applyCorrections($accountB, $bill->id(), ['consumption_kwh' => '1.000'], self::now()),
                ] as $attempt) {
                    Assert::throws(AppException::class, $attempt);
                    try {
                        $attempt();
                    } catch (AppException $e) {
                        Assert::same(404, $e->httpStatus(), 'foreign bill is indistinguishable from nonexistent (D-064 §3)');
                        Assert::same('NOT_FOUND', $e->errorCode());
                    }
                }
            }),

            TestResult::check('an account without a household context cannot own or reach bills', function (): void {
                $account = AuthTesting::activateAccount();
                $service = self::service();

                try {
                    $service->create($account, null, null, self::now());
                    Assert::true(false, 'expected 404 with no household');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                }
            }),

            TestResult::check('the extraction lifecycle stores draft + bill + validations + provenance coherently', function (): void {
                [$service, $account, $bill] = self::processingBill();

                $draft = BillExtractionDraft::create(
                    $bill->id(),
                    'mistral',
                    'mistral-ocr-latest',
                    self::now(),
                    DocumentAssessment::VALID,
                    null,
                    '0.9900',
                    self::canonicalValues(),
                    ['consumption_kwh' => '0.95']
                );

                $validations = [
                    BillValidation::create(ValidationRule::METER_CONSUMPTION, ValidationStatus::PASS, '145.000', '145.000'),
                    BillValidation::create(ValidationRule::SOLAR_PRODUCTION, ValidationStatus::PASS, '322.000', '322.000'),
                    BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::PASS, '-177.000', '-177.000'),
                ];

                $updated = $service->recordExtraction($account, $bill->id(), $draft, $validations, []);

                // EXTRACTED, NOT verified — a 0.99 VALID extraction never self-authorizes (§3/§17/§20).
                Assert::same(BillStatus::EXTRACTED, $updated->status());
                Assert::same(null, $updated->verifiedAt());

                $reloaded = self::repo()->find($bill->id(), $account->id() ? self::householdOf($account) : '');
                Assert::true($reloaded instanceof Bill);
                Assert::same('145.000', $reloaded->value('consumption_kwh'));
                Assert::same('-177.000', $reloaded->value('net_units_kwh'), 'negative net survives persistence verbatim');
                Assert::same('180.00', $reloaded->value('bill_amount'));
                Assert::same('-105.00', $reloaded->value('arrears'), 'arrears sign preserved');
                Assert::same('75.00', $reloaded->value('total_due'));
                Assert::same('180.00', $reloaded->value('bill_amount'), 'bill amount untouched by other money fields (§6)');
                Assert::same(null, $reloaded->value('energy_charges'), 'not extracted = NULL, never 0 (§15)');
                Assert::same(null, $reloaded->value('taxes_adjustments'));

                // Draft stored, document-level only.
                $storedDraft = $service->draftFor($account, $bill->id());
                Assert::true($storedDraft instanceof BillExtractionDraft);
                Assert::same('mistral', $storedDraft->provider());
                Assert::same('mistral-ocr-latest', $storedDraft->model());
                Assert::same(DocumentAssessment::VALID, $storedDraft->assessment());
                Assert::same('0.9900', $storedDraft->documentConfidence());
                Assert::same('145.000', $storedDraft->values()['consumption_kwh']);

                // Validations all PASS and readable.
                $storedValidations = $service->validationsFor($account, $bill->id());
                Assert::same(3, count($storedValidations));
                foreach ($storedValidations as $validation) {
                    Assert::same(ValidationStatus::PASS, $validation->status());
                }

                // Provenance: AI-sourced, field confidence HONEST.
                $provenance = $service->provenanceFor($account, $bill->id());
                Assert::same(ProvenanceSource::AI_EXTRACTION, $provenance['consumption_kwh']['source']);
                Assert::same('0.9500', $provenance['consumption_kwh']['confidence']);
                Assert::same(null, $provenance['bill_amount']['confidence'], 'document confidence 0.99 was NOT copied onto fields (§8/§14)');
                Assert::false(array_key_exists('energy_charges', $provenance), 'no provenance row for values that do not exist');
            }),

            TestResult::check('a CONFLICT records expected vs extracted verbatim and forces NEEDS_REVIEW without touching values', function (): void {
                [$service, $account, $bill] = self::processingBill();

                // The provider extracted the wrong SIGN for net units (177 instead of -177).
                $values = self::canonicalValues();
                $values['net_units_kwh'] = '177.000';

                $draft = BillExtractionDraft::create(
                    $bill->id(),
                    'mistral',
                    null,
                    self::now(),
                    DocumentAssessment::VALID,
                    null,
                    null,
                    $values,
                );

                $validations = [
                    BillValidation::create(ValidationRule::METER_CONSUMPTION, ValidationStatus::PASS, '145.000', '145.000'),
                    BillValidation::create(ValidationRule::SOLAR_PRODUCTION, ValidationStatus::PASS, '322.000', '322.000'),
                    // The observed real-bill shape: sign flipped by the provider.
                    BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::CONFLICT, '-177.000', '177.000'),
                ];

                $updated = $service->recordExtraction($account, $bill->id(), $draft, $validations, []);
                Assert::same(BillStatus::NEEDS_REVIEW, $updated->status(), 'a conflict is a review obligation, not silence (§10/§11)');

                $reloaded = self::repo()->find($bill->id(), self::householdOf($account));
                Assert::same('177.000', $reloaded->value('net_units_kwh'), 'the extracted value stays EXACTLY as extracted — persistence never auto-corrects (§10/§18)');

                $net = null;
                foreach ($service->validationsFor($account, $bill->id()) as $validation) {
                    if ($validation->rule() === ValidationRule::NET_UNITS) {
                        $net = $validation;
                    }
                }
                Assert::true($net instanceof BillValidation);
                Assert::same(ValidationStatus::CONFLICT, $net->status());
                Assert::same('-177.000', $net->expectedValue(), 'expected keeps its sign');
                Assert::same('177.000', $net->extractedValue(), 'extracted preserved alongside for explanation (§10)');
            }),

            TestResult::check('NOT_CHECKED persists honestly (NULL operands, reason code, no fake pass)', function (): void {
                [$service, $account, $bill] = self::processingBill();

                $values = self::canonicalValues();
                unset($values['previous_solar_reading'], $values['solar_units_kwh']);

                $draft = BillExtractionDraft::create(
                    $bill->id(),
                    'mistral',
                    null,
                    self::now(),
                    DocumentAssessment::VALID_WITH_LIMITATIONS,
                    'solar register partially unreadable',
                    null,
                    $values
                );

                $validations = [
                    BillValidation::create(ValidationRule::METER_CONSUMPTION, ValidationStatus::PASS, '145.000', '145.000'),
                    BillValidation::create(ValidationRule::SOLAR_PRODUCTION, ValidationStatus::NOT_CHECKED, null, null, ValidationStatus::REASON_MISSING_OPERANDS),
                ];
                $flags = [
                    BillQualityFlag::create(QualityCode::MISSING_SOLAR_READINGS, 'previous_solar_reading'),
                    BillQualityFlag::create(QualityCode::UNREADABLE_FIELD, 'solar_units_kwh'),
                ];

                $updated = $service->recordExtraction($account, $bill->id(), $draft, $validations, $flags);
                Assert::same(BillStatus::NEEDS_REVIEW, $updated->status());

                $reloaded = self::repo()->find($bill->id(), self::householdOf($account));
                Assert::same(null, $reloaded->value('previous_solar_reading'), 'missing stays NULL (never 0, §12/§15)');
                Assert::same(null, $reloaded->value('solar_units_kwh'));

                $rules = [];
                foreach ($service->validationsFor($account, $bill->id()) as $validation) {
                    $rules[$validation->rule()] = $validation;
                }
                Assert::same(ValidationStatus::NOT_CHECKED, $rules[ValidationRule::SOLAR_PRODUCTION]->status());
                Assert::same(null, $rules[ValidationRule::SOLAR_PRODUCTION]->expectedValue());
                Assert::same(ValidationStatus::REASON_MISSING_OPERANDS, $rules[ValidationRule::SOLAR_PRODUCTION]->reasonCode());

                $codes = array_map(static fn (BillQualityFlag $f): string => $f->code() . ':' . ($f->fieldName() ?? '*'), $service->qualityFlagsFor($account, $bill->id()));
                sort($codes);
                Assert::same(
                    ['MISSING_SOLAR_READINGS:previous_solar_reading', 'UNREADABLE_FIELD:solar_units_kwh'],
                    $codes
                );
            }),

            TestResult::check('user corrections update the bill and provenance WITHOUT erasing the AI draft, then verify makes it authoritative', function (): void {
                [$service, $account, $bill] = self::processingBill();

                $draft = BillExtractionDraft::create(
                    $bill->id(),
                    'mistral',
                    'mistral-ocr-latest',
                    self::now(),
                    DocumentAssessment::VALID,
                    null,
                    null,
                    self::canonicalValues(),
                    ['consumption_kwh' => '0.72']
                );
                $service->recordExtraction($account, $bill->id(), $draft, [], []);

                // The user reviews and corrects the AI draft: 145 -> 148.
                $corrected = $service->applyCorrections($account, $bill->id(), ['consumption_kwh' => '148.000'], self::now());
                Assert::same('148.000', $corrected->value('consumption_kwh'));

                // AI draft untouched (test 12): the original stays forever.
                $storedDraft = $service->draftFor($account, $bill->id());
                Assert::same('145.000', $storedDraft->values()['consumption_kwh'], 'corrections must never rewrite the immutable AI draft');

                // Field-level provenance distinguishes AI from user (test 11).
                $provenance = $service->provenanceFor($account, $bill->id());
                Assert::same(ProvenanceSource::USER_CORRECTION, $provenance['consumption_kwh']['source']);
                Assert::same(null, $provenance['consumption_kwh']['confidence'], 'a human value is not "confident", it is confirmed');
                Assert::same(ProvenanceSource::AI_EXTRACTION, $provenance['bill_amount']['source'], 'untouched fields keep AI provenance');

                // Only explicit verification flips to authoritative — and it does.
                $verified = $service->verify($account, $bill->id(), self::now());
                Assert::same(BillStatus::VERIFIED, $verified->status());
                Assert::true($verified->verifiedAt() !== null);
                Assert::same('148.000', $verified->value('consumption_kwh'), 'final authoritative value is the corrected one (§7)');

                $storedDraft = $service->draftFor($account, $bill->id());
                Assert::same('145.000', $storedDraft->values()['consumption_kwh'], 'verification keeps the draft intact too');

                // A verified bill is frozen: no silent re-edit, no double verify.
                Assert::throws(
                    AppException::class,
                    static fn () => $service->applyCorrections($account, $bill->id(), ['consumption_kwh' => '149.000'], self::now())
                );
                try {
                    $service->verify($account, $bill->id(), self::now());
                    Assert::true(false, 'expected 409 on double verify');
                } catch (AppException $e) {
                    Assert::same('INVALID_BILL_STATE', $e->errorCode());
                }
            }),

            TestResult::check('exception assessments terminate the bill and persist no data (but the draft is honest evidence)', function (): void {
                $cases = [
                    DocumentAssessment::NOT_A_BILL => BillStatus::NOT_A_BILL,
                    DocumentAssessment::UNREADABLE => BillStatus::UNREADABLE,
                    DocumentAssessment::UNCERTAIN => BillStatus::UNCERTAIN,
                ];

                foreach ($cases as $assessment => $expectedStatus) {
                    [$service, $account, $bill] = self::processingBill();

                    $draft = BillExtractionDraft::create(
                        $bill->id(),
                        'mistral',
                        null,
                        self::now(),
                        $assessment,
                        'assessment recorded for the audit trail',
                        null,
                        // the provider guessed SOME values — an unusable document must not launder them into the bill:
                        ['consumption_kwh' => '50.000']
                    );

                    $updated = $service->recordExtraction($account, $bill->id(), $draft, [], []);
                    Assert::same($expectedStatus, $updated->status(), $assessment);

                    $reloaded = self::repo()->find($bill->id(), self::householdOf($account));
                    Assert::same(null, $reloaded->value('consumption_kwh'), 'an unusable document leaves no data on the bill');
                    Assert::same([], $service->provenanceFor($account, $bill->id()));

                    // terminal: no further lifecycle moves (§18)
                    try {
                        $service->markProcessing($account, $bill->id(), self::now());
                        Assert::true(false, $assessment . ' must be terminal');
                    } catch (AppException $e) {
                        Assert::same('INVALID_BILL_STATE', $e->errorCode());
                    }
                }
            }),

            TestResult::check('invalid lifecycle sequences are rejected at the right states (UPLOADED cannot skip to extraction; extraction is once-only)', function (): void {
                [$account, $householdId] = self::fixture();
                $service = self::service();
                $bill = self::track($service->create($account, null, null, self::now()));

                $draft = BillExtractionDraft::create($bill->id(), 'mistral', null, self::now(), DocumentAssessment::VALID, null, null, []);

                try {
                    $service->recordExtraction($account, $bill->id(), $draft, [], []);
                    Assert::true(false, 'UPLOADED must not accept extraction');
                } catch (AppException $e) {
                    Assert::same('INVALID_BILL_STATE', $e->errorCode());
                    Assert::contains('UPLOADED', $e->getMessage());
                }

                // verify before extraction: rejected.
                try {
                    $service->verify($account, $bill->id(), self::now());
                    Assert::true(false, 'UPLOADED must not be verifiable');
                } catch (AppException $e) {
                    Assert::same('INVALID_BILL_STATE', $e->errorCode());
                }

                $service->markProcessing($account, $bill->id(), self::now());
                $service->markProcessingFailed($account, $bill->id(), self::now());

                // PROCESSING_FAILED can transition back to PROCESSING for retry
                $retried = $service->markProcessing($account, $bill->id(), self::now());
                Assert::same(BillStatus::PROCESSING, $retried->status());

                // But cannot skip directly to VERIFIED
                try {
                    $service->verify($account, $bill->id(), self::now());
                    Assert::true(false, 'PROCESSING must not skip directly to VERIFIED');
                } catch (AppException $e) {
                    Assert::same('INVALID_BILL_STATE', $e->errorCode());
                }
            }),

            TestResult::check('billing chronology is refused by persistence (reversed dates never stored, never reordered)', function (): void {
                [$account, ] = self::fixture();
                $service = self::service();

                try {
                    $service->create($account, '2026-09-01', '2026-08-01', self::now());
                    Assert::true(false, 'reversed period must be rejected at creation');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same('billing_period_start', $e->context()['field'] ?? null);
                }

                // On a persisted, extracted bill, a correction that reverses
                // the chronology is refused (still a domain validation error).
                [$service2, $account2, $bill] = self::processingBill();
                $draft = BillExtractionDraft::create(
                    $bill->id(), 'mistral', null, self::now(),
                    DocumentAssessment::VALID, null, null, self::canonicalValues()
                );
                $service2->recordExtraction($account2, $bill->id(), $draft, [], []);

                try {
                    $service2->applyCorrections($account2, $bill->id(), ['billing_period_start' => '2027-01-01'], self::now());
                    Assert::true(false, 'a correction that reverses chronology must be rejected');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                }
            }),

            TestResult::check('explicit zero survives, NULL survives, decimals keep their exact scale', function (): void {
                [$service, $account, $bill] = self::processingBill();

                $values = self::canonicalValues();
                $values['consumption_kwh'] = '0.000';        // explicit zero usage
                $values['net_units_kwh'] = '-177.125';       // fractional energy
                $values['bill_amount'] = '180.55';           // paise precision
                unset($values['arrears'], $values['total_due']);

                $draft = BillExtractionDraft::create(
                    $bill->id(),
                    'mistral',
                    null,
                    self::now(),
                    DocumentAssessment::VALID,
                    null,
                    null,
                    $values
                );
                $service->recordExtraction($account, $bill->id(), $draft, [], []);

                $reloaded = self::repo()->find($bill->id(), self::householdOf($account));
                Assert::same('0.000', $reloaded->value('consumption_kwh'), 'a real zero is not NULL and is not hidden (§15)');
                Assert::same('-177.125', $reloaded->value('net_units_kwh'), 'decimal kWh preserved (§14)');
                Assert::same('180.55', $reloaded->value('bill_amount'), 'monetary precision preserved (§14)');
                Assert::same(null, $reloaded->value('arrears'), 'missing money field remains NULL (§6)');
                Assert::same(null, $reloaded->value('total_due'));
                // three cost concepts remain three separate columns (§6)
                Assert::same('180.55', $reloaded->value('bill_amount'));
                Assert::same('30.00', $reloaded->value('fixed_charges'));
                Assert::same('45.00', $reloaded->value('other_charges'));
            }),

            TestResult::check('validation upsert is one-row-per-rule and idempotent; quality flags de-duplicate', function (): void {
                [$service, $account, $bill] = self::processingBill();

                // A full-evidence, ownership-matching extraction so this
                // test measures ONLY the upsert/dedup semantics below
                // (D-071: an empty "VALID" draft would honestly become
                // NO_BILL_EVIDENCE and confuse the counts).
                $draft = BillExtractionDraft::create($bill->id(), 'p', null, self::now(), DocumentAssessment::VALID, null, null, self::canonicalValues());
                $service->recordExtraction($account, $bill->id(), $draft, [], []);

                $repo = self::repo();
                $repo->upsertValidation($bill->id(), BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::PASS, '1.000', '1.000'), self::now()->format('Y-m-d H:i:s'));
                $repo->upsertValidation($bill->id(), BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::CONFLICT, '-2.000', '2.000'), self::now()->format('Y-m-d H:i:s'));
                $repo->recordQualityFlag($bill->id(), BillQualityFlag::create(QualityCode::MISSING_CONSUMPTION), self::now()->format('Y-m-d H:i:s'));
                $repo->recordQualityFlag($bill->id(), BillQualityFlag::create(QualityCode::MISSING_CONSUMPTION), self::now()->format('Y-m-d H:i:s'));

                $validations = $service->validationsFor($account, $bill->id());
                Assert::same(1, count($validations), 'ON DUPLICATE KEY keeps exactly one result per rule');
                Assert::same(ValidationStatus::CONFLICT, $validations[0]->status(), 'the latest statement wins');

                // The extraction itself contributed no flags (values are
                // complete and the service number matches the registered
                // household), so the only flag here is the manual one.
                $flagCodes = array_map(
                    static fn ($f): string => $f->code() . ':' . ($f->fieldName() ?? '-'),
                    $service->qualityFlagsFor($account, $bill->id())
                );
                sort($flagCodes);
                Assert::same(['MISSING_CONSUMPTION:-'], $flagCodes, 'the same (code, field) is never duplicated');
            }),

            TestResult::check('household owns the bills (delete RESTRICT) and deleting a bill takes its whole aggregate with it (CASCADE)', function (): void {
                [$account, $householdId] = self::fixture();
                $service = self::service();
                $bill = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));

                $service->markProcessing($account, $bill->id(), self::now());
                $draft = BillExtractionDraft::create($bill->id(), 'p', null, self::now(), DocumentAssessment::VALID, null, null, self::canonicalValues());
                $service->recordExtraction(
                    $account,
                    $bill->id(),
                    $draft,
                    [BillValidation::create(ValidationRule::NET_UNITS, ValidationStatus::PASS, '-177.000', '-177.000')],
                    [BillQualityFlag::create(QualityCode::AMBIGUOUS_AMOUNT, 'other_charges')]
                );

                $pdo = self::pdo();

                // A household referenced by bills can never be deleted silently (§19).
                $restricted = false;
                try {
                    $pdo->prepare('DELETE FROM households WHERE id = :id')->execute(['id' => $householdId]);
                } catch (\PDOException) {
                    $restricted = true;
                }
                Assert::true($restricted, 'bills -> households RESTRICT must block silent household removal');

                // Deleting the bill row removes its aggregate (children CASCADE).
                $countDrafts = static function (string $billId) use ($pdo): int {
                    $s = $pdo->prepare('SELECT COUNT(*) FROM bill_extraction_drafts WHERE bill_id = :id');
                    $s->execute(['id' => $billId]);

                    return (int) $s->fetchColumn();
                };
                Assert::same(1, $countDrafts($bill->id()));

                $pdo->prepare('DELETE FROM bills WHERE id = :id')->execute(['id' => $bill->id()]);
                Assert::same(0, $countDrafts($bill->id()), 'aggregate children cascade with the bill');

                $statement = $pdo->prepare('SELECT COUNT(*) FROM bill_field_provenance WHERE bill_id = :id');
                $statement->execute(['id' => $bill->id()]);
                Assert::same(0, (int) $statement->fetchColumn());
                $statement = $pdo->prepare('SELECT COUNT(*) FROM bill_validation_results WHERE bill_id = :id');
                $statement->execute(['id' => $bill->id()]);
                Assert::same(0, (int) $statement->fetchColumn());
                $statement = $pdo->prepare('SELECT COUNT(*) FROM bill_quality_flags WHERE bill_id = :id');
                $statement->execute(['id' => $bill->id()]);
                Assert::same(0, (int) $statement->fetchColumn());

                // Now the household is deletable again.
                $pdo->prepare('DELETE FROM household_memberships WHERE household_id = :id')->execute(['id' => $householdId]);
                $pdo->prepare('DELETE FROM households WHERE id = :id')->execute(['id' => $householdId]);
                self::$createdBills = array_values(array_filter(self::$createdBills, static fn (string $id): bool => $id !== $bill->id()));

                // Clean up all remaining created bills from this class so spms_test is clean for downstream suites
                $cleanStmt = $pdo->prepare('DELETE FROM bills WHERE id = :id');
                foreach (self::$createdBills as $cleanId) {
                    $cleanStmt->execute(['id' => $cleanId]);
                }
                self::$createdBills = [];
            }),

            TestResult::check('duplicate same-period import is idempotent and yields one canonical statement (CASE 1)', function (): void {
                [$account, $householdId] = self::fixture();
                $service = self::service();
                $repo = self::repo();

                $bill1 = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));
                $bill1 = $service->markProcessing($account, $bill1->id(), self::now());
                $bill1 = $service->recordExtraction(
                    $account, $bill1->id(),
                    BillExtractionDraft::create($bill1->id(), 'prov', 'm', self::now(), DocumentAssessment::VALID, null, '0.95', self::canonicalValues()),
                    [], [], self::now()
                );

                $bill2 = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));
                $bill2 = $service->markProcessing($account, $bill2->id(), self::now());
                $bill2 = $service->recordExtraction(
                    $account, $bill2->id(),
                    BillExtractionDraft::create($bill2->id(), 'prov', 'm', self::now(), DocumentAssessment::VALID, null, '0.95', self::canonicalValues()),
                    [], [], self::now()
                );

                $list = $repo->listForHousehold($householdId);
                Assert::same(1, count($list), 'two unverified compatible bills for same period deduplicate to one canonical statement');
                Assert::same('2026-08-01', $list[0]->value('billing_period_start'));
                Assert::same('2026-09-01', $list[0]->value('billing_period_end'));
            }),

            TestResult::check('verified same-period record remains authoritative over unverified (CASE 2)', function (): void {
                [$account, $householdId] = self::fixture();
                $service = self::service();
                $repo = self::repo();

                // Bill 1 is verified
                $bill1 = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));
                $bill1 = $service->markProcessing($account, $bill1->id(), self::now());
                $bill1 = $service->recordExtraction(
                    $account, $bill1->id(),
                    BillExtractionDraft::create($bill1->id(), 'prov', 'm', self::now(), DocumentAssessment::VALID, null, '0.95', self::canonicalValues()),
                    [], [], self::now()
                );
                $bill1 = $service->verify($account, $bill1->id(), self::now());

                // Bill 2 is newly extracted for the same period
                $bill2 = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));
                $bill2 = $service->markProcessing($account, $bill2->id(), self::now());
                $bill2 = $service->recordExtraction(
                    $account, $bill2->id(),
                    BillExtractionDraft::create($bill2->id(), 'prov', 'm', self::now(), DocumentAssessment::VALID, null, '0.95', self::canonicalValues()),
                    [], [], self::now()
                );

                $list = $repo->listForHousehold($householdId);
                Assert::same(1, count($list), 'verified record takes precedence as the single canonical statement');
                Assert::same($bill1->id(), $list[0]->id());
                Assert::same(BillStatus::VERIFIED, $list[0]->status());
            }),

            TestResult::check('overlapping but different periods are not incorrectly deduplicated (CASE 3)', function (): void {
                [$account, $householdId] = self::fixture();
                $service = self::service();
                $repo = self::repo();

                $bill1 = self::track($service->create($account, '2026-07-01', '2026-08-01', self::now()));
                $bill2 = self::track($service->create($account, '2026-07-15', '2026-08-15', self::now()));

                $list = $repo->listForHousehold($householdId);
                Assert::same(2, count($list), 'different billing periods must stay distinct');
            }),

            TestResult::check('conflicting same-period records preserve conflict rather than silently overwriting (CASE 4)', function (): void {
                [$account, $householdId] = self::fixture();
                $service = self::service();
                $repo = self::repo();

                $vals1 = self::canonicalValues();
                $vals1['consumption_kwh'] = '145.000';
                $bill1 = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));
                $bill1 = $service->markProcessing($account, $bill1->id(), self::now());
                $bill1 = $service->recordExtraction(
                    $account, $bill1->id(),
                    BillExtractionDraft::create($bill1->id(), 'prov', 'm', self::now(), DocumentAssessment::VALID, null, '0.95', $vals1),
                    [], [], self::now()
                );

                $vals2 = self::canonicalValues();
                $vals2['consumption_kwh'] = '200.000'; // conflicting
                $bill2 = self::track($service->create($account, '2026-08-01', '2026-09-01', self::now()));
                $bill2 = $service->markProcessing($account, $bill2->id(), self::now());
                $bill2 = $service->recordExtraction(
                    $account, $bill2->id(),
                    BillExtractionDraft::create($bill2->id(), 'prov', 'm', self::now(), DocumentAssessment::VALID, null, '0.95', $vals2),
                    [], [], self::now()
                );

                $list = $repo->listForHousehold($householdId);
                Assert::same(2, count($list), 'conflicting unverified statements are not silently merged');
            }),

            TestResult::check('reapStaleProcessing reaps stuck PROCESSING bills to PROCESSING_FAILED after bounded timeout', function (): void {
                [$account, $householdId] = self::fixture();
                $service = self::service();
                $repo = self::repo();
                $pdo = self::pdo();

                $bill = self::track($service->create($account, null, null, self::now()));
                $bill = $service->markProcessing($account, $bill->id(), self::now());

                // Artificially age the processing bill in the DB
                $stmt = $pdo->prepare('UPDATE bills SET created_at = :old1, updated_at = :old2 WHERE id = :id');
                $oldTime = date('Y-m-d H:i:s', time() - 300); // 300 seconds ago (> 180s)
                $stmt->execute(['old1' => $oldTime, 'old2' => $oldTime, 'id' => $bill->id()]);

                $reaped = $repo->reapStaleProcessing($householdId, 180);
                Assert::same(1, $reaped, 'stale processing bill must be reaped');

                $found = $repo->find($bill->id(), $householdId);
                Assert::same(BillStatus::PROCESSING_FAILED, $found?->status(), 'reaped bill must be in PROCESSING_FAILED');
            }),

            TestResult::check('cleanup removes deduplication and reap test bills', function (): void {
                $pdo = self::pdo();
                $cleanStmt = $pdo->prepare('DELETE FROM bills WHERE id = :id');
                foreach (self::$createdBills as $cleanId) {
                    $cleanStmt->execute(['id' => $cleanId]);
                }
                self::$createdBills = [];
            }),
        ];

        return $tests;
    }

    private static function householdOf(Account $account): string
    {
        $context = (new \App\Domain\Household\HouseholdService())->activeHouseholdFor($account);
        Assert::true($context !== null, 'fixture account must have a household');

        return $context['household']->id();
    }
}
