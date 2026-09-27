<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\ForecastController;
use App\Domain\Forecast\ForecastHorizon;
use App\Support\AppException;
use App\Support\MethodNotAllowedException;
use App\Support\NotFoundException;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class ForecastApiTest
{
    private static function controller(): ForecastController
    {
        AuthTesting::configure();

        return new ForecastController();
    }

    public static function run(): array
    {
        return [
            TestResult::check('GET /api/v1/forecasts requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->index(Request::create('GET', '/api/v1/forecasts'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/forecasts/generate requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->generate(Request::create('POST', '/api/v1/forecasts/generate', body: ['horizon' => 'DAY_AHEAD']));
                    Assert::true(false, 'Expected AppException for unauthenticated generate request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('Account without household cannot access forecasts', function (): void {
                RequestId::set(RequestId::new());
                $orphanAccount = AuthTesting::activateAccount('orphan-fc-');

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/forecasts'), $orphanAccount);

                try {
                    self::controller()->index($req);
                    Assert::true(false, 'Expected 404 for account without active household');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/forecasts returns valid envelope and honest limitations', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/forecasts', query: ['horizon' => 'DAY_AHEAD']), $fixture['account']);
                $response = self::controller()->index($req);

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same(null, $body['error']);
                Assert::true(isset($body['data']['horizon']));

                $forecast = $body['data'];
                Assert::same('DAY_AHEAD', $forecast['horizon']);
                Assert::true(isset($forecast['solar_forecast']));
                Assert::true(isset($forecast['consumption_forecast']));
                Assert::true(isset($forecast['energy_balance_projection']));
                Assert::true(isset($forecast['energy_cost_projection']));
                Assert::true(is_array($forecast['limitations']));

                // Zero bills honesty: no fabricated consumption, no false surplus, no false cost
                Assert::same('INSUFFICIENT', $forecast['consumption_forecast']['confidence']);
                Assert::same('ESTIMATED', $forecast['consumption_forecast']['data_nature']);
                Assert::same(null, $forecast['consumption_forecast']['predicted_value']);
                Assert::same('UNAVAILABLE', $forecast['energy_balance_projection']['state']);
                Assert::same(null, $forecast['energy_balance_projection']['balance_kwh']);
                Assert::same('UNAVAILABLE', $forecast['energy_balance_projection']['classification']);
                Assert::same('INSUFFICIENT_EVIDENCE', $forecast['energy_cost_projection']['state']);
                Assert::same(null, $forecast['energy_cost_projection']['estimated_cost_inr']);

                // Limitations list has honesty entries
                Assert::true(count($forecast['limitations']) >= 1);
            }),

            TestResult::check('POST /api/v1/forecasts/generate validates horizon and persists snapshot', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();

                // Invalid horizon
                try {
                    $invalidReq = AuthTesting::authed(Request::create('POST', '/api/v1/forecasts/generate', body: ['horizon' => 'YEAR_AHEAD']), $fixture['account']);
                    self::controller()->generate($invalidReq);
                    Assert::true(false, 'Expected 422 for invalid horizon');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }

                // Valid generate
                $validReq = AuthTesting::authed(Request::create('POST', '/api/v1/forecasts/generate', body: ['horizon' => 'SEVEN_DAY']), $fixture['account']);
                $response = self::controller()->generate($validReq);

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same('SEVEN_DAY', $body['data']['horizon']);

                // Reading back GET returns persisted snapshot
                $readReq = AuthTesting::authed(Request::create('GET', '/api/v1/forecasts', query: ['horizon' => 'SEVEN_DAY']), $fixture['account']);
                $readResponse = self::controller()->index($readReq);
                $readBody = $readResponse->body();
                Assert::same('SEVEN_DAY', $readBody['data']['horizon']);
                Assert::true(isset($readBody['data']['solar_forecast']));
            }),

            TestResult::check('Router dispatches forecast routes correctly', function (): void {
                $router = new Router();
                $router->add('GET', '/api/v1/forecasts', [ForecastController::class, 'index']);
                $router->add('POST', '/api/v1/forecasts/generate', [ForecastController::class, 'generate']);

                [$getClass, $getAction] = $router->dispatch('GET', '/api/v1/forecasts');
                Assert::same(ForecastController::class, $getClass);
                Assert::same('index', $getAction);

                [$postClass, $postAction] = $router->dispatch('POST', '/api/v1/forecasts/generate');
                Assert::same(ForecastController::class, $postClass);
                Assert::same('generate', $postAction);

                Assert::throws(MethodNotAllowedException::class, function () use ($router): void {
                    $router->dispatch('PUT', '/api/v1/forecasts');
                });
            }),
        ];
    }
}
