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
