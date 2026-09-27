<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Controllers\HouseholdController;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W3.1 — Profile completion through the real authentication pipeline.
 *
 * Cookie session + CSRF middleware (not the synthetic controller
 * context): PATCH /api/v1/household behaves like every other
 * authenticated mutation, and the derived readiness projection is
 * verified end to end: onboarding state is reconstructible from
 * authoritative reads alone — no persisted wizard state exists.
 */
final class HouseholdProfileWorkflowTest
{
    private static function cookieRequest(string $method, string $path, string $rawSessionToken, array $body = [], array $extraHeaders = []): Request
    {
        return AuthMiddleware::process(Request::create($method, $path, body: $body, headers: array_merge([
            'Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $rawSessionToken,
        ], $extraHeaders), ipAddress: AuthTesting::uniqueIpAddress()));
    }

    /**
     * @return array{token: string, csrf: string, email: string}
     */
    private static function registeredAndLoggedInSession(): array
    {
        $auth = new AuthController();
        $email = AuthTesting::uniqueEmail();

        $auth->register(Request::create('POST', '/api/v1/auth/register', body: [
            'email' => $email,
            'password' => 'correct-horse-battery',
        ], ipAddress: AuthTesting::uniqueIpAddress()));

        $token = AuthTesting::latestMailCode('Verify your email');
        Assert::true($token !== null, 'verification mail must reach the sink');
        $auth->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token]));

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
            TestResult::check('onboarding reconstruct: verify → login → create → complete profile → review readiness → logout', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();
                $session = self::registeredAndLoggedInSession();
                $household = new HouseholdController();

                // No household yet: null context, incomplete readiness.
                $start = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']))->body()['data'];
                Assert::same(null, $start['household']);
                Assert::same(true, $start['readiness']['email_verified']);
                Assert::same(false, $start['readiness']['timezone_set']);
                Assert::same(false, $start['readiness']['location_provided']);
                Assert::same('UNAVAILABLE', $start['readiness']['solar']);
                Assert::same('UNAVAILABLE', $start['readiness']['verified_bills']);
                Assert::same('LIMITED', $start['readiness']['analysis']);

                // Household Setup — the ratified creation primitive.
                $created = $household->create(self::cookieRequest(
                    'POST', '/api/v1/household', $session['token'], ['timezone' => 'Asia/Kolkata', 'label' => 'w31-workflow-home'],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));
                Assert::same(201, $created->status());
                $householdId = (string) $created->body()['data']['household']['id'];

                // Location Setup — profile completion over the same own-route.
                $patched = $household->update(self::cookieRequest(
                    'PATCH', '/api/v1/household', $session['token'], ['location_label' => 'Anantapur, Andhra Pradesh'],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));
                Assert::same(200, $patched->status());
                Assert::same($householdId, $patched->body()['data']['household']['id']);
                Assert::same('Anantapur, Andhra Pradesh', $patched->body()['data']['household']['location_label']);
                Assert::same('w31-workflow-home', $patched->body()['data']['household']['label'], 'partial PATCH preserved the label');

                // Initial review state — reconstructible from the read alone.
                $review = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']))->body()['data'];
                Assert::same(true, $review['readiness']['timezone_set']);
                Assert::same(true, $review['readiness']['location_provided']);
                Assert::same('UNAVAILABLE', $review['readiness']['solar']);
                Assert::same($householdId, $review['household']['id']);

                // Logout ends the chain honestly.
                (new AuthController())->logout(self::cookieRequest(
                    'POST', '/api/v1/auth/logout', $session['token'], [],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));

                try {
                    $household->update(self::cookieRequest('PATCH', '/api/v1/household', $session['token'], ['timezone' => 'UTC']));
                    Assert::true(false, 'expected 401 after logout');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('PATCH without CSRF is rejected by the middleware and changes nothing', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();
                $session = self::registeredAndLoggedInSession();
                $household = new HouseholdController();

                $created = $household->create(self::cookieRequest(
                    'POST', '/api/v1/household', $session['token'], ['timezone' => 'Asia/Kolkata', 'label' => 'w31-csrf-home'],
                    [AuthMiddleware::CSRF_HEADER => $session['csrf']]
                ));
                $householdId = (string) $created->body()['data']['household']['id'];

                try {
                    $household->update(self::cookieRequest(
                        'PATCH', '/api/v1/household', $session['token'], ['timezone' => 'Europe/Berlin']
                    ));
                    Assert::true(false, 'expected CSRF rejection for authenticated mutation');
                } catch (AppException $e) {
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }

                // A wrong token is rejected too — and nothing was written.
                try {
                    $household->update(self::cookieRequest(
                        'PATCH', '/api/v1/household', $session['token'], ['timezone' => 'Europe/Berlin'],
                        [AuthMiddleware::CSRF_HEADER => 'wrong-token']
                    ));
                    Assert::true(false, 'expected CSRF rejection for the wrong token');
                } catch (AppException $e) {
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                }

                $context = $household->current(self::cookieRequest('GET', '/api/v1/household', $session['token']))->body()['data'];
                Assert::same($householdId, $context['household']['id']);
                Assert::same('Asia/Kolkata', $context['household']['timezone'], 'rejected mutations leave the profile untouched');
            }),
        ];
    }
}

