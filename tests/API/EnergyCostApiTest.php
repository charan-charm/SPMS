<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\EnergyCostController;
use App\Domain\Energy\EnergyCostService;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * F5 — Energy Cost API boundary tests (docs/06 §3, §6, docs/10 D-042).
 */
final class EnergyCostApiTest
{
    private static function estimatePayload(array $overrides = []): array
    {
        return array_merge([
            'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
            'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
            'pricing' => [
                'basis' => 'USER_PROVIDED_RATE',
                'unit_rate' => 8.5,
                'currency' => 'INR',
                'source' => 'Owner-declared sample rate',
            ],
            'fixed_charge' => ['amount' => 150.0, 'currency' => 'INR', 'label' => 'Fixed customer charge'],
            'period' => ['start' => '2026-09-01', 'end' => '2026-09-30', 'resolution' => 'MONTH'],
        ], $overrides);
    }

    public static function run(): array
    {
        return [
            TestResult::check('POST /api/v1/energy/cost/estimate returns 200 envelope with estimated cost', function (): void {
                RequestId::set(RequestId::new());

                $controller = new EnergyCostController();
                $response = $controller->estimate(Request::create(
                    'POST',
                    '/api/v1/energy/cost/estimate',
                    body: self::estimatePayload()
                ));

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same(null, $body['error']);
                Assert::same('ESTIMATED', $body['data']['result']['state']);
                Assert::same(21.25, $body['data']['result']['estimated_variable_cost']);
                Assert::same('INR', $body['data']['result']['currency']);
                Assert::same('FUTURE_ESTIMATE', $body['data']['result']['cost_type']);
                Assert::same('USER_PROVIDED_RATE', $body['data']['result']['pricing_basis']);
                Assert::same(3, $body['data']['result']['evidence_level']);
            }),

            TestResult::check('Estimated result carries range, fixed-charge separation and contexts', function (): void {
                $controller = new EnergyCostController();
                $response = $controller->estimate(Request::create(
                    'POST',
                    '/api/v1/energy/cost/estimate',
                    body: self::estimatePayload()
                ));

                $data = $response->body()['data'];
                Assert::same(15.94, $data['result']['range']['low']);
                Assert::same(26.56, $data['result']['range']['high']);
                Assert::same('SEPARATE_NOT_INCLUDED', $data['result']['fixed_charge']['treatment']);
                Assert::same('ESTIMATED', $data['result']['measurement_context']);
                Assert::same('POTENTIAL', $data['energy_position']['measurement_context']);
                Assert::same(2.5, $data['energy_position']['grid_dependent_kwh']);
                Assert::same('Owner-declared sample rate', $data['inputs']['pricing']['source']);
            }),

            TestResult::check('Envelope meta carries request_id through the cost response', function (): void {
                $requestId = RequestId::new();
                RequestId::set($requestId);

                $controller = new EnergyCostController();
                $response = $controller->estimate(Request::create(
                    'POST',
                    '/api/v1/energy/cost/estimate',
                    body: self::estimatePayload()
                ));

                ob_start();
                $response->send();
                $raw = (string) ob_get_clean();

                $body = Assert::jsonDecodes($raw);
                Assert::same($requestId, $body['meta']['request_id']);
                Assert::same(21.25, $body['data']['result']['estimated_variable_cost']);
            }),

            TestResult::check('POST cost estimate rejects invalid pricing basis with 422 field context', function (): void {
                $controller = new EnergyCostController();

                try {
                    $controller->estimate(Request::create(
                        'POST',
                        '/api/v1/energy/cost/estimate',
                        body: self::estimatePayload([
                            'pricing' => ['basis' => 'MAGIC_BASIS', 'unit_rate' => 8.5, 'currency' => 'INR'],
                        ])
                    ));
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('pricing.basis', $e->context()['field']);
                }
            }),

            TestResult::check('POST cost estimate rejects non-INR currency with 422 field context', function (): void {
                $controller = new EnergyCostController();

                try {
                    $controller->estimate(Request::create(
                        'POST',
                        '/api/v1/energy/cost/estimate',
                        body: self::estimatePayload([
                            'pricing' => ['basis' => 'TARIFF_EVIDENCE', 'unit_rate' => 8.5, 'currency' => 'USD'],
                        ])
                    ));
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('pricing.currency', $e->context()['field']);
                }
            }),

            TestResult::check('GET /api/v1/energy/cost/historical returns honest UNAVAILABLE', function (): void {
                RequestId::set(RequestId::new());

                $controller = new EnergyCostController();
                $response = $controller->historical(Request::create('GET', '/api/v1/energy/cost/historical'));

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same('UNAVAILABLE', $body['data']['result']['state']);
                Assert::same('HISTORICAL_VERIFIED', $body['data']['result']['cost_type']);
                Assert::same('UNAVAILABLE', $body['data']['result']['data_status']);
            }),

            TestResult::check('Historical endpoint fabricates no amount and stays distinct from estimates', function (): void {
                $controller = new EnergyCostController();
                $response = $controller->historical(Request::create('GET', '/api/v1/energy/cost/historical'));

                $result = $response->body()['data']['result'];
                Assert::same(null, $result['amount']);
                Assert::same(null, $result['currency']);
                Assert::same(null, $result['data_nature']);
                Assert::same(null, $result['measurement_context']);
                $notes = strtolower(json_encode($response->body()['data']['notes']));
                Assert::true(str_contains($notes, 'strictly separate'), 'Historical notes must declare separation from estimates');
            }),

            TestResult::check('Potential deficit honesty — never reinterpreted as measured grid import', function (): void {
                $controller = new EnergyCostController();
                $response = $controller->estimate(Request::create(
                    'POST',
                    '/api/v1/energy/cost/estimate',
                    body: self::estimatePayload()
                ));

                $data = $response->body()['data'];
                Assert::same('POTENTIAL_DEFICIT', $data['energy_position']['classification']);
                Assert::same('POTENTIAL', $data['energy_position']['measurement_context']);
                $notes = strtolower(json_encode($data['notes']));
                Assert::true(str_contains($notes, 'never a measured grid import'), 'Deficit must not be presented as measured import');
                Assert::true(str_contains($notes, 'not a confirmed future electricity bill'), 'Estimate must disclaim confirmed-bill framing');
            }),

            TestResult::check('Simulated inputs flow through the real pipeline labeled simulated', function (): void {
                $controller = new EnergyCostController();
                $response = $controller->estimate(Request::create(
                    'POST',
                    '/api/v1/energy/cost/estimate',
                    body: self::estimatePayload([
                        'solar_generation' => ['value_kwh' => 2.0, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 4.5, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH'],
                    ])
                ));

                $data = $response->body()['data'];
                Assert::same('SIMULATED', $data['result']['data_nature']);
                Assert::same('ESTIMATED', $data['result']['measurement_context']);
                $notes = strtolower(json_encode($data['notes']));
                Assert::true(str_contains($notes, 'simulated'), 'Simulated labeling must reach the notes');
            }),

            TestResult::check('Insufficient evidence returns an honest 200 with no fabricated cost', function (): void {
                $controller = new EnergyCostController();
                $response = $controller->estimate(Request::create(
                    'POST',
                    '/api/v1/energy/cost/estimate',
                    body: self::estimatePayload([
                        'pricing' => ['basis' => 'INSUFFICIENT_EVIDENCE', 'currency' => 'INR'],
                    ])
                ));

                Assert::same(200, $response->status());
                $result = $response->body()['data']['result'];
                Assert::same('INSUFFICIENT_EVIDENCE', $result['state']);
                Assert::same(null, $result['estimated_variable_cost']);
                Assert::same(null, $result['range']);
            }),

            TestResult::check('POST cost estimate treats tolerance-balanced positions as zero material deficit', function (): void {
                RequestId::set(RequestId::new());

                $controller = new EnergyCostController();
                $response = $controller->estimate(Request::create(
                    'POST',
                    '/api/v1/energy/cost/estimate',
                    body: self::estimatePayload([
                        'solar_generation' => ['value_kwh' => 5.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'household_consumption' => ['value_kwh' => 5.1, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH'],
                        'pricing' => ['basis' => 'USER_PROVIDED_RATE', 'unit_rate' => 8.5, 'currency' => 'INR'],
                    ])
                ));

                Assert::same(200, $response->status());
                $data = $response->body()['data'];
                Assert::same('BALANCED', $data['energy_position']['classification']);
                Assert::same(-0.1, $data['energy_position']['balance_kwh']);
                Assert::same(0.0, $data['energy_position']['grid_dependent_kwh']);
                Assert::same(0.0, $data['result']['estimated_variable_cost']);
                Assert::same(0.0, $data['result']['range']['low']);
                Assert::same(0.0, $data['result']['range']['high']);
                Assert::true(str_contains($data['result']['explanation'], 'BALANCED within the disclosed tolerance'), 'Balanced explanation must reach the envelope');
            }),

            TestResult::check('Router dispatches both energy cost methods to the controller', function (): void {
                $router = new Router();
                $router->add('POST', '/api/v1/energy/cost/estimate', [EnergyCostController::class, 'estimate']);
                $router->add('GET', '/api/v1/energy/cost/historical', [EnergyCostController::class, 'historical']);

                [$postClass, $postAction] = $router->dispatch('POST', '/api/v1/energy/cost/estimate');
                Assert::same(EnergyCostController::class, $postClass);
                Assert::same('estimate', $postAction);

                [$getClass, $getAction] = $router->dispatch('GET', '/api/v1/energy/cost/historical');
                Assert::same(EnergyCostController::class, $getClass);
                Assert::same('historical', $getAction);

                Assert::throws(\App\Support\MethodNotAllowedException::class, function () use ($router): void {
                    $router->dispatch('DELETE', '/api/v1/energy/cost/estimate');
                });
            }),
        ];
    }
}
