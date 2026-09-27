<?php

declare(strict_types=1);

namespace Tests\Security;

use App\App;
use App\Domain\Recommendation\Recommendation;
use App\Domain\Recommendation\RecommendationConfidence;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Repositories\RecommendationRepository;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Security\SessionCookie;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class RecommendationSecurityTest
{
    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    public static function run(): array
    {
        AuthTesting::configure();

        return [
            TestResult::check('Cross-household recommendation detail returns 404 (indistinguishable from nonexistent)', function (): void {
                RequestId::set(RequestId::new());
                $owner = AuthTesting::householdFixture();
                $intruder = AuthTesting::householdFixture();

                $repo = new RecommendationRepository(Database::connection());
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $owner['household_id'],
                    type: RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Owner Confidential Recommendation',
                    summary: 'Private data',
                    action: 'Do action',
                    reason: 'Private reason',
                    timingWindow: '12:00 - 14:00',
                    potentialImpact: 'Potential 3 kWh savings',
                    impactJson: null,
                    evidenceJson: [],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );
                $repo->save($rec);

                $controller = new \App\Controllers\RecommendationController();

                // Intruder requests detail
                $intruderReq = AuthTesting::authed(Request::create('GET', "/api/v1/recommendations/{$recId}"), $intruder['account']);
                try {
                    $controller->show($intruderReq, ['id' => $recId]);
                    Assert::true(false, 'Intruder must be rejected with 404');
                } catch (\App\Support\AppException $e) {
                    Assert::same(404, $e->httpStatus());
                    Assert::same('NOT_FOUND', $e->errorCode());
                }
            }),

            TestResult::check('Cross-household dismiss returns 404 and does not mutate record', function (): void {
                RequestId::set(RequestId::new());
                $owner = AuthTesting::householdFixture();
                $intruder = AuthTesting::householdFixture();

                $repo = new RecommendationRepository(Database::connection());
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $owner['household_id'],
                    type: RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Owner Recommendation',
                    summary: 'Active recommendation',
                    action: 'Run appliance',
                    reason: 'Solar surplus',
                    timingWindow: '11:00 - 14:00',
                    potentialImpact: null,
                    impactJson: null,
                    evidenceJson: [],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );
                $repo->save($rec);

                $controller = new \App\Controllers\RecommendationController();

                $intruderDismissReq = AuthTesting::authed(Request::create('POST', "/api/v1/recommendations/{$recId}/dismiss"), $intruder['account']);
                try {
                    $controller->dismiss($intruderDismissReq, ['id' => $recId]);
                    Assert::true(false, 'Intruder dismiss must be rejected with 404');
                } catch (\App\Support\AppException $e) {
                    Assert::same(404, $e->httpStatus());
                }

                // Verify the recommendation was NOT dismissed
                $fresh = $repo->findById($recId);
                Assert::true($fresh !== null);
                Assert::same(Recommendation::STATUS_ACTIVE, $fresh->status());
                Assert::true($fresh->dismissedAt() === null);
            }),

            TestResult::check('Mutation endpoints reject requests missing CSRF token', function (): void {
                RequestId::set(RequestId::new());
                $account = AuthTesting::activateAccount('csrf-test-');
                $login = (new \App\Controllers\AuthController())->login(
                    Request::create('POST', '/api/v1/auth/login', body: [
                        'email' => $account->email(),
                        'password' => 'correct-horse-battery',
                    ])
                );
                $rawToken = (string) AuthTesting::sessionTokenFrom($login);

                // POST generate without CSRF header
                $reqNoCsrf = Request::create(
                    'POST',
                    '/api/v1/recommendations/generate',
                    headers: ['Cookie' => (SessionCookie::name() . '=') . $rawToken]
                );

                try {
                    \App\Security\AuthMiddleware::process($reqNoCsrf);
                    Assert::true(false, 'Missing CSRF must throw AppException');
                } catch (\App\Support\AppException $e) {
                    Assert::same(403, $e->httpStatus(), 'Missing CSRF must yield 403 Forbidden');
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                }
            }),

            TestResult::check('/recommendations/apply cannot control or schedule appliances and fails closed with 404', function (): void {
                RequestId::set(RequestId::new());

                // 1. Verify RecommendationController has zero apply or appliance-control methods
                $controllerRef = new \ReflectionClass(\App\Controllers\RecommendationController::class);
                Assert::false($controllerRef->hasMethod('apply'), 'RecommendationController must NOT have an apply method');
                Assert::false($controllerRef->hasMethod('schedule'), 'RecommendationController must NOT have a schedule method');
                Assert::false($controllerRef->hasMethod('control'), 'RecommendationController must NOT have a control method');

                // 2. Verify RecommendationService has zero appliance automation or control methods
                $serviceRef = new \ReflectionClass(\App\Domain\Recommendation\RecommendationService::class);
                Assert::false($serviceRef->hasMethod('apply'), 'RecommendationService must NOT have an apply method');
                Assert::false($serviceRef->hasMethod('scheduleAppliance'), 'RecommendationService must NOT have scheduleAppliance');
                Assert::false($serviceRef->hasMethod('controlDevice'), 'RecommendationService must NOT have controlDevice');

                // 3. Verify Router registers NO apply route of any kind (hasRoute is false)
                $router = new \App\Support\Router();
                $refApp = new \ReflectionClass(App::class);
                $registerMethod = $refApp->getMethod('registerRoutes');
                $app = App::create(dirname(__DIR__, 2));
                $registerMethod->invoke($app, $router);

                Assert::false($router->hasRoute('POST', '/api/v1/recommendations/apply'));
                Assert::false($router->hasRoute('POST', '/api/v1/recommendations/{id}/apply'));
                Assert::false($router->hasRoute('PUT', '/api/v1/recommendations/apply'));
                Assert::false($router->hasRoute('PATCH', '/api/v1/recommendations/apply'));

                // 4. Any direct dispatch to /api/v1/recommendations/{id}/apply throws NotFoundException
                $recId = self::uuid();
                Assert::throws(\App\Support\NotFoundException::class, function () use ($router, $recId): void {
                    $router->dispatch('POST', "/api/v1/recommendations/{$recId}/apply");
                });
            }),
        ];
    }
}
