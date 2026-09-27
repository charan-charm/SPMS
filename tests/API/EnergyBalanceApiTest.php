<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\EnergyBalanceController;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * F4 — Energy Balance API boundary tests (docs/06 §6, §13).
 */
final class EnergyBalanceApiTest
{
    private static function classifyPayload(array $overrides = []): array
    {
        return array_merge([
            'analysis_type' => 'AGGREGATE',
            'solar_generation' => [
                'value_kwh' => 5.0,
                'data_nature' => 'ACTUAL',
                'data_status' => 'FRESH',
            ],
            'household_consumption' => [
                'value_kwh' => 3.0,
                'data_nature' => 'ACTUAL',
                'data_status' => 'FRESH',
            ],
        ], $overrides);
    }

    public static function run(): array
    {
        return [
            TestResult::check('POST /api/v1/energy/balance returns 200 envelope with classified balance', function (): void {
                RequestId::set(RequestId::new());

                $controller = new EnergyBalanceController();
                $response = $controller->classify(Request::create(
                    'POST',
                    '/api/v1/energy/balance',
                    body: self::classifyPayload()
                ));

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same(null, $body['error']);
                Assert::same('POTENTIAL_SURPLUS', $body['data']['result']['classification']);
                Assert::same(2.0, $body['data']['result']['balance_kwh']);
                Assert::same('POTENTIAL', $body['data']['result']['measurement_context']);
            }),

            TestResult::check('POST balance handles simulated inputs through the same real pipeline', function (): void {
                $controller = new EnergyBalanceController();
                $response = $controller->classify(Request::create(
                    'POST',
                    '/api/v1/energy/balance',
                    body: self::classifyPayload([
                        'solar_generation' => ['value_kwh' => 4.9, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 5.0, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH'],
                    ])
                ));

                $body = $response->body();
                Assert::same('BALANCED', $body['data']['result']['classification']);
                Assert::same('SIMULATED', $body['data']['result']['data_nature']);
                Assert::true(str_contains(strtolower(json_encode($body['data']['notes'])), 'simulated'));
            }),

            TestResult::check('POST balance with TIME_PERIOD validates and echoes period', function (): void {
                $controller = new EnergyBalanceController();
                $response = $controller->classify(Request::create(
                    'POST',
                    '/api/v1/energy/balance',
                    body: self::classifyPayload([
                        'analysis_type' => 'TIME_PERIOD',
                        'period' => ['start' => '2026-09-01', 'end' => '2026-09-08', 'resolution' => 'DAY'],
                    ])
                ));

                $body = $response->body();
                Assert::same('TIME_PERIOD', $body['data']['analysis']['type']);
                Assert::same('2026-09-01', $body['data']['analysis']['period']['start']);
            }),

            TestResult::check('POST balance rejects negative value with 422 VALIDATION_ERROR', function (): void {
                $controller = new EnergyBalanceController();

                try {
                    $controller->classify(Request::create(
                        'POST',
                        '/api/v1/energy/balance',
                        body: self::classifyPayload([
                            'solar_generation' => ['value_kwh' => -1.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        ])
                    ));
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('solar_generation.value_kwh', $e->context()['field']);
                }
            }),

            TestResult::check('POST balance rejects unknown data_nature with 422', function (): void {
                $controller = new EnergyBalanceController();

                try {
                    $controller->classify(Request::create(
                        'POST',
                        '/api/v1/energy/balance',
                        body: self::classifyPayload([
                            'household_consumption' => ['value_kwh' => 3.0, 'data_nature' => 'MAGIC', 'data_status' => 'FRESH'],
                        ])
                    ));
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('household_consumption.data_nature', $e->context()['field']);
                }
            }),

            TestResult::check('POST balance rejects invalid analysis_type with 422', function (): void {
                $controller = new EnergyBalanceController();

                try {
                    $controller->classify(Request::create(
                        'POST',
                        '/api/v1/energy/balance',
                        body: self::classifyPayload(['analysis_type' => 'MAGIC'])
                    ));
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('POST balance rejects TIME_PERIOD period.end before start with 422', function (): void {
                $controller = new EnergyBalanceController();

                try {
                    $controller->classify(Request::create(
                        'POST',
                        '/api/v1/energy/balance',
                        body: self::classifyPayload([
                            'analysis_type' => 'TIME_PERIOD',
                            'period' => ['start' => '2026-09-08', 'end' => '2026-09-01'],
                        ])
                    ));
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/energy/balance returns honest UNAVAILABLE result', function (): void {
                RequestId::set(RequestId::new());

                $controller = new EnergyBalanceController();
                $response = $controller->current(Request::create('GET', '/api/v1/energy/balance'));

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same('UNAVAILABLE', $body['data']['result']['state']);
                Assert::same(null, $body['data']['result']['classification']);
                Assert::same(null, $body['data']['result']['balance_kwh']);
                Assert::same('UNAVAILABLE', $body['data']['result']['data_status']);
            }),

            TestResult::check('Router dispatches both energy balance methods to the controller', function (): void {
                $router = new Router();
                $router->add('POST', '/api/v1/energy/balance', [EnergyBalanceController::class, 'classify']);
                $router->add('GET', '/api/v1/energy/balance', [EnergyBalanceController::class, 'current']);

                [$postClass, $postAction] = $router->dispatch('POST', '/api/v1/energy/balance');
                Assert::same(EnergyBalanceController::class, $postClass);
                Assert::same('classify', $postAction);

                [$getClass, $getAction] = $router->dispatch('GET', '/api/v1/energy/balance');
                Assert::same(EnergyBalanceController::class, $getClass);
                Assert::same('current', $getAction);

                Assert::throws(\App\Support\MethodNotAllowedException::class, function () use ($router): void {
                    $router->dispatch('DELETE', '/api/v1/energy/balance');
                });
            }),

            TestResult::check('Envelope meta carries request_id through the controller response', function (): void {
                $requestId = RequestId::new();
                RequestId::set($requestId);

                $controller = new EnergyBalanceController();
                $response = $controller->current(Request::create('GET', '/api/v1/energy/balance'));

                ob_start();
                $response->send();
                $raw = (string) ob_get_clean();

                $body = Assert::jsonDecodes($raw);
                Assert::same($requestId, $body['meta']['request_id']);
                Assert::same('UNAVAILABLE', $body['data']['result']['state']);
            }),

            TestResult::check('GET /api/v1/energy/balance with missing solar and bills returns honest UNAVAILABLE', function (): void {
                AuthTesting::configure();
                RequestId::set(RequestId::new());

                $fixture = AuthTesting::householdFixture('f4-empty-' . bin2hex(random_bytes(4)));
                $controller = new EnergyBalanceController();
                $req = AuthTesting::authed(Request::create('GET', '/api/v1/energy/balance'), $fixture['account']);
                $response = $controller->current($req);

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same('UNAVAILABLE', $body['data']['result']['state']);
                Assert::true(str_contains($body['data']['result']['explanation'], 'No solar generation or household consumption data sources'));
                Assert::true(str_contains($body['data']['result']['limitation'], 'once solar generation and household consumption data sources are connected'));
            }),

            TestResult::check('GET /api/v1/energy/balance with solar only returns honest UNAVAILABLE citing bills', function (): void {
                AuthTesting::configure();
                RequestId::set(RequestId::new());

                $fixture = AuthTesting::householdFixture('f4-solar-' . bin2hex(random_bytes(4)));
                $solarRepo = new SolarConnectionRepository();
                $solarRepo->upsert(
                    id: bin2hex(random_bytes(16)),
                    householdId: $fixture['household_id'],
                    provider: 'GOODWE_SEMS',
                    stationId: 'st-' . bin2hex(random_bytes(4)),
                    inverterSn: 'GW-' . bin2hex(random_bytes(4)),
                    accountIdentifier: 'solar@example.test',
                    encryptedCredentials: 'enc',
                    connectionStatus: 'CONNECTED'
                );

                $controller = new EnergyBalanceController();
                $req = AuthTesting::authed(Request::create('GET', '/api/v1/energy/balance'), $fixture['account']);
                $response = $controller->current($req);

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same('UNAVAILABLE', $body['data']['result']['state']);
                Assert::true(str_contains($body['data']['result']['explanation'], 'Household consumption history has no verified electricity bills yet'));
                Assert::true(str_contains($body['data']['result']['limitation'], 'Upload and verify your recent electricity bills'));
            }),

            TestResult::check('GET /api/v1/energy/balance with bills only returns honest UNAVAILABLE citing solar inverter', function (): void {
                AuthTesting::configure();
                RequestId::set(RequestId::new());

                $fixture = AuthTesting::householdFixture('f4-bill-' . bin2hex(random_bytes(4)));
                $pdo = Database::connection();
                $billId = bin2hex(random_bytes(16));
                $pdo->prepare(
                    "INSERT INTO bills (id, household_id, status, billing_period_start, billing_period_end, consumption_kwh, created_at, updated_at) "
                    . "VALUES (:id, :hh, 'VERIFIED', '2026-08-01', '2026-08-31', 120.000, NOW(), NOW())"
                )->execute(['id' => $billId, 'hh' => $fixture['household_id']]);

                $controller = new EnergyBalanceController();
                $req = AuthTesting::authed(Request::create('GET', '/api/v1/energy/balance'), $fixture['account']);
                $response = $controller->current($req);

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same('UNAVAILABLE', $body['data']['result']['state']);
                Assert::true(str_contains($body['data']['result']['explanation'], 'Solar inverter is not connected yet'));
                Assert::true(str_contains($body['data']['result']['limitation'], 'Connect your solar inverter on the Solar page'));
            }),

            TestResult::check('GET /api/v1/energy/balance with connected solar and verified bills returns CLASSIFIED potential surplus', function (): void {
                AuthTesting::configure();
                RequestId::set(RequestId::new());

                $fixture = AuthTesting::householdFixture('f4-real-' . bin2hex(random_bytes(4)));
                $householdId = $fixture['household_id'];

                $solarRepo = new SolarConnectionRepository();
                $solarRepo->upsert(
                    id: bin2hex(random_bytes(16)),
                    householdId: $householdId,
                    provider: 'GOODWE_SEMS',
                    stationId: 'st-' . bin2hex(random_bytes(4)),
                    inverterSn: 'GW-' . bin2hex(random_bytes(4)),
                    accountIdentifier: 'user@example.test',
                    encryptedCredentials: 'enc',
                    connectionStatus: 'CONNECTED'
                );

                $readingRepo = new SolarReadingRepository();
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $snapshot = new \App\Domain\Solar\DTO\NormalizedSolarSnapshot(
                    stationId: 'st-001',
                    inverterSn: 'GW-001',
                    measurementTime: $now->modify('-1 hour'),
                    receivedAt: $now,
                    powerW: 4000.0,
                    dailyGenerationKwh: 18.0,
                    totalGenerationKwh: 500.0,
                    pvPowerW: 4200.0,
                    acVoltageV: 230.0,
                    acCurrentA: 17.4,
                    acFrequencyHz: 50.0,
                    temperatureC: 40.0,
                    gridPowerW: null,
                    consumptionPowerW: null,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH',
                    dataQuality: 'GOOD'
                );
                $readingRepo->insert(
                    id: bin2hex(random_bytes(16)),
                    householdId: $householdId,
                    snapshot: $snapshot,
                    syncBatchId: bin2hex(random_bytes(16))
                );

                $pdo = Database::connection();
                $billId = bin2hex(random_bytes(16));
                $pdo->prepare(
                    "INSERT INTO bills (id, household_id, status, billing_period_start, billing_period_end, consumption_kwh, created_at, updated_at) "
                    . "VALUES (:id, :hh, 'VERIFIED', '2026-08-01', '2026-08-31', 90.000, NOW(), NOW())"
                )->execute(['id' => $billId, 'hh' => $householdId]);

                $controller = new EnergyBalanceController();
                $req = AuthTesting::authed(Request::create('GET', '/api/v1/energy/balance'), $fixture['account']);
                $response = $controller->current($req);

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same('CLASSIFIED', $body['data']['result']['state']);
                Assert::same('POTENTIAL_SURPLUS', $body['data']['result']['classification']);
                Assert::true((float) $body['data']['result']['balance_kwh'] > 0);
                Assert::same('POTENTIAL', $body['data']['result']['measurement_context']);
                Assert::same('PREDICTED', $body['data']['result']['data_nature']);
                Assert::true((float) $body['data']['inputs']['solar_generation']['value_kwh'] > 0);
                Assert::true((float) $body['data']['inputs']['household_consumption']['value_kwh'] > 0);
                Assert::true(is_array($body['data']['notes']));
                Assert::true(str_contains(json_encode($body['data']['notes']), 'potential energy situation'));
            }),

            TestResult::check('POST /api/v1/energy/balance classifies potential deficit with POTENTIAL context', function (): void {
                $controller = new EnergyBalanceController();
                $response = $controller->classify(Request::create(
                    'POST',
                    '/api/v1/energy/balance',
                    body: self::classifyPayload([
                        'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 8.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                    ])
                ));

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same('CLASSIFIED', $body['data']['result']['state']);
                Assert::same('POTENTIAL_DEFICIT', $body['data']['result']['classification']);
                Assert::same(-6.0, $body['data']['result']['balance_kwh']);
                Assert::same('POTENTIAL', $body['data']['result']['measurement_context']);
                Assert::true(str_contains($body['data']['result']['explanation'], 'potential deficit'));
            }),

            TestResult::check('GET /api/v1/energy/balance enforces strict household isolation', function (): void {
                AuthTesting::configure();
                RequestId::set(RequestId::new());

                $fixtureA = AuthTesting::householdFixture('f4-iso-a-' . bin2hex(random_bytes(4)));
                $fixtureB = AuthTesting::householdFixture('f4-iso-b-' . bin2hex(random_bytes(4)));

                $controller = new EnergyBalanceController();
                // Account A trying to access Household B's balance via query parameter
                $req = AuthTesting::authed(
                    Request::create('GET', '/api/v1/energy/balance', query: ['household_id' => $fixtureB['household_id']]),
                    $fixtureA['account']
                );

                try {
                    $controller->current($req);
                    Assert::true(false, 'Expected 403 ACCESS_DENIED on cross-household access');
                } catch (AppException $e) {
                    Assert::same('ACCESS_DENIED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }
            }),

            TestResult::check('DEMO sample analysis run via POST does not contaminate live GET state', function (): void {
                AuthTesting::configure();
                RequestId::set(RequestId::new());

                $fixture = AuthTesting::householdFixture('f4-demo-sep-' . bin2hex(random_bytes(4)));
                $controller = new EnergyBalanceController();

                // 1. Live GET for empty household returns UNAVAILABLE
                $getReq = AuthTesting::authed(Request::create('GET', '/api/v1/energy/balance'), $fixture['account']);
                $getRes1 = $controller->current($getReq);
                Assert::same('UNAVAILABLE', $getRes1->body()['data']['result']['state']);

                // 2. Sample analysis run via POST with SIMULATED inputs
                $postRes = $controller->classify(Request::create(
                    'POST',
                    '/api/v1/energy/balance',
                    body: [
                        'analysis_type' => 'TIME_PERIOD',
                        'period' => ['start' => '2026-09-26T00:00:00', 'end' => '2026-09-26T23:59:59', 'resolution' => 'DAY'],
                        'solar_generation' => ['value_kwh' => 6.4, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 4.1, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH'],
                    ]
                ));
                Assert::same('CLASSIFIED', $postRes->body()['data']['result']['state']);
                Assert::same('SIMULATED', $postRes->body()['data']['result']['data_nature']);
                Assert::true(str_contains(json_encode($postRes->body()['data']['notes']), 'Inputs are simulated'));

                // 3. Live GET remains completely unaffected (still UNAVAILABLE, not fabricated from the sample run)
                $getRes2 = $controller->current($getReq);
                Assert::same('UNAVAILABLE', $getRes2->body()['data']['result']['state']);
                Assert::same(null, $getRes2->body()['data']['result']['balance_kwh']);
            }),
        ];
    }
}
