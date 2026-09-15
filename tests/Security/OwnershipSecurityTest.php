<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\HouseholdController;
use App\Controllers\IntelligenceController;
use App\Domain\Account\Account;
use App\Domain\Auth\SessionService;
use App\Domain\Household\Household;
use App\Repositories\AccountRepository;
use App\Repositories\HouseholdRepository;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W3 — Ownership security properties (docs/10 D-050, D-055, D-056;
 * docs/01 §31). Everything derived server-side; nothing trusted from
 * the client; non-active states never resolve.
 */
final class OwnershipSecurityTest
{
    private static function listAction(Request $request): void
    {
        (new IntelligenceController())->list($request);
    }

    private static function showAction(Request $request): void
    {
        (new IntelligenceController())->show($request);
    }

    public static function run(): array
    {
        return [
            TestResult::check('Cross-household insight detail is indistinguishable from a nonexistent id', function (): void {
                RequestId::set(RequestId::new());
                $owner = AuthTesting::householdFixture();
                $intruder = AuthTesting::householdFixture();

                // Persist an insight owned by the first household through
                // the internal seam (repository-level, auth-independent).
                $repository = new \App\Repositories\InsightRepository();
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $evidence = \App\Domain\Intelligence\InsightEvidenceInput::fromArray([
                    'household_id' => $owner['household_id'],
                    'balance' => [
                        (new \App\Domain\Energy\EnergyBalanceService())->classify(
                            [
                                'solar' => ['value_kwh' => 5.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                                'consumption' => ['value_kwh' => 3.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                            ],
                            'TIME_PERIOD',
                            ['start' => '2026-09-04T12:00:00', 'end' => '2026-09-04T12:00:00', 'resolution' => 'HOUR']
                        ),
                        (new \App\Domain\Energy\EnergyBalanceService())->classify(
                            [
                                'solar' => ['value_kwh' => 1.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                                'consumption' => ['value_kwh' => 4.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                            ],
                            'TIME_PERIOD',
                            ['start' => '2026-09-04T19:00:00', 'end' => '2026-09-04T19:00:00', 'resolution' => 'HOUR']
                        ),
                    ],
                ]);
                $candidates = (new \App\Domain\Intelligence\InsightEngine())->evaluate($evidence, $now);
                Assert::true(count($candidates) >= 1, 'evidence must produce a household-scoped insight');
                $secretInsight = $repository->upsert($candidates[0], $now);

                $codes = [];
                foreach ([$secretInsight->id(), '00000000-0000-4000-8000-0000000000ff'] as $id) {
                    try {
                        self::showAction(AuthTesting::authed(
                            Request::create('GET', '/api/v1/intelligence/insights/' . $id),
                            $intruder['account']
                        ));
                        Assert::true(false, 'Expected denial for ' . $id);
                    } catch (AppException $e) {
                        $codes[$id] = [$e->errorCode(), $e->httpStatus(), $e->getMessage()];
                    }
                }

                Assert::same($codes['00000000-0000-4000-8000-0000000000ff'], $codes[$secretInsight->id()], 'ownership denial must not reveal existence');

                // The owner still reads the same id fine — only the
                // intruder's view was tested above.
                (new IntelligenceController())->show(AuthTesting::authed(
                    Request::create('GET', '/api/v1/intelligence/insights/' . $secretInsight->id()),
                    $owner['account']
                ));
                Assert::true(true, 'owner detail reached without exception');
            }),

            TestResult::check('Foreign household claims are rejected with a data-free 403', function (): void {
                RequestId::set(RequestId::new());
                $a = AuthTesting::householdFixture();
                $b = AuthTesting::householdFixture();

                foreach ([$b['household_id'], "b' OR '1'='1", str_repeat('f', 40)] as $claim) {
                    try {
                        self::listAction(AuthTesting::authed(
                            Request::create('GET', '/api/v1/intelligence/insights', query: ['household_id' => $claim]),
                            $a['account']
                        ));
                        Assert::true(false, 'Expected 403 for claim ' . substr($claim, 0, 8));
                    } catch (AppException $e) {
                        Assert::same('HOUSEHOLD_ACCESS_DENIED', $e->errorCode());
                        Assert::same(403, $e->httpStatus());
                        Assert::notContains('insight', strtolower($e->getMessage()), 'denial messages carry no data payload');
                    }
                }
            }),

            TestResult::check('DELETION_PENDING and DELETED households never resolve as active context', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $repository = new HouseholdRepository();

                foreach ([Household::STATUS_DELETION_PENDING, Household::STATUS_DELETED] as $state) {
                    $household = $repository->findById($fixture['household_id']);
                    Assert::true($household !== null);
                    $repository->updateStatus($household->withStatus(
                        $state,
                        new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                    ));

                    $context = (new \App\Domain\Household\HouseholdService())->activeHouseholdFor($fixture['account']);
                    Assert::same(null, $context, $state . ' household must not resolve as active');

                    $response = (new HouseholdController())->current(AuthTesting::authed(
                        Request::create('GET', '/api/v1/household'),
                        $fixture['account']
                    ));
                    Assert::same(null, $response->body()['data']['household'], $state . ' household context is null');
                }

                // Restore ACTIVE and the context resolves again (no silent loss).
                $household = $repository->findById($fixture['household_id']);
                $repository->updateStatus($household->withStatus(Household::STATUS_ACTIVE, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
                $context = (new \App\Domain\Household\HouseholdService())->activeHouseholdFor($fixture['account']);
                Assert::true($context !== null, 'restored household context resolves');
            }),

            TestResult::check('A session whose account left ACTIVE state cannot reach household data', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];
                $accounts = new AccountRepository();
                $service = new SessionService();

                foreach ([Account::STATUS_DISABLED, Account::STATUS_DELETION_PENDING] as $state) {
                    // A fresh live session per state (a revoked session from
                    // the previous iteration would pass for the wrong reason).
                    $started = $service->start($account->id(), null);
                    $rawToken = (string) $started['raw_token'];

                    $fresh = $accounts->findById($account->id());
                    Assert::true($fresh !== null);
                    $accounts->updateStatus($fresh->withStatus(
                        $state,
                        $fresh->toStorageRow()['email_verified_at'] ?? '2026-09-12 00:00:00',
                        new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                    ));

                    $request = AuthMiddleware::process(Request::create(
                        'GET',
                        '/api/v1/intelligence/insights',
                        headers: ['Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $rawToken]
                    ));

                    try {
                        self::listAction($request);
                        Assert::true(false, $state . ' account still reached household data');
                    } catch (AppException $e) {
                        Assert::same('UNAUTHENTICATED', $e->errorCode());
                        Assert::same(401, $e->httpStatus());
                    }

                    // The live session was revoked, not merely ignored.
                    Assert::same(null, $service->resolve($rawToken, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))), 'stale-eligible session revoked at the source');

                    // Restore ACTIVE for the next state.
                    $current = $accounts->findById($account->id());
                    $accounts->updateStatus($current->withStatus(
                        Account::STATUS_ACTIVE,
                        '2026-09-12 00:00:00',
                        new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                    ));
                }
            }),

            TestResult::check('ADMIN receives no cross-household reach beyond its memberships', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                $admin = AuthTesting::activateAccount();
                $victim = AuthTesting::householdFixture();

                $accounts = new AccountRepository();
                $pdo = \App\Support\Database::connection();
                $pdo->prepare("UPDATE accounts SET role = 'ADMIN' WHERE id = :id")->execute(['id' => $admin->id()]);

                // Admin with no household: system scope only.
                $response = (new IntelligenceController())->list(AuthTesting::authed(
                    Request::create('GET', '/api/v1/intelligence/insights'),
                    $admin
                ));
                foreach ($response->body()['data']['insights'] as $insight) {
                    Assert::same(null, $insight['household_id'], 'role ADMIN must not expand scope');
                }

                // Admin claiming a victim household: still denied — role is
                // not an ownership grant (D-055: ADMIN uses explicit
                // capabilities, never implicit household bypass).
                try {
                    (new IntelligenceController())->list(AuthTesting::authed(
                        Request::create('GET', '/api/v1/intelligence/insights', query: ['household_id' => $victim['household_id']]),
                        $admin
                    ));
                    Assert::true(false, 'Expected 403: ADMIN must not bypass membership');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_ACCESS_DENIED', $e->errorCode());
                }

                try {
                    (new IntelligenceController())->show(AuthTesting::authed(
                        Request::create('GET', '/api/v1/intelligence/insights/00000000-0000-4000-8000-0000000000fe'),
                        $admin
                    ));
                    Assert::true(false, 'Expected 404');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                }

                // Restore the fixture row to USER semantics is unnecessary:
                // role was only ever set on this throwaway account.
                unset($accounts);
            }),
        ];
    }
}
