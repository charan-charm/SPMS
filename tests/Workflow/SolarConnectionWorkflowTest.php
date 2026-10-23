<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Controllers\HouseholdController;
use App\Controllers\SolarController;
use App\Domain\Solar\SolarService;
use App\Integrations\GoodWe\FakeSolarProvider;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class SolarConnectionWorkflowTest
{
    private static function cookieRequest(string $method, string $path, string $rawSessionToken, array $body = [], array $extraHeaders = []): Request
    {
        return AuthMiddleware::process(Request::create($method, $path, body: $body, headers: array_merge([
            'Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $rawSessionToken,
        ], $extraHeaders), ipAddress: AuthTesting::uniqueIpAddress()));
    }

    /**
     * @return array{token: string, csrf: string, email: string, household_id: string}
     */
    private static function setupUserWithHousehold(): array
    {
        $auth = new AuthController();
        $email = AuthTesting::uniqueEmail();

        $auth->register(Request::create('POST', '/api/v1/auth/register', body: [
            'email' => $email,
            'password' => 'correct-horse-battery',
        ], ipAddress: AuthTesting::uniqueIpAddress()));

        $token = AuthTesting::latestMailCode('Verify your email');
        Assert::true($token !== null, 'verification code delivered');
        $auth->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token]));

        $login = $auth->login(Request::create('POST', '/api/v1/auth/login', body: [
            'email' => $email,
            'password' => 'correct-horse-battery',
        ], ipAddress: AuthTesting::uniqueIpAddress()));

        $sessionToken = AuthTesting::sessionTokenFrom($login);
        $csrf = AuthTesting::csrfFrom($login->body()['data'] ?? []);

        $household = new HouseholdController();
        $created = $household->create(self::cookieRequest(
            'POST',
            '/api/v1/household',
            $sessionToken,
            ['timezone' => 'Asia/Kolkata', 'label' => 'solar-workflow-home'],
            [AuthMiddleware::CSRF_HEADER => $csrf]
        ));

        $householdId = (string) $created->body()['data']['household']['id'];

        return [
            'token' => $sessionToken,
            'csrf' => $csrf,
            'email' => $email,
            'household_id' => $householdId,
        ];
    }

    public static function run(): array
    {
        return [
            TestResult::check('Anonymous requests to solar endpoints fail with 401', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                $controller = new SolarController();

                Assert::throws(AppException::class, function () use ($controller): void {
                    $controller->connection(Request::create('GET', '/api/v1/solar/connection'));
                });

                Assert::throws(AppException::class, function () use ($controller): void {
                    $controller->latest(Request::create('GET', '/api/v1/solar/latest'));
                });
            }),

            TestResult::check('Solar connection lifecycle: probe → save → sync → telemetry → readiness → disconnect', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                Config::overrideForTesting(['SOLAR_PROVIDER' => 'fake']);

                $session = self::setupUserWithHousehold();
                $fake = new FakeSolarProvider();
                $solarService = new SolarService(provider: $fake);
                $solar = new SolarController($solarService);
                $household = new HouseholdController();

                // 1. Initially, no solar connection configured
                $initialConn = $solar->connection(self::cookieRequest('GET', '/api/v1/solar/connection', $session['token']))->body()['data']['connection'];
                Assert::same(false, $initialConn['configured']);
                Assert::same('NOT_CONFIGURED', $initialConn['connection_status']);

                // Initial onboarding readiness shows UNAVAILABLE
                $initialReadiness = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']))->body()['data']['readiness'];
                Assert::same('UNAVAILABLE', $initialReadiness['solar']);

                // 2. Test connection with invalid credentials reports failure
                $testInvalid = $solar->testConnection(self::cookieRequest(
                    'POST',
                    '/api/v1/solar/connection/test',
                    $session['token'],
                    [
                        'account' => 'invalid@example.com',
                        'password' => 'wrong_password',
                    ],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ))->body()['data']['test_result'];

                Assert::same(false, $testInvalid['success']);
                Assert::same('AUTH_FAILED', $testInvalid['status']);

                // 3. Test connection with zero stations found
                $testZero = $solar->testConnection(self::cookieRequest(
                    'POST',
                    '/api/v1/solar/connection/test',
                    $session['token'],
                    [
                        'account' => 'zero_stations@example.com',
                        'password' => 'valid_password',
                    ],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ))->body()['data']['test_result'];

                Assert::same(false, $testZero['success']);
                Assert::same('NO_STATIONS_FOUND', $testZero['status']);
                Assert::same([], $testZero['stations']);

                // 4. Test connection with multiple stations found prompts selection
                $testMulti = $solar->testConnection(self::cookieRequest(
                    'POST',
                    '/api/v1/solar/connection/test',
                    $session['token'],
                    [
                        'account' => 'multi_stations@example.com',
                        'password' => 'valid_password',
                    ],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ))->body()['data']['test_result'];

                Assert::same(false, $testMulti['success']);
                Assert::same('MULTIPLE_STATIONS', $testMulti['status']);
                Assert::same(2, count($testMulti['stations']));
                Assert::same('st-alpha-1', $testMulti['stations'][0]['station_id']);
                Assert::same('Farm House PV', $testMulti['stations'][0]['station_name']);

                // 5. Dedicated discover endpoint works
                $discResp = $solar->discoverStations(self::cookieRequest(
                    'POST',
                    '/api/v1/solar/stations/discover',
                    $session['token'],
                    [
                        'account' => 'multi_stations@example.com',
                        'password' => 'valid_password',
                    ],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ))->body()['data'];
                Assert::same(2, $discResp['count']);

                // 6. Test connection with single station auto-discovery (NO station_id provided!)
                $testSingle = $solar->testConnection(self::cookieRequest(
                    'POST',
                    '/api/v1/solar/connection/test',
                    $session['token'],
                    [
                        'account' => 'my.solar@example.com',
                        'password' => 'solarSecret123!',
                    ],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ))->body()['data']['test_result'];

                Assert::same(true, $testSingle['success']);
                Assert::same('CONNECTED', $testSingle['status']);
                Assert::same('goodwe-station-98765', $testSingle['station_id']);
                Assert::same('Residential Rooftop PV Station', $testSingle['station_name']);
                Assert::same('96000DNS22300188', $testSingle['inverter_sn']);

                // 7. Connect solar with valid credentials WITHOUT providing station_id (auto-discovery flow)
                $saveResp = $solar->saveConnection(self::cookieRequest(
                    'POST',
                    '/api/v1/solar/connection',
                    $session['token'],
                    [
                        'account' => 'my.solar@example.com',
                        'password' => 'solarSecret123!',
                    ],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));

                Assert::same(201, $saveResp->status());
                $connData = $saveResp->body()['data']['connection'];
                Assert::same(true, $connData['configured']);
                Assert::same('goodwe-station-98765', $connData['station_id']);
                Assert::same('m***r@example.com', $connData['account_identifier']);
                Assert::same('CONNECTED', $connData['connection_status']);
                Assert::same('96000DNS22300188', $connData['inverter_sn']);
                // Password must NEVER be in response
                Assert::false(isset($connData['password']));
                Assert::false(isset($connData['encrypted_credentials']));

                // 8. Onboarding readiness dynamically reflects CONNECTED
                $updatedReadiness = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']))->body()['data']['readiness'];
                Assert::same('CONNECTED', $updatedReadiness['solar']);

                // 9. Ingested snapshot is visible via latest endpoint
                $latestResp = $solar->latest(self::cookieRequest('GET', '/api/v1/solar/latest', $session['token']))->body()['data']['latest'];
                Assert::true($latestResp !== null, 'Latest reading must exist after auto-sync');
                Assert::same('3450.75', $latestResp['power_w']);
                Assert::same('14.820', $latestResp['daily_generation_kwh']);
                Assert::true($latestResp['grid_power_w'] === null, 'Smart meter absent rule honored');
                Assert::true($latestResp['consumption_power_w'] === null, 'Smart meter absent rule honored');
                Assert::same('ACTUAL', $latestResp['data_nature']);

                // 10. Telemetry history endpoint
                $historyResp = $solar->history(self::cookieRequest('GET', '/api/v1/solar/history', $session['token']))->body()['data'];
                Assert::same(1, $historyResp['count']);
                Assert::same('3450.75', $historyResp['history'][0]['power_w']);

                // 10b. Opportunistic visit auto-synchronization
                // If recently synced, returns FRESH without calling provider
                $autoSyncResp = $solar->autoSync(self::cookieRequest(
                    'POST',
                    '/api/v1/solar/sync/auto',
                    $session['token'],
                    [],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ))->body()['data'];
                Assert::false($autoSyncResp['synced']);
                Assert::same('FRESH', $autoSyncResp['status']);

                // Concurrency test: when another process holds GET_LOCK, returns IN_PROGRESS without blocking
                $cfg = \App\Support\DataEnvironments::connectionConfig('test');
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['HOST'], $cfg['PORT'], $cfg['DATABASE'], $cfg['CHARSET']);
                $otherPdo = new \PDO($dsn, $cfg['USERNAME'], $cfg['PASSWORD']);
                $lockKey = 'spms_solar_sync_' . substr(hash('sha256', $session['household_id']), 0, 24);
                $stmt = $otherPdo->prepare('SELECT GET_LOCK(:k, 0)');
                $stmt->execute(['k' => $lockKey]);

                $syncService = new SolarService(provider: $fake);
                $lockedResult = $syncService->syncIfStale($session['household_id'], 0);
                Assert::false($lockedResult['synced']);
                Assert::same('IN_PROGRESS', $lockedResult['status']);

                // Release lock on other connection
                $relStmt = $otherPdo->prepare('SELECT RELEASE_LOCK(:k)');
                $relStmt->execute(['k' => $lockKey]);

                // When stale and lock free, sync triggers and returns SUCCESS
                $successResult = $syncService->syncIfStale($session['household_id'], 0);
                Assert::true($successResult['synced']);
                Assert::same('SUCCESS', $successResult['status']);

                // 11. Disconnect solar
                $discResp = $solar->disconnect(self::cookieRequest(
                    'DELETE',
                    '/api/v1/solar/connection',
                    $session['token'],
                    [],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));
                Assert::same(true, $discResp->body()['data']['disconnected']);

                // Connection state reflects not configured
                $afterDiscConn = $solar->connection(self::cookieRequest('GET', '/api/v1/solar/connection', $session['token']))->body()['data']['connection'];
                Assert::same(false, $afterDiscConn['configured']);

                // Onboarding readiness reverts back to UNAVAILABLE
                $afterDiscReadiness = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']))->body()['data']['readiness'];
                Assert::same('UNAVAILABLE', $afterDiscReadiness['solar']);
            }),
        ];
    }
}
