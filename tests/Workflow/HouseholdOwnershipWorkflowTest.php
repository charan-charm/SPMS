<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Controllers\HouseholdController;
use App\Controllers\IntelligenceController;
use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Intelligence\InsightEngine;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Repositories\InsightRepository;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W3 — End-to-end household ownership workflow.
 *
 * Real controllers + real auth middleware (cookie session, CSRF) + real
 * database: register → verify → login → create household (onboarding
 * primitive) → authenticated household context → household-scoped
 * insight visibility and cross-account isolation → logout invalidation.
 */
final class HouseholdOwnershipWorkflowTest
{
    private static function cookieRequest(string $method, string $path, string $rawSessionToken, array $body = [], array $extraHeaders = [], array $query = []): Request
    {
        return AuthMiddleware::process(Request::create($method, $path, body: $body, query: $query, headers: array_merge([
            'Cookie' => 'spms_session=' . $rawSessionToken,
        ], $extraHeaders), ipAddress: AuthTesting::uniqueIpAddress()));
    }

    /**
     * @return array{token: string, csrf: string}
     */
    private static function registeredAndLoggedInSession(): array
    {
        $auth = new AuthController();
        $email = AuthTesting::uniqueEmail();

        $auth->register(Request::create('POST', '/api/v1/auth/register', body: [
            'email' => $email,
            'password' => 'correct-horse-battery',
        ], ipAddress: AuthTesting::uniqueIpAddress()));

        $token = AuthTesting::latestMailToken('Verify your email');
        Assert::true($token !== null, 'verification mail must reach the sink');
        $auth->verify(Request::create('POST', '/api/v1/auth/verify', body: ['token' => $token]));

        $login = $auth->login(Request::create('POST', '/api/v1/auth/login', body: [
            'email' => $email,
            'password' => 'correct-horse-battery',
        ], ipAddress: AuthTesting::uniqueIpAddress()));

        $sessionToken = AuthTesting::sessionTokenFrom($login);
        $csrf = AuthTesting::csrfFrom($login->body()['data'] ?? []);
        Assert::true($sessionToken !== null && $csrf !== null, 'login must yield session + CSRF');

        return ['token' => $sessionToken, 'csrf' => $csrf, 'email' => $email];
    }

    public static function run(): array
    {
        return [
            TestResult::check('register → login → household onboarding → context → logout, through the real pipeline', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();
                $session = self::registeredAndLoggedInSession();
                $household = new HouseholdController();

                // Anonymous through the real middleware: honest 401.
                try {
                    $household->current(Request::create('GET', '/api/v1/household'));
                    Assert::true(false, 'anonymous context must be rejected');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                }

                // Authenticated mutation without CSRF: middleware-level 403.
                try {
                    $household->create(self::cookieRequest('POST', '/api/v1/household', $session['token'], ['timezone' => 'Asia/Kolkata']));
                    Assert::true(false, 'expected CSRF rejection');
                } catch (AppException $e) {
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }

                // No household yet: honest null context.
                $empty = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']));
                Assert::same(null, $empty->body()['data']['household']);

                // Onboarding primitive creates the household with OWNER role.
                $created = $household->create(self::cookieRequest(
                    'POST',
                    '/api/v1/household',
                    $session['token'],
                    ['timezone' => 'Asia/Kolkata', 'label' => 'w3-workflow-home'],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));
                Assert::same(201, $created->status());
                $householdId = (string) $created->body()['data']['household']['id'];
                Assert::same('OWNER', $created->body()['data']['membership']['role']);

                // Context now resolves from the session, no identifiers sent.
                $context = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']));
                Assert::same($householdId, $context->body()['data']['household']['id']);

                // A second household is refused (v1 rule) — again CSRF-guarded.
                try {
                    $household->create(self::cookieRequest(
                        'POST', '/api/v1/household', $session['token'], ['timezone' => 'UTC'],
                        [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                    ));
                    Assert::true(false, 'expected 409 HOUSEHOLD_EXISTS');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_EXISTS', $e->errorCode());
                    Assert::same(409, $e->httpStatus());
                }

                // Logout revokes the chain: context gone.
                (new AuthController())->logout(self::cookieRequest(
                    'POST', '/api/v1/auth/logout', $session['token'], [],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));

                try {
                    $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']));
                    Assert::true(false, 'expected 401 after logout');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('two households are fully isolated through the authenticated insight pipeline', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                $a = self::registeredAndLoggedInSession();
                $b = self::registeredAndLoggedInSession();

                $householdController = new HouseholdController();
                $householdA = (string) $householdController->create(self::cookieRequest(
                    'POST', '/api/v1/household', $a['token'], ['timezone' => 'Asia/Kolkata', 'label' => 'w3-workflow-a'],
                    [AuthMiddleware::CSRF_HEADER => $a['csrf']]
                ))->body()['data']['household']['id'];

                $householdB = (string) $householdController->create(self::cookieRequest(
                    'POST', '/api/v1/household', $b['token'], ['timezone' => 'Asia/Kolkata', 'label' => 'w3-workflow-b'],
                    [AuthMiddleware::CSRF_HEADER => $b['csrf']]
                ))->body()['data']['household']['id'];

                // Generate one real insight for A through the internal seam.
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $evidence = InsightEvidenceInput::fromArray([
                    'household_id' => $householdA,
                    'balance' => [
                        (new EnergyBalanceService())->classify(
                            [
                                'solar' => ['value_kwh' => 4.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                                'consumption' => ['value_kwh' => 1.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                            ],
                            'TIME_PERIOD',
                            ['start' => '2026-09-06T12:00:00', 'end' => '2026-09-06T12:00:00', 'resolution' => 'HOUR']
                        ),
                        (new EnergyBalanceService())->classify(
                            [
                                'solar' => ['value_kwh' => 5.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                                'consumption' => ['value_kwh' => 2.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                            ],
                            'TIME_PERIOD',
                            ['start' => '2026-09-06T13:00:00', 'end' => '2026-09-06T13:00:00', 'resolution' => 'HOUR']
                        ),
                    ],
                ]);
                $insights = (new InsightEngine())->evaluate($evidence, $now);
                Assert::true(count($insights) >= 1);
                $persisted = (new InsightRepository())->upsert($insights[0], $now);
                $persistedType = $persisted->type();

                $intelligence = new IntelligenceController();

                // A sees it through the full cookie pipeline.
                $listA = $intelligence->list(self::cookieRequest('GET', '/api/v1/intelligence/insights', $a['token'], query: [
                    'type' => $persistedType,
                    'limit' => '200',
                ]));
                $idsA = array_map(static fn (array $i): string => $i['id'], $listA->body()['data']['insights']);
                Assert::true(in_array($persisted->id(), $idsA, true), 'owner sees own insight through real auth pipeline');

                // B does not — same request shape, different session.
                $listB = $intelligence->list(self::cookieRequest('GET', '/api/v1/intelligence/insights', $b['token'], query: [
                    'type' => $persistedType,
                    'limit' => '200',
                ]));
                $idsB = array_map(static fn (array $i): string => $i['id'], $listB->body()['data']['insights']);
                Assert::true(!in_array($persisted->id(), $idsB, true), 'cross-household leak through the real pipeline');

                // B cannot even address A's scope explicitly.
                try {
                    $intelligence->list(self::cookieRequest('GET', '/api/v1/intelligence/insights', $b['token'], query: [
                        'household_id' => $householdA,
                    ]));
                    Assert::true(false, 'expected 403 claiming a foreign household through cookie auth');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_ACCESS_DENIED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }

                // B cannot read the detail by direct id.
                try {
                    $intelligence->show(self::cookieRequest('GET', '/api/v1/intelligence/insights/' . $persisted->id(), $b['token']));
                    Assert::true(false, 'expected 404 for foreign insight detail');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }

                // A's own detail works end to end.
                $own = $intelligence->show(self::cookieRequest('GET', '/api/v1/intelligence/insights/' . $persisted->id(), $a['token']));
                Assert::same(200, $own->status());
                Assert::same($householdA, $own->body()['data']['insight']['household_id']);
            }),
        ];
    }
}
