<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\BillController;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillExtractionDraft;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\QualityCode;
use App\Domain\Bill\ServiceNumber;
use App\Repositories\BillRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class BillVerificationApiTest
{
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-21 14:00:00', new DateTimeZone('UTC'));
    }

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    private static function controller(): BillController
    {
        AuthTesting::configure();

        return new BillController();
    }

    /** @var list<string> */
    private static array $createdBillIds = [];

    private static function createBillWithDraft(
        string $householdId,
        string $start,
        string $end,
        ?string $serviceNumber = null
    ): Bill {
        $bill = Bill::create($householdId, $start, $end, self::now())
            ->transitionTo(BillStatus::PROCESSING, self::now())
            ->transitionTo(BillStatus::EXTRACTED, self::now());

        if ($serviceNumber !== null) {
            $bill = $bill->withValues(['service_number' => $serviceNumber], self::now());
        }

        $repo = new BillRepository(self::pdo());
        $repo->insert($bill);
        self::$createdBillIds[] = $bill->id();

        $draft = BillExtractionDraft::create(
            $bill->id(),
            'manual_test',
            'test-model',
            self::now(),
            DocumentAssessment::VALID,
            null,
            '0.9500',
            $bill->values(),
            []
        );
        $repo->insertDraft($draft);

        return $bill;
    }

    public static function run(): array
    {
        return [
            TestResult::check('POST /api/v1/bills/{id}/verify requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->verify(Request::create('POST', '/api/v1/bills/018b0000-0000-7000-8000-000000000001/verify'));
                    Assert::true(false, 'Expected 401 UNAUTHENTICATED');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/bills/{id}/verify returns 404 for unknown bill', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('v-404-' . bin2hex(random_bytes(3)));

                try {
                    $req = AuthTesting::authed(Request::create('POST', '/api/v1/bills/018b0000-0000-7000-8000-000000000002/verify'), $fixture['account']);
                    self::controller()->verify($req);
                    Assert::true(false, 'Expected 404 NOT_FOUND');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/bills/{id}/verify rejects bills with SERVICE_NUMBER_MISMATCH', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('v-mismatch-' . bin2hex(random_bytes(3)), '10002000300');
                $bill = self::createBillWithDraft($fixture['household_id'], '2024-01-01', '2024-01-31', '99999999999');

                // Attach mismatch flag
                $repo = new BillRepository(self::pdo());
                $flag = BillQualityFlag::create(QualityCode::SERVICE_NUMBER_MISMATCH, 'service_number');
                $repo->recordQualityFlag($bill->id(), $flag, self::now()->format('Y-m-d H:i:s'));

                try {
                    $req = AuthTesting::authed(Request::create('POST', '/api/v1/bills/' . $bill->id() . '/verify'), $fixture['account']);
                    self::controller()->verify($req);
                    Assert::true(false, 'Expected 422 OWNERSHIP_MISMATCH');
                } catch (AppException $e) {
                    Assert::same('OWNERSHIP_MISMATCH', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/bills/{id}/verify detects sequence gap and enforces exception acknowledgement', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('v-gap-' . bin2hex(random_bytes(3)), '10002000300');

                // Create first verified bill
                $bill1 = self::createBillWithDraft($fixture['household_id'], '2024-01-01', '2024-01-31', '10002000300');
                $billService = new BillService();
                $billService->verify($fixture['account'], $bill1->id(), self::now(), false);

                // Create second bill with a gap: starts 2024-04-01 (skipping Feb & Mar)
                $bill2 = self::createBillWithDraft($fixture['household_id'], '2024-04-01', '2024-04-30', '10002000300');

                // 1. Verify without accept_gap: true must fail with 422
                try {
                    $req = AuthTesting::authed(Request::create('POST', '/api/v1/bills/' . $bill2->id() . '/verify', body: ['accept_gap' => false]), $fixture['account']);
                    self::controller()->verify($req);
                    Assert::true(false, 'Expected 422 BILLING_GAP_DETECTED');
                } catch (AppException $e) {
                    Assert::same('BILLING_GAP_DETECTED', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }

                // 2. Verify with accept_gap: true succeeds and attaches INCOMPLETE_HISTORY
                $reqAccept = AuthTesting::authed(Request::create('POST', '/api/v1/bills/' . $bill2->id() . '/verify', body: ['accept_gap' => true]), $fixture['account']);
                $response = self::controller()->verify($reqAccept);

                Assert::same(200, $response->status());
                Assert::same(BillStatus::VERIFIED, $response->body()['data']['status']);

                $detail = self::controller()->show(AuthTesting::authed(Request::create('GET', '/api/v1/bills/' . $bill2->id()), $fixture['account']));
                $flags = $detail->body()['data']['bill']['quality_flags'] ?? [];
                $hasIncompleteHistory = false;
                foreach ($flags as $f) {
                    if ($f['code'] === QualityCode::INCOMPLETE_HISTORY) {
                        $hasIncompleteHistory = true;
                    }
                }
                Assert::true($hasIncompleteHistory, 'INCOMPLETE_HISTORY flag must be attached when gap is accepted');
            }),

            TestResult::check('POST /api/v1/bills/{id}/verify rejects duplicate billing period with 409', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('v-dup-' . bin2hex(random_bytes(3)), '10002000300');

                // First bill: Jan 2024 verified
                $bill1 = self::createBillWithDraft($fixture['household_id'], '2024-01-01', '2024-01-31', '10002000300');
                $billService = new BillService();
                $billService->verify($fixture['account'], $bill1->id(), self::now(), false);

                // Second bill covering a different period initially
                $billDup = self::createBillWithDraft($fixture['household_id'], '2024-02-01', '2024-02-28', '10002000300');

                // Attempting to change billDup to the exact same period as bill1 is rejected with 409
                try {
                    $req = AuthTesting::authed(Request::create('PATCH', '/api/v1/bills/' . $billDup->id(), body: [
                        'billing_period_start' => '2024-01-01',
                        'billing_period_end' => '2024-01-31',
                    ]), $fixture['account']);
                    self::controller()->update($req);
                    Assert::true(false, 'Expected 409 DUPLICATE_BILL_PERIOD');
                } catch (AppException $e) {
                    Assert::same('DUPLICATE_BILL_PERIOD', $e->errorCode());
                    Assert::same(409, $e->httpStatus());
                }
            }),

            TestResult::check('PATCH /api/v1/bills/{id} updates values and records USER_CORRECTION provenance', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('v-patch-' . bin2hex(random_bytes(3)), '10002000300');
                $bill = self::createBillWithDraft($fixture['household_id'], '2024-05-01', '2024-05-31', '10002000300');

                $req = AuthTesting::authed(Request::create('PATCH', '/api/v1/bills/' . $bill->id(), body: [
                    'consumption_kwh' => '350.000',
                    'bill_amount' => '1200.00',
                ]), $fixture['account']);

                $response = self::controller()->update($req);
                Assert::same(200, $response->status());
                $values = $response->body()['data']['values'];
                Assert::same('350.000', $values['consumption_kwh']);
                Assert::same('1200.00', $values['bill_amount']);

                // Verify provenance recorded as USER_CORRECTION
                $detail = self::controller()->show(AuthTesting::authed(Request::create('GET', '/api/v1/bills/' . $bill->id()), $fixture['account']));
                $provenance = $detail->body()['data']['bill']['provenance'] ?? [];
                Assert::same('USER_CORRECTION', $provenance['consumption_kwh']['source'] ?? null);
                Assert::same('USER_CORRECTION', $provenance['bill_amount']['source'] ?? null);
            }),

            TestResult::check('PATCH /api/v1/bills/{id} rejects anonymous callers with 401', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->update(Request::create('PATCH', '/api/v1/bills/018b0000-0000-7000-8000-000000000001', body: ['consumption_kwh' => '100.000']));
                    Assert::true(false, 'Expected 401 UNAUTHENTICATED');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('PATCH /api/v1/bills/{id} rejects cross-household attempt with 404', function (): void {
                RequestId::set(RequestId::new());
                $fixtureA = AuthTesting::householdFixture('patch-cross-a-' . bin2hex(random_bytes(3)));
                $fixtureB = AuthTesting::householdFixture('patch-cross-b-' . bin2hex(random_bytes(3)));
                $billA = self::createBillWithDraft($fixtureA['household_id'], '2024-06-01', '2024-06-30');

                try {
                    $req = AuthTesting::authed(Request::create('PATCH', '/api/v1/bills/' . $billA->id(), body: ['consumption_kwh' => '200.000']), $fixtureB['account']);
                    self::controller()->update($req);
                    Assert::true(false, 'Expected 404 NOT_FOUND for cross-household bill patch');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }),

            TestResult::check('PATCH /api/v1/bills/{id} rejects read-only fields under canonical field policy', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('patch-readonly-' . bin2hex(random_bytes(3)));
                $bill = self::createBillWithDraft($fixture['household_id'], '2024-07-01', '2024-07-31');

                try {
                    $req = AuthTesting::authed(Request::create('PATCH', '/api/v1/bills/' . $bill->id(), body: [
                        'source' => 'MANUAL_OVERWRITE',
                    ]), $fixture['account']);
                    self::controller()->update($req);
                    Assert::true(false, 'Expected 422 READ_ONLY_FIELD');
                } catch (AppException $e) {
                    Assert::same('READ_ONLY_FIELD', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('PATCH /api/v1/bills/{id} rejects invalid numeric values with 422', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('patch-badnum-' . bin2hex(random_bytes(3)));
                $bill = self::createBillWithDraft($fixture['household_id'], '2024-08-01', '2024-08-31');

                try {
                    $req = AuthTesting::authed(Request::create('PATCH', '/api/v1/bills/' . $bill->id(), body: [
                        'consumption_kwh' => 'invalid-energy-units',
                    ]), $fixture['account']);
                    self::controller()->update($req);
                    Assert::true(false, 'Expected 422 VALIDATION_ERROR');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('user correction preserves immutable draft, revalidates net units conflict, transitions to EXTRACTED without auto-verifying, then verifies successfully', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('patch-reval-' . bin2hex(random_bytes(3)), '8623310010998');

                // Simulate October extraction case:
                // Meter: 1460 -> 1663 (consumption: 203)
                // Solar: 2200 -> 2503 (solar meter delta: 303)
                // Extracted candidate solar units: 177.000 (wrong candidate)
                // Net units: -100.000
                // Net validation: 203 - 177 = 26 != -100 (CONFLICT -> INCONSISTENT_NET_UNITS, NEEDS_REVIEW)
                $bill = Bill::create($fixture['household_id'], '2026-09-01', '2026-10-01', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->withValues([
                        'consumption_kwh' => '203.000',
                        'previous_meter_reading' => '1460.000',
                        'present_meter_reading' => '1663.000',
                        'previous_solar_reading' => '2200.000',
                        'present_solar_reading' => '2503.000',
                        'solar_units_kwh' => '177.000',
                        'net_units_kwh' => '-100.000',
                        'bill_amount' => '149.00',
                        'service_number' => '8623310010998',
                    ], self::now())
                    ->transitionTo(BillStatus::NEEDS_REVIEW, self::now());

                $repo = new BillRepository(self::pdo());
                $repo->insert($bill);
                self::$createdBillIds[] = $bill->id();

                // Save initial draft and conflicting validation
                $draft = BillExtractionDraft::create(
                    $bill->id(),
                    'mistral',
                    'mistral-ocr-2503',
                    self::now(),
                    DocumentAssessment::VALID_WITH_LIMITATIONS,
                    'inconsistent net units',
                    '0.9500',
                    $bill->values(),
                    []
                );
                $repo->insertDraft($draft);
                $repo->upsertValidation($bill->id(), \App\Domain\Bill\BillValidation::create(
                    \App\Domain\Bill\ValidationRule::NET_UNITS,
                    \App\Domain\Bill\ValidationStatus::CONFLICT,
                    '26.000',
                    '-100.000'
                ), self::now()->format('Y-m-d H:i:s'));
                $repo->recordQualityFlag($bill->id(), BillQualityFlag::create(QualityCode::INCONSISTENT_NET_UNITS, 'net_units_kwh'), self::now()->format('Y-m-d H:i:s'));

                // Verify initial state: NEEDS_REVIEW with net conflict
                $detailBefore = self::controller()->show(AuthTesting::authed(Request::create('GET', '/api/v1/bills/' . $bill->id()), $fixture['account']));
                Assert::same(BillStatus::NEEDS_REVIEW, $detailBefore->body()['data']['bill']['status']);

                // USER CORRECTS solar_units_kwh to 303.000 (authoritative solar export from meter delta 2503 - 2200)
                // Now: 203 - 303 = -100.000 (matches net_units_kwh)!
                $reqPatch = AuthTesting::authed(Request::create('PATCH', '/api/v1/bills/' . $bill->id(), body: [
                    'solar_units_kwh' => '303.000',
                ]), $fixture['account']);
                $patchRes = self::controller()->update($reqPatch);

                Assert::same(200, $patchRes->status());
                Assert::same('303.000', $patchRes->body()['data']['values']['solar_units_kwh']);

                // Bill status transitioned from NEEDS_REVIEW to EXTRACTED because conflict resolved!
                Assert::same(BillStatus::EXTRACTED, $patchRes->body()['data']['status']);
                // Crucial requirement: correction does NOT automatically mark bill VERIFIED
                Assert::true($patchRes->body()['data']['status'] !== BillStatus::VERIFIED);

                // Fetch full detail to verify draft immutability and revalidation outcome
                $detailAfter = self::controller()->show(AuthTesting::authed(Request::create('GET', '/api/v1/bills/' . $bill->id()), $fixture['account']));
                $billData = $detailAfter->body()['data']['bill'];

                // 1. Immutable original draft is preserved
                Assert::same('177.000', $billData['extraction']['raw_values']['solar_units_kwh'], 'Draft raw value must retain 177.000');
                // 2. Current value reflects user correction
                Assert::same('303.000', $billData['values']['solar_units_kwh']);
                // 3. Provenance marks user correction
                Assert::same('USER_CORRECTION', $billData['provenance']['solar_units_kwh']['source']);
                // 4. NET_UNITS validation now passes
                $netVal = null;
                foreach ($billData['validations'] as $v) {
                    if ($v['rule'] === \App\Domain\Bill\ValidationRule::NET_UNITS) {
                        $netVal = $v;
                    }
                }
                Assert::true($netVal !== null && $netVal['status'] === \App\Domain\Bill\ValidationStatus::PASS, 'NET_UNITS validation must PASS after correction');
                // 5. INCONSISTENT_NET_UNITS quality flag cleared
                $flags = array_column($billData['quality_flags'], 'code');
                Assert::false(in_array(QualityCode::INCONSISTENT_NET_UNITS, $flags, true), 'INCONSISTENT_NET_UNITS flag must be cleared');

                // Now user explicitly verifies the bill
                $reqVerify = AuthTesting::authed(Request::create('POST', '/api/v1/bills/' . $bill->id() . '/verify'), $fixture['account']);
                $verifyRes = self::controller()->verify($reqVerify);
                Assert::same(200, $verifyRes->status());
                Assert::same(BillStatus::VERIFIED, $verifyRes->body()['data']['status']);

                // Protected: Verified bill cannot be edited anymore
                try {
                    $reqPostVerifyPatch = AuthTesting::authed(Request::create('PATCH', '/api/v1/bills/' . $bill->id(), body: [
                        'solar_units_kwh' => '350.000',
                    ]), $fixture['account']);
                    self::controller()->update($reqPostVerifyPatch);
                    Assert::true(false, 'Expected 409 INVALID_BILL_STATE for verified bill correction');
                } catch (AppException $e) {
                    Assert::same('INVALID_BILL_STATE', $e->errorCode());
                    Assert::same(409, $e->httpStatus());
                }
            }),

            TestResult::check('cleanup removes verification test bills', function (): void {
                $pdo = self::pdo();
                foreach (self::$createdBillIds as $id) {
                    foreach ([
                        'DELETE FROM bill_quality_flags WHERE bill_id = :id',
                        'DELETE FROM bill_validation_results WHERE bill_id = :id',
                        'DELETE FROM bill_field_provenance WHERE bill_id = :id',
                        'DELETE FROM bill_extraction_drafts WHERE bill_id = :id',
                        'DELETE FROM bill_temp_documents WHERE bill_id = :id',
                        'DELETE FROM bills WHERE id = :id',
                    ] as $sql) {
                        $pdo->prepare($sql)->execute(['id' => $id]);
                    }
                }
                self::$createdBillIds = [];
                Assert::true(true);
            }),
        ];
    }
}
