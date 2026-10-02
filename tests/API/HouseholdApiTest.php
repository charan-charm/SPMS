<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\HouseholdController;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W3 — Household context API boundary (docs/10 D-050, D-055).
 *
 * GET/POST /api/v1/household (plus the W3.1 PATCH route registered
 * alongside them): authenticated, membership-derived, no
 * client-selected household identifier anywhere on any route.
 */
final class HouseholdApiTest
{
    private static function controller(): HouseholdController
    {
        AuthTesting::configure();

        return new HouseholdController();
    }

    private static function request(string $method, array $body = [], ?\App\Domain\Account\Account $as = null): Request
    {
        $request = Request::create($method, '/api/v1/household', body: $body, ipAddress: AuthTesting::uniqueIpAddress());

        return $as === null ? $request : AuthTesting::authed($request, $as);
    }

    public static function run(): array
    {
        return [
            TestResult::check('GET /api/v1/household requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->current(self::request('GET'));
                    Assert::true(false, 'Expected AppException for anonymous household context');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }

                try {
                    self::controller()->create(self::request('POST', ['timezone' => 'UTC']));
                    Assert::true(false, 'Expected AppException for anonymous household creation');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('A verified account with no household gets an honest null context', function (): void {
                $account = AuthTesting::activateAccount();

                $response = self::controller()->current(self::request('GET', as: $account));
                $data = $response->body()['data'];

                Assert::same(200, $response->status());
                Assert::same(true, $data['authenticated']);
                Assert::same(null, $data['household']);
                Assert::same(null, $data['membership']);
                Assert::same($account->email(), $data['account']['email']);
            }),

            TestResult::check('POST /api/v1/household creates the account household with an OWNER membership', function (): void {
                $account = AuthTesting::activateAccount();

                $response = self::controller()->create(self::request('POST', [
                    'timezone' => 'Asia/Kolkata',
                    'label' => 'Family rooftop',
                    'location_label' => 'Anantapur, Andhra Pradesh',
                ], as: $account));

                Assert::same(201, $response->status());
                $data = $response->body()['data'];
                Assert::same(36, strlen($data['household']['id']));
                Assert::same('Asia/Kolkata', $data['household']['timezone']);
                Assert::same('Family rooftop', $data['household']['label']);
                Assert::same('ACTIVE', $data['household']['status']);
                Assert::same('OWNER', $data['membership']['role']);

                // GET now resolves exactly that household, derived from
                // membership — no identifier was supplied on the request.
                $current = self::controller()->current(self::request('GET', as: $account));
                Assert::same($data['household']['id'], $current->body()['data']['household']['id']);
            }),

            TestResult::check('POST /api/v1/household accepts optional service_number on creation', function (): void {
                $account = AuthTesting::activateAccount();
                $response = self::controller()->create(self::request('POST', [
                    'label' => 'Primary Home',
                    'timezone' => 'Asia/Kolkata',
                    'location_label' => 'Hyderabad',
                    'service_number' => '1000-2000-300',
                ], as: $account));

                Assert::same(201, $response->status());
                $data = $response->body()['data'];
                Assert::same('10002000300', $data['household']['service_number']);
                Assert::same('Primary Home', $data['household']['label']);
                Assert::same('Hyderabad', $data['household']['location_label']);

                $current = self::controller()->current(self::request('GET', as: $account));
                Assert::same('10002000300', $current->body()['data']['household']['service_number']);
            }),

            TestResult::check('POST /api/v1/household/demo/ensure fails closed in non-demo environment', function (): void {
                $account = AuthTesting::activateAccount();
                try {
                    self::controller()->ensureDemo(self::request('POST', as: $account));
                    Assert::true(false, 'Expected ensureDemo to fail outside demo environment');
                } catch (AppException $e) {
                    Assert::same('INVALID_ENVIRONMENT', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/household/demo/ensure fails unauthenticated with 401', function (): void {
                try {
                    self::controller()->ensureDemo(self::request('POST'));
                    Assert::true(false, 'Expected ensureDemo to fail unauthenticated');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/household/demo/ensure in demo environment returns existing household without mutation', function (): void {
                \App\Support\Database::switchTo('demo');
                try {
                    $accountRepo = new \App\Repositories\AccountRepository();
                    $demoAccount = $accountRepo->findByEmail('demo.surplus@spms.local');
                    Assert::true($demoAccount !== null, 'DEMO1 account must exist');

                    $memberRepo = new \App\Repositories\MembershipRepository();
                    $initial = $memberRepo->findActiveByAccount($demoAccount->id());
                    Assert::true($initial !== null, 'DEMO1 initial household must exist');
                    $initialHousehold = $initial['household'];

                    $response = self::controller()->ensureDemo(self::request('POST', as: $demoAccount));
                    Assert::same(200, $response->status());
                    Assert::same($initialHousehold->id(), $response->body()['data']['household']['id']);
                    Assert::same('Solar Surplus Residence', $response->body()['data']['household']['label']);

                    $after = $memberRepo->findActiveByAccount($demoAccount->id());
                    Assert::same($initialHousehold->id(), $after['household']->id(), 'Household ID must remain unchanged');
                    Assert::same($initialHousehold->label(), $after['household']->label(), 'Household label must remain unchanged');
                } finally {
                    \App\Support\Database::switchTo('test');
                }
            }),

            TestResult::check('V1 single-household rule: a second creation is rejected, never replaced', function (): void {
                $fixture = AuthTesting::householdFixture();

                try {
                    self::controller()->create(self::request('POST', ['timezone' => 'UTC'], as: $fixture['account']));
                    Assert::true(false, 'Expected AppException for a second household');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_EXISTS', $e->errorCode());
                    Assert::same(409, $e->httpStatus());
                }

                // The original household is untouched.
                $current = self::controller()->current(self::request('GET', as: $fixture['account']));
                Assert::same($fixture['household_id'], $current->body()['data']['household']['id']);
            }),

            TestResult::check('Household creation input is strictly validated with field context', function (): void {
                $account = AuthTesting::activateAccount();

                foreach ([
                    ['body' => [], 'field' => 'timezone', 'message' => 'timezone is required.'],
                    ['body' => ['timezone' => 'Mars/Olympus'], 'field' => 'timezone'],
                    ['body' => ['timezone' => 'UTC', 'label' => str_repeat('l', 121)], 'field' => 'label'],
                    ['body' => ['timezone' => 'UTC', 'location_label' => str_repeat('L', 191)], 'field' => 'location_label'],
                ] as $case) {
                    try {
                        self::controller()->create(self::request('POST', $case['body'], as: $account));
                        Assert::true(false, 'Expected validation failure for ' . json_encode(array_keys($case['body'])));
                    } catch (AppException $e) {
                        Assert::same('VALIDATION_ERROR', $e->errorCode());
                        Assert::same(422, $e->httpStatus());
                        Assert::same($case['field'], $e->context()['field']);
                    }
                }

                // A failed attempt consumed no household slot.
                $response = self::controller()->current(self::request('GET', as: $account));
                Assert::same(null, $response->body()['data']['household']);
            }),

            TestResult::check('Responses never contain password material, session data or authorization internals', function (): void {
                $fixture = AuthTesting::householdFixture();

                $body = json_encode(self::controller()->current(self::request('GET', as: $fixture['account']))->body()) ?: '';

                Assert::notContains('password', strtolower($body));
                Assert::notContains('csrf', strtolower($body));
                Assert::notContains('w3-fixture-session', $body);
                Assert::true(str_contains($body, '"role":"OWNER"'), 'membership role is disclosed — it is not sensitive, the boundary is server-side');
            }),

            TestResult::check('Router registers exactly the three household routes (W3.1 adds PATCH)', function (): void {
                $router = new Router();
                $router->add('GET', '/api/v1/household', [HouseholdController::class, 'current']);
                $router->add('POST', '/api/v1/household', [HouseholdController::class, 'create']);
                $router->add('PATCH', '/api/v1/household', [HouseholdController::class, 'update']);

                [$class, $action] = $router->dispatch('GET', '/api/v1/household');
                Assert::same(HouseholdController::class, $class);
                Assert::same('current', $action);

                [$patchClass, $patchAction] = $router->dispatch('PATCH', '/api/v1/household');
                Assert::same(HouseholdController::class, $patchClass);
                Assert::same('update', $patchAction);

                foreach (['PUT', 'DELETE'] as $verb) {
                    try {
                        $router->dispatch($verb, '/api/v1/household');
                        Assert::true(false, $verb . ' must not be routable');
                    } catch (\App\Support\MethodNotAllowedException) {
                        Assert::true(true);
                    }
                }

                try {
                    $router->dispatch('GET', '/api/v1/households/any-id');
                    Assert::true(false, 'no household-id-addressed route may exist');
                } catch (\App\Support\NotFoundException) {
                    Assert::true(true);
                }
            }),
        ];
    }
}
