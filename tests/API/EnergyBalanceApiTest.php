<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\EnergyBalanceController;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use Tests\Support\Assert;
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
        ];
    }
}
