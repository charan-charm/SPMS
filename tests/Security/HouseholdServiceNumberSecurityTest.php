<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\HouseholdController;
use App\Controllers\BillController;
use App\Domain\Account\Account;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\Ownership;
use App\Domain\Bill\QualityCode;
use App\Domain\Bill\ServiceNumber;
use App\Domain\Household\Household;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Repositories\HouseholdRepository;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Database;
use App\Support\DataEnvironments;
use App\Support\Request;
use App\Support\RequestId;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class HouseholdServiceNumberSecurityTest
{
    private static function controller(): HouseholdController
    {
        AuthTesting::configure();

        return new HouseholdController();
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    public static function run(): array
    {
        AuthTesting::configure();

        return [
            TestResult::check('Authenticated household can update its own service number with canonical format', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $repo = new HouseholdRepository();

                $syntheticService = 'SYNTH10002000300';
                $request = AuthTesting::authed(
                    Request::create('PATCH', '/api/v1/household', body: [
                        'service_number' => 'synth 1000-2000-300', // formatting with spaces and hyphens
                    ]),
                    $fixture['account']
                );

                $response = self::controller()->update($request);
                Assert::same(200, $response->status());
                Assert::same($syntheticService, $response->body()['data']['household']['service_number']);

                // Verify in database
                $stored = $repo->findById($fixture['household_id']);
                Assert::true($stored !== null);
                Assert::same($syntheticService, $stored->serviceNumber(), 'Service number must be stored in canonical format');
                Assert::same($fixture['household_id'], $stored->id(), 'Household id is preserved');
                Assert::same('ACTIVE', $stored->status(), 'Household status is preserved');
            }),

            TestResult::check('Unauthenticated update is rejected with 401', function (): void {
                RequestId::set(RequestId::new());
                $request = Request::create('PATCH', '/api/v1/household', body: [
                    'service_number' => 'SYNTH10002000300',
                ]);

                try {
                    self::controller()->update($request);
                    Assert::true(false, 'Expected 401 for unauthenticated update');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('User cannot update another household (ownership derived server-side)', function (): void {
                RequestId::set(RequestId::new());
                $userA = AuthTesting::householdFixture();
                $userB = AuthTesting::householdFixture();
                $repo = new HouseholdRepository();

                $bInitialService = 'SYNTHBINITIAL111';
                (new HouseholdService())->updateProfile(
                    $userB['account'],
                    ['service_number' => $bInitialService],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                // User A attempts to update service number
                $requestA = AuthTesting::authed(
                    Request::create('PATCH', '/api/v1/household', body: [
                        'service_number' => 'SYNTHANEW222',
                    ]),
                    $userA['account']
                );

                self::controller()->update($requestA);

                // Verify User B was not affected at all
                $storedB = $repo->findById($userB['household_id']);
                Assert::same('SYNTHBINITIAL111', $storedB->serviceNumber(), 'User B household service number must remain completely unchanged');

                // Verify User A was updated
                $storedA = $repo->findById($userA['household_id']);
                Assert::same('SYNTHANEW222', $storedA->serviceNumber(), 'User A household service number updated');
            }),

            TestResult::check('Forged household_id in body is rejected fail-closed with 422', function (): void {
                RequestId::set(RequestId::new());
                $userA = AuthTesting::householdFixture();
                $userB = AuthTesting::householdFixture();

                $request = AuthTesting::authed(
                    Request::create('PATCH', '/api/v1/household', body: [
                        'household_id' => $userB['household_id'],
                        'service_number' => 'SYNTH10002000300',
                    ]),
                    $userA['account']
                );

                try {
                    self::controller()->update($request);
                    Assert::true(false, 'Expected 422 for unrecognized/forged household_id');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('household_id', $e->context()['field'] ?? null);
                }
            }),

            TestResult::check('Service-number validation is strictly enforced (format and bounds)', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();

                // Too short (< 4 chars)
                try {
                    self::controller()->update(AuthTesting::authed(
                        Request::create('PATCH', '/api/v1/household', body: ['service_number' => 'AB']),
                        $fixture['account']
                    ));
                    Assert::true(false, 'Expected validation error for too short service_number');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }

                // Invalid characters (punctuation not among decorative separators)
                try {
                    self::controller()->update(AuthTesting::authed(
                        Request::create('PATCH', '/api/v1/household', body: ['service_number' => 'ABC!@#$%^']),
                        $fixture['account']
                    ));
                    Assert::true(false, 'Expected validation error for invalid characters');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('Updating household service number reconciles unverified bill ownership without bypass', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];
                $householdId = $fixture['household_id'];
                $billRepo = new BillRepository();
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $timestamp = $now->format('Y-m-d H:i:s');

                // 1. Initial household service number
                $oldSyntheticService = 'SYNTHOLD111111';
                (new HouseholdService())->updateProfile($account, ['service_number' => $oldSyntheticService], $now);

                // 2. Insert a bill with extracted service number that differs from household's old service number
                $billId = self::uuid();
                $extractedService = 'SYNTHNEW999999';

                $pdo = Database::connection();
                $pdo->prepare('INSERT INTO bills (id, household_id, status, source, service_number, billing_period_start, billing_period_end, created_at, updated_at)
                    VALUES (:id, :hh, :st, :src, :sn, :start, :end, NOW(), NOW())')
                    ->execute([
                        'id' => $billId,
                        'hh' => $householdId,
                        'st' => BillStatus::NEEDS_REVIEW,
                        'src' => \App\Domain\Bill\BillSource::ELECTRICITY_BILL,
                        'sn' => $extractedService,
                        'start' => '2026-07-01',
                        'end' => '2026-08-01',
                    ]);

                // Record initial mismatch flag
                $billRepo->recordQualityFlag($billId, BillQualityFlag::create(QualityCode::SERVICE_NUMBER_MISMATCH, BillField::SERVICE_NUMBER), $timestamp);

                // Verify it has mismatch flag
                $flagsBefore = $billRepo->findQualityFlags($billId, $householdId);
                Assert::same(1, count($flagsBefore));
                Assert::same(QualityCode::SERVICE_NUMBER_MISMATCH, $flagsBefore[0]->code());

                // 3. Update the household's service number to match the bill's extracted number
                $response = self::controller()->update(AuthTesting::authed(
                    Request::create('PATCH', '/api/v1/household', body: [
                        'service_number' => $extractedService,
                    ]),
                    $account
                ));
                Assert::same(200, $response->status());

                // 4. Verify bill state:
                // MUST NOT be marked VERIFIED automatically!
                $reloadedBill = $billRepo->find($billId, $householdId);
                Assert::true($reloadedBill !== null);
                Assert::same(BillStatus::NEEDS_REVIEW, $reloadedBill->status(), 'Bill status must remain NEEDS_REVIEW (never auto-verified)');
                Assert::same(null, $reloadedBill->verifiedAt(), 'verified_at must remain null');

                // Ownership mismatch flag MUST be cleared because service numbers now match
                $flagsAfter = $billRepo->findQualityFlags($billId, $householdId);
                Assert::same(0, count($flagsAfter), 'SERVICE_NUMBER_MISMATCH must be resolved');

                // Ownership derivation now resolves to MATCH
                $ownership = Ownership::derive(
                    $reloadedBill->status(),
                    $reloadedBill->values(),
                    $flagsAfter,
                    $extractedService
                );
                Assert::same(Ownership::MATCH, $ownership['status'], 'Ownership derivation must now be MATCH');

                // 5. Verification must still satisfy normal verification flow
                $verifyResponse = (new BillController())->verify(AuthTesting::authed(
                    Request::create('POST', "/api/v1/bills/{$billId}/verify"),
                    $account
                ));
                Assert::same(200, $verifyResponse->status());
                Assert::same(BillStatus::VERIFIED, $verifyResponse->body()['data']['status']);

                $finalBill = $billRepo->find($billId, $householdId);
                Assert::same(BillStatus::VERIFIED, $finalBill->status());
                Assert::true($finalBill->verifiedAt() !== null, 'verified_at stamped on explicit user verification');

                // Clean up test bill so other suites remain isolated
                $pdo->prepare('DELETE FROM bills WHERE id = :id')->execute(['id' => $billId]);
            }),

            TestResult::check('DEMO cannot modify REAL environment', function (): void {
                $pdoReal = new PDO('mysql:host=127.0.0.1;dbname=spms_real', 'root', '');
                $pdoDemo = new PDO('mysql:host=127.0.0.1;dbname=spms_demo', 'root', '');

                // Ensure demo database is distinct from real
                $stmtReal = $pdoReal->query('SELECT DATABASE()');
                $realDbName = $stmtReal->fetchColumn();
                Assert::same('spms_real', $realDbName);

                $stmtDemo = $pdoDemo->query('SELECT DATABASE()');
                $demoDbName = $stmtDemo->fetchColumn();
                Assert::same('spms_demo', $demoDbName);

                // Any write against demo connection affects 0 rows in real
                $testId = 'synth-isolation-' . bin2hex(random_bytes(4));
                $pdoDemo->prepare("INSERT INTO households (id, label, timezone, status, created_at, updated_at)
                    VALUES (:id, 'Demo Iso', 'Asia/Kolkata', 'ACTIVE', NOW(), NOW())")->execute(['id' => $testId]);

                $checkReal = $pdoReal->prepare('SELECT COUNT(*) FROM households WHERE id = :id');
                $checkReal->execute(['id' => $testId]);
                Assert::same(0, (int) $checkReal->fetchColumn(), 'Mutation in DEMO must not exist in REAL');

                // Clean up demo row
                $pdoDemo->prepare('DELETE FROM households WHERE id = :id')->execute(['id' => $testId]);
            }),

            TestResult::check('TEST cannot modify REAL database (cross-environment contamination guard)', function (): void {
                $pdoReal = new PDO('mysql:host=127.0.0.1;dbname=spms_real', 'root', '');
                $pdoTest = Database::connection();

                // Current test connection is strictly spms_test
                $stmtTest = $pdoTest->query('SELECT DATABASE()');
                $testDbName = $stmtTest->fetchColumn();
                Assert::same('spms_test', $testDbName, 'Test suite must run strictly against spms_test');

                // Count households in REAL
                $stmtRealBefore = $pdoReal->query('SELECT COUNT(*) FROM households');
                $realCountBefore = (int) $stmtRealBefore->fetchColumn();

                // Perform fixture creation in TEST
                $fixture = AuthTesting::householdFixture();

                // Count households in REAL again
                $stmtRealAfter = $pdoReal->query('SELECT COUNT(*) FROM households');
                $realCountAfter = (int) $stmtRealAfter->fetchColumn();

                Assert::same($realCountBefore, $realCountAfter, 'TEST activity must never alter row counts in REAL database');
            }),
        ];
    }
}
