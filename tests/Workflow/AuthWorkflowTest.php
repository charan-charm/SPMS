<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Domain\Account\Account;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W1 — End-to-end authentication workflows (docs/02 §W1, docs/10 D-049–D-054).
 *
 * Real controllers + real middleware pipeline + real database + real
 * mail sink: register → verify → login → session → logout, and the full
 * recovery/reset cycle.
 */
final class AuthWorkflowTest
{
    private static function controller(): AuthController
    {
        AuthTesting::configure();

        return new AuthController();
    }

    private static function cookieRequest(string $method, string $path, string $rawSessionToken, array $body = [], array $extraHeaders = []): Request
    {
        return AuthMiddleware::process(Request::create($method, $path, body: $body, headers: array_merge([
            'Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $rawSessionToken,
        ], $extraHeaders), ipAddress: AuthTesting::uniqueIpAddress()));
    }

    public static function run(): array
    {
        return [
            TestResult::check('Registration → verification → login → session → logout lifecycle', function (): void {
                RequestId::set(RequestId::new());
                $email = AuthTesting::uniqueEmail();
                $ip = AuthTesting::uniqueIpAddress();

                // 1. Registration.
                $registered = self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: $ip
                ));
                Assert::same(201, $registered->status());
                Assert::same(Account::STATUS_PENDING_VERIFICATION, $registered->body()['data']['account']['status']);

                // Login before activation must fail.
                try {
                    self::controller()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $email, 'password' => 'correct-horse-battery'],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                    Assert::true(false, 'Pending accounts must not log in');
                } catch (AppException $e) {
                    Assert::same('INVALID_CREDENTIALS', $e->errorCode());
                }

                // 2. Verification through the delivered mail.
                $verifyToken = AuthTesting::latestMailToken('Verify your email');
                Assert::true(is_string($verifyToken));
                $verified = self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['token' => $verifyToken], ipAddress: AuthTesting::uniqueIpAddress()));
                Assert::same(Account::STATUS_ACTIVE, $verified->body()['data']['account']['status']);

                // 3. Login.
                $login = self::controller()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $login->status());
                $sessionToken = AuthTesting::sessionTokenFrom($login);
                $csrf = AuthTesting::csrfFrom($login->body()['data']);
                Assert::true(is_string($sessionToken) && is_string($csrf));

                // 4. Authenticated session.
                $session = self::controller()->session(self::cookieRequest('GET', '/api/v1/auth/session', (string) $sessionToken));
                Assert::same(200, $session->status());
                Assert::same(strtolower($email), $session->body()['data']['account']['email']);
                Assert::same(Account::ROLE_USER, $session->body()['data']['account']['role']);

                // 5. Logout (CSRF-protected) ends the session.
                $logout = self::controller()->logout(self::cookieRequest('POST', '/api/v1/auth/logout', (string) $sessionToken, [], ['X-SPMS-CSRF' => (string) $csrf]));
                Assert::same(200, $logout->status());

                try {
                    self::controller()->session(Request::create(
                        'GET', '/api/v1/auth/session',
                        headers: ['Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $sessionToken],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                    Assert::true(false, 'Logged-out session must not authenticate');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                }
            }),

            TestResult::check('Recovery request → reset → old password invalid → new password valid', function (): void {
                $email = AuthTesting::uniqueEmail();
                $ip = AuthTesting::uniqueIpAddress();

                self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: $ip
                ));
                self::controller()->verify(Request::create(
                    'POST', '/api/v1/auth/verify',
                    body: ['token' => (string) AuthTesting::latestMailToken('Verify your email')],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));

                $requested = self::controller()->recoveryRequest(Request::create(
                    'POST', '/api/v1/auth/recovery/request',
                    body: ['email' => $email],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $requested->status());

                $resetToken = AuthTesting::latestMailToken('Reset your password');
                Assert::true(is_string($resetToken), 'recovery mail must arrive through the sink');

                // Weak passwords are rejected server-side.
                try {
                    self::controller()->recoveryConfirm(Request::create(
                        'POST', '/api/v1/auth/recovery/confirm',
                        body: ['token' => $resetToken, 'password' => 'weak-pass'],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                    Assert::true(false, 'Expected password policy rejection');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same('password', $e->context()['field']);
                }

                $reset = self::controller()->recoveryConfirm(Request::create(
                    'POST', '/api/v1/auth/recovery/confirm',
                    body: ['token' => $resetToken, 'password' => 'the-replacement-password-9'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $reset->status());

                $oldError = null;
                try {
                    self::controller()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $email, 'password' => 'correct-horse-battery'],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $oldError = $e;
                }
                Assert::true($oldError instanceof AppException);
                Assert::same(401, $oldError->toResponse()->status());

                $newLogin = self::controller()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'the-replacement-password-9'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $newLogin->status());
            }),

            TestResult::check('Verification resend delivers a fresh working token', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));

                $resend = self::controller()->verifyResend(Request::create(
                    'POST', '/api/v1/auth/verify/resend',
                    body: ['email' => $email],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $resend->status());
                Assert::same(true, $resend->body()['data']['sent'], 'pending accounts receive the resend');

                $token = AuthTesting::latestMailToken('Verify your email');
                Assert::true(is_string($token));

                $verified = self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['token' => $token], ipAddress: AuthTesting::uniqueIpAddress()));
                Assert::same(Account::STATUS_ACTIVE, $verified->body()['data']['account']['status']);
            }),
        ];
    }
}
