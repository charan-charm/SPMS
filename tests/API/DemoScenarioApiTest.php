<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\DemoScenarioController;
use App\Domain\Account\Account;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\SessionService;
use App\Repositories\AccountRepository;
use App\Repositories\SessionRepository;
use App\Security\AuthContext;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Database;
use App\Support\DataEnvironments;
use App\Support\Request;
use App\Support\RequestId;
use Database\Seeders\DemoScenarioSeeder;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

require_once dirname(__DIR__, 2) . '/database/seeders/DemoScenarioSeeder.php';

/**
 * SPMS D1 — Automated verification of Demo Scenario Credentials & Secure Switcher.
 */
final class DemoScenarioApiTest
{
    private static function controller(): DemoScenarioController
    {
        AuthTesting::configure();
        return new DemoScenarioController();
    }

    private static function request(string $method, string $path, array $body = [], ?Account $as = null, ?array $session = null): Request
    {
        RequestId::set(RequestId::new());
        $req = Request::create($method, $path, ['Content-Type' => 'application/json'], $body, ipAddress: AuthTesting::uniqueIpAddress());
        if ($as !== null) {
            $context = AuthContext::authenticated($as, $session ?? [
                'id' => bin2hex(random_bytes(16)),
                'account_id' => $as->id(),
                'csrf_token' => 'valid-csrf-token',
                'created_at' => gmdate('Y-m-d H:i:s'),
                'last_activity_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
                'absolute_expires_at' => gmdate('Y-m-d H:i:s', time() + 604800),
            ]);
            $req = $req->withAuthContext($context);
        }
        return $req;
    }

    public static function run(): array
    {
        return [
            TestResult::check('Credentials: All five scenarios resolve documented credentials with secure hashing', function (): void {
                $expected = [
                    'SCEN-01' => ['email' => 'demo.surplus@spms.local', 'password' => 'SolarSurplus2026!'],
                    'SCEN-02' => ['email' => 'demo.deficit@spms.local', 'password' => 'EveningDeficit2026!'],
                    'SCEN-03' => ['email' => 'demo.overproduction@spms.local', 'password' => 'Overproduction2026!'],
                    'SCEN-04' => ['email' => 'demo.decline@spms.local', 'password' => 'SolarDecline2026!'],
                    'SCEN-05' => ['email' => 'demo.surge@spms.local', 'password' => 'DemandSurge2026!'],
                ];

                foreach ($expected as $id => $cred) {
                    $resolvedPass = DemoScenarioSeeder::resolveScenarioPassword($cred['email']);
                    Assert::same($cred['password'], $resolvedPass, "{$id} must resolve documented password");

                    $meta = DemoScenarioSeeder::SCENARIOS[$id];
                    Assert::same($cred['email'], $meta['email'], "{$id} email must match documented account");
                    Assert::true(isset($meta['narrative']), "{$id} must have observation narrative");
                }
            }),

            TestResult::check('GET /api/v1/demo/scenarios requires authentication and demo mode', function (): void {
                $ctrl = self::controller();

                // 1. Unauthenticated request fails closed with 401
                $anonReq = self::request('GET', '/api/v1/demo/scenarios');
                try {
                    $ctrl->index($anonReq);
                    Assert::true(false, 'Expected 401 for anonymous request');
                } catch (AppException $e) {
                    Assert::same(401, $e->httpStatus());
                }

                // 2. REAL account mode fails closed with 403
                $realAccount = Account::register(
                    AuthTesting::uniqueEmail(),
                    PasswordPolicy::hash('Password123!Safe'),
                    new \DateTimeImmutable(),
                    Account::MODE_REAL
                );
                $realReq = self::request('GET', '/api/v1/demo/scenarios', as: $realAccount);
                try {
                    $ctrl->index($realReq);
                    Assert::true(false, 'Expected 403 for REAL account');
                } catch (AppException $e) {
                    Assert::same(403, $e->httpStatus());
                    Assert::same('DEMO_MODE_REQUIRED', $e->errorCode());
                }
            }),

            TestResult::check('GET /api/v1/demo/scenarios returns all 5 scenarios with active metadata', function (): void {
                $ctrl = self::controller();
                $demoAccount = Account::register(
                    'demo.surplus@spms.local',
                    PasswordPolicy::hash('SolarSurplus2026!'),
                    new \DateTimeImmutable(),
                    Account::MODE_DEMO_TEST
                );

                $req = self::request('GET', '/api/v1/demo/scenarios', as: $demoAccount);
                $resp = $ctrl->index($req);

                Assert::same(200, $resp->status());
                $body = $resp->body();
                Assert::true($body['success']);
                Assert::same(5, count($body['data']['scenarios']));
                Assert::same('SCEN-01', $body['data']['active_scenario']['id']);
                Assert::same('Solar Surplus Residence', $body['data']['active_scenario']['name']);
                Assert::true(str_contains($body['data']['active_scenario']['narrative'], 'daytime solar surplus'));
            }),

            TestResult::check('POST /api/v1/demo/scenarios/switch validates scenario identifier against strict allowlist', function (): void {
                $ctrl = self::controller();
                $demoAccount = Account::register(
                    'demo.surplus@spms.local',
                    PasswordPolicy::hash('SolarSurplus2026!'),
                    new \DateTimeImmutable(),
                    Account::MODE_DEMO_TEST
                );

                // Unknown scenario identifier rejected with 422
                $invalidReq = self::request('POST', '/api/v1/demo/scenarios/switch', ['scenario_id' => 'SCEN-UNKNOWN'], as: $demoAccount);
                try {
                    $ctrl->switch($invalidReq);
                    Assert::true(false, 'Expected 422 for unknown scenario');
                } catch (AppException $e) {
                    Assert::same(422, $e->httpStatus());
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                }

                // Empty scenario rejected with 422
                $emptyReq = self::request('POST', '/api/v1/demo/scenarios/switch', ['scenario_id' => ''], as: $demoAccount);
                try {
                    $ctrl->switch($emptyReq);
                    Assert::true(false, 'Expected 422 for empty scenario');
                } catch (AppException $e) {
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/demo/scenarios/switch successfully rotates session and switches scenario', function (): void {
                $pdo = Database::connection();
                $passwords = [];
                DemoScenarioSeeder::seed($pdo, $passwords);

                $accountRepo = new AccountRepository();
                $scen01Account = $accountRepo->findByEmail('demo.surplus@spms.local');
                Assert::true($scen01Account !== null);

                // Start an authentic prior session for SCEN-01
                $sessionService = new SessionService();
                $priorSessionResult = $sessionService->start($scen01Account->id());
                $priorSession = $priorSessionResult['session'];

                $ctrl = self::controller();
                $switchReq = self::request(
                    'POST',
                    '/api/v1/demo/scenarios/switch',
                    ['scenario_id' => 'SCEN-02'],
                    as: $scen01Account,
                    session: $priorSession
                );

                $resp = $ctrl->switch($switchReq);
                Assert::same(200, $resp->status());
                $body = $resp->body();

                Assert::true($body['success']);
                Assert::true($body['data']['switched']);
                Assert::same('SCEN-02', $body['data']['active_scenario']['id']);
                Assert::same($scen01Account->email(), $body['data']['account']['email']);
                Assert::same('DEMO — Persistent Grid Deficit & High Demand', $body['data']['household']['label']);
                Assert::true(isset($body['data']['session']['csrf_token']), 'Must return fresh CSRF token');

                // Must never return password hashes or raw secrets
                Assert::false(isset($body['data']['account']['password_hash']));
                Assert::false(isset($body['data']['account']['password']));

                // Verify Set-Cookie header was issued
                $headers = $resp->headers();
                Assert::true(isset($headers['Set-Cookie']));
                Assert::true(str_contains($headers['Set-Cookie'], 'spms_'));

                // Verify prior session was revoked (session fixation defense)
                $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = :id');
                $stmt->execute([':id' => (string) $priorSession['id']]);
                $priorRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                Assert::true($priorRow !== false && $priorRow !== null);
                Assert::true($priorRow['revoked_at'] !== null, 'Prior session must be revoked after switch');

                // Reseed to restore pristine baseline for subsequent test suites
                DemoScenarioSeeder::seed($pdo, $passwords);
            }),

            TestResult::check('Switching fails closed in REAL profile even if request provides demo body', function (): void {
                $ctrl = self::controller();
                $demoAccount = Account::register(
                    'demo.surplus@spms.local',
                    PasswordPolicy::hash('SolarSurplus2026!'),
                    new \DateTimeImmutable(),
                    Account::MODE_DEMO_TEST
                );

                // Switch database current profile to real
                Database::switchTo('real');
                try {
                    $req = self::request('POST', '/api/v1/demo/scenarios/switch', ['scenario_id' => 'SCEN-02'], as: $demoAccount);
                    try {
                        $ctrl->switch($req);
                        Assert::true(false, 'Expected 403 in real profile');
                    } catch (AppException $e) {
                        Assert::same(403, $e->httpStatus());
                        Assert::same('REFUSED_IN_REAL_ENVIRONMENT', $e->errorCode());
                    }
                } finally {
                    Database::switchTo('test');
                }
            }),

            TestResult::check('Canonical DEMO operator can switch across multiple scenarios without re-login', function (): void {
                $pdo = Database::connection();
                $passwords = [];
                DemoScenarioSeeder::seed($pdo, $passwords);

                $accountRepo = new AccountRepository();
                $operatorAccount = $accountRepo->findByEmail('demo.operator@spms.local');
                Assert::true($operatorAccount !== null, 'Canonical demo operator must exist');

                $sessionService = new SessionService();
                $sessionResult = $sessionService->start($operatorAccount->id());
                $session = $sessionResult['session'];

                $ctrl = self::controller();

                try {
                    // Switch to SCEN-03
                    $switch3Req = self::request(
                        'POST',
                        '/api/v1/demo/scenarios/switch',
                        ['scenario_id' => 'SCEN-03'],
                        as: $operatorAccount,
                        session: $session
                    );
                    $resp3 = $ctrl->switch($switch3Req);
                    Assert::same(200, $resp3->status());
                    $body3 = $resp3->body();
                    Assert::same('SCEN-03', $body3['data']['active_scenario']['id']);
                    Assert::same('demo.operator@spms.local', $body3['data']['account']['email']);
                    Assert::same('DEMO — Sustained Solar Surplus', $body3['data']['household']['label']);

                    // Switch to SCEN-05 using rotated session
                    $session = $body3['data']['session'];
                    $switch5Req = self::request(
                        'POST',
                        '/api/v1/demo/scenarios/switch',
                        ['scenario_id' => 'SCEN-05'],
                        as: $operatorAccount,
                        session: $session
                    );
                    $resp5 = $ctrl->switch($switch5Req);
                    Assert::same(200, $resp5->status());
                    $body5 = $resp5->body();
                    Assert::same('SCEN-05', $body5['data']['active_scenario']['id']);
                    Assert::same('demo.operator@spms.local', $body5['data']['account']['email']);
                    Assert::same('DEMO — Consumption Surge & Anomaly', $body5['data']['household']['label']);
                } finally {
                    $pdo->prepare('DELETE FROM household_memberships WHERE account_id = :aid')->execute([':aid' => $operatorAccount->id()]);
                    DemoScenarioSeeder::seed($pdo, $passwords);
                }
            }),
        ];
    }
}
