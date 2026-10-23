<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\WeatherController;
use App\Domain\Household\HouseholdService;
use App\Support\AppException;
use App\Support\MethodNotAllowedException;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * Weather API Endpoint Test Suite (Section 5 & 16).
 *
 * Verifies:
 * - GET /api/v1/weather requires authentication (401)
 * - Account without active household returns 404
 * - GET /api/v1/weather returns valid 200 envelope with normalized weather contract
 * - Strict household isolation: household coordinates derived strictly from account
 * - Arbitrary coordinate query params cannot bypass household isolation
 * - Zero credentials or secrets exposed in response
 * - Router dispatches weather route correctly
 */
final class WeatherApiTest
{
    private static function controller(): WeatherController
    {
        AuthTesting::configure();

        return new WeatherController();
    }

    /**
     * @return list<TestResult>
     */
    public static function run(): array
    {
        return [
            // 1. Unauthenticated -> 401
            TestResult::check('GET /api/v1/weather requires authentication (401 for anonymous)', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->current(Request::create('GET', '/api/v1/weather'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            // 2. Account without household -> 404
            TestResult::check('GET /api/v1/weather returns 404 for account with no active household', function (): void {
                RequestId::set(RequestId::new());
                $orphanAccount = AuthTesting::activateAccount('orphan-weather-');

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/weather'), $orphanAccount);

                try {
                    self::controller()->current($req);
                    Assert::true(false, 'Expected 404 for account without active household');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }),

            // 3. Authenticated household -> 200 with normalized weather contract
            TestResult::check('GET /api/v1/weather returns 200 envelope with normalized weather structure', function (): void {
                RequestId::set(RequestId::new());
                $context = AuthTesting::householdFixture('api-w-' . bin2hex(random_bytes(4)));

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Kurnool, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/weather'), $context['account']);
                $response = self::controller()->current($req);

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);

                $data = $body['data'];
                Assert::same($context['household_id'], $data['household_id']);
                Assert::true(array_key_exists('location', $data));
                Assert::true(array_key_exists('latitude', $data));
                Assert::true(array_key_exists('longitude', $data));
                Assert::true(array_key_exists('timezone', $data));
                Assert::true(array_key_exists('provider', $data));
                Assert::true(array_key_exists('status', $data));
                Assert::true(array_key_exists('freshness', $data));
                Assert::true(array_key_exists('solar_impact', $data));
                Assert::true(array_key_exists('hourly', $data));

                // Verify no provider secrets/credentials exist in response
                Assert::false(isset($data['api_key']), 'No API keys in response');
                Assert::false(isset($data['token']), 'No token in response');
                Assert::false(isset($data['password']), 'No password in response');
            }),

            // 4. Household isolation & arbitrary coordinates ignored
            TestResult::check('GET /api/v1/weather enforces strict household isolation and ignores arbitrary query coordinates', function (): void {
                RequestId::set(RequestId::new());

                $ctxA = AuthTesting::householdFixture('iso-wa-' . bin2hex(random_bytes(4)));
                $ctxB = AuthTesting::householdFixture('iso-wb-' . bin2hex(random_bytes(4)));

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $ctxA['account'],
                    ['location_label' => 'Kodumur, Kurnool, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );
                $hhService->updateProfile(
                    $ctxB['account'],
                    ['location_label' => 'Tirupati, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                // Account A attempts to bypass isolation by querying with Account B coordinates in query string
                $reqA = AuthTesting::authed(
                    Request::create('GET', '/api/v1/weather', query: ['latitude' => '13.628', 'longitude' => '79.419', 'location' => 'Tirupati']),
                    $ctxA['account']
                );
                $resA = self::controller()->current($reqA);

                Assert::same(200, $resA->status());
                $dataA = $resA->body()['data'];

                // Coordinates and household_id must reflect Account A's own household, NOT query params
                Assert::same($ctxA['household_id'], $dataA['household_id']);
                Assert::true(str_contains((string) $dataA['location'], 'Kodumur') || str_contains((string) $dataA['location'], 'Kurnool'), 'Location matches household A');

                // Account B gets Account B's own household
                $reqB = AuthTesting::authed(Request::create('GET', '/api/v1/weather'), $ctxB['account']);
                $resB = self::controller()->current($reqB);
                Assert::same(200, $resB->status());
                $dataB = $resB->body()['data'];
                Assert::same($ctxB['household_id'], $dataB['household_id']);
            }),

            // 5. Router dispatches weather route correctly
            TestResult::check('Router dispatches weather route correctly', function (): void {
                $router = new Router();
                $router->add('GET', '/api/v1/weather', [WeatherController::class, 'current']);

                [$getClass, $getAction] = $router->dispatch('GET', '/api/v1/weather');
                Assert::same(WeatherController::class, $getClass);
                Assert::same('current', $getAction);

                Assert::throws(MethodNotAllowedException::class, function () use ($router): void {
                    $router->dispatch('POST', '/api/v1/weather');
                });
            }),
        ];
    }
}
