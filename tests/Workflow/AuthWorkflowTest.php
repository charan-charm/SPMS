<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Domain\Account\Account;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Security\TokenHasher;
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
                $verifyToken = AuthTesting::latestMailCode('Verify your email');
                Assert::true(is_string($verifyToken));
                $verified = self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $verifyToken], ipAddress: AuthTesting::uniqueIpAddress()));
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
                    body: ['code' => (string) AuthTesting::latestMailCode('Verify your email')],
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
                Assert::same(6, strlen($resetToken), 'recovery code must be a 6-digit numeric string');

                // Verify recovery code before setting new password (Step 2)
                $verifyRes = self::controller()->recoveryVerify(Request::create(
                    'POST', '/api/v1/auth/recovery/verify',
                    body: ['code' => $resetToken, 'email' => $email],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $verifyRes->status());
                Assert::same(true, $verifyRes->body()['data']['valid']);

                // Weak passwords are rejected server-side.
                try {
                    self::controller()->recoveryConfirm(Request::create(
                        'POST', '/api/v1/auth/recovery/confirm',
                        body: ['code' => $resetToken, 'password' => 'weak-pass'],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                    Assert::true(false, 'Expected password policy rejection');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same('password', $e->context()['field']);
                }

                $reset = self::controller()->recoveryConfirm(Request::create(
                    'POST', '/api/v1/auth/recovery/confirm',
                    body: ['code' => $resetToken, 'password' => 'the-replacement-password-9'],
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

                $token = AuthTesting::latestMailCode('Verify your email');
                Assert::true(is_string($token));

                $verified = self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token], ipAddress: AuthTesting::uniqueIpAddress()));
                Assert::same(Account::STATUS_ACTIVE, $verified->body()['data']['account']['status']);
            }),

            TestResult::check('Regression: code with leading zeroes "003786" verifies successfully and activates account', function (): void {
                $email = AuthTesting::uniqueEmail();
                $reg = self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                $accountId = $reg->body()['data']['account']['id'];

                // Overwrite the token hash with exactly hash('sha256', '003786')
                $pdo = Database::connection();
                $code = '003786';
                $hash = TokenHasher::hash($code);
                $pdo->prepare("UPDATE account_tokens SET token_hash = :hash WHERE account_id = :acc AND type = 'EMAIL_VERIFICATION'")
                    ->execute(['hash' => $hash, 'acc' => $accountId]);

                // Verify with string '003786'
                $verified = self::controller()->verify(Request::create(
                    'POST', '/api/v1/auth/verify',
                    body: ['code' => '003786', 'email' => $email],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $verified->status());
                Assert::same(Account::STATUS_ACTIVE, $verified->body()['data']['account']['status']);

                // Account is now ACTIVE in database
                $stmt = $pdo->prepare("SELECT status FROM accounts WHERE id = :id");
                $stmt->execute(['id' => $accountId]);
                Assert::same('ACTIVE', $stmt->fetchColumn());
            }),

            TestResult::check('Wrong verification code fails with 422', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));

                $failed = false;
                try {
                    self::controller()->verify(Request::create(
                        'POST', '/api/v1/auth/verify',
                        body: ['code' => '000000', 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $failed = true;
                    Assert::same(422, $e->httpStatus());
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::true(str_contains(strtolower($e->getMessage()), 'invalid'));
                }
                Assert::true($failed, 'Wrong code must be rejected with 422');
            }),

            TestResult::check('Expired verification code fails with 422', function (): void {
                $email = AuthTesting::uniqueEmail();
                $reg = self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                $accountId = $reg->body()['data']['account']['id'];
                $token = (string) AuthTesting::latestMailCode('Verify your email');

                // Mark expired
                $pdo = Database::connection();
                $pdo->prepare("UPDATE account_tokens SET expires_at = '2020-01-01 00:00:00' WHERE account_id = :acc")
                    ->execute(['acc' => $accountId]);

                $failed = false;
                try {
                    self::controller()->verify(Request::create(
                        'POST', '/api/v1/auth/verify',
                        body: ['code' => $token, 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $failed = true;
                    Assert::same(422, $e->httpStatus());
                    Assert::true(str_contains(strtolower($e->getMessage()), 'expired'));
                }
                Assert::true($failed, 'Expired code must be rejected with 422');
            }),

            TestResult::check('Used verification code cannot be reused (single-use guard)', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                $token = (string) AuthTesting::latestMailCode('Verify your email');

                // First verify succeeds
                $v1 = self::controller()->verify(Request::create(
                    'POST', '/api/v1/auth/verify',
                    body: ['code' => $token, 'email' => $email],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $v1->status());

                // Second verify with same code must fail
                $failed = false;
                try {
                    self::controller()->verify(Request::create(
                        'POST', '/api/v1/auth/verify',
                        body: ['code' => $token, 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $failed = true;
                    Assert::same(422, $e->httpStatus());
                    Assert::true(str_contains(strtolower($e->getMessage()), 'already been used') || str_contains(strtolower($e->getMessage()), 'invalid'));
                }
                Assert::true($failed, 'Re-using a verification code must be rejected with 422');
            }),

            TestResult::check('Too many failed attempts blocks verification with 429 RATE_LIMITED', function (): void {
                $email = AuthTesting::uniqueEmail();
                $reg = self::controller()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                $accountId = $reg->body()['data']['account']['id'];
                $token = (string) AuthTesting::latestMailCode('Verify your email');

                // Record 5 verification failures for this account
                $pdo = Database::connection();
                for ($i = 0; $i < 5; $i++) {
                    $pdo->prepare("INSERT INTO security_events (id, event_type, account_id, created_at) VALUES (UUID(), 'VERIFICATION_FAILED', :acc, NOW())")
                        ->execute(['acc' => $accountId]);
                }

                // Attempting to verify now (even with the correct code) must be blocked by rate limit
                $blocked = false;
                try {
                    self::controller()->verify(Request::create(
                        'POST', '/api/v1/auth/verify',
                        body: ['code' => $token, 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $blocked = true;
                    Assert::same(429, $e->httpStatus());
                    Assert::same('RATE_LIMITED', $e->errorCode());
                }
                Assert::true($blocked, 'Excessive verification failures must trigger 429 RATE_LIMITED');
            }),

            TestResult::check('Wrong recovery code fails with 422', function (): void {
                $email = AuthTesting::uniqueEmail();
                $reg = self::controller()->register(Request::create('POST', '/api/v1/auth/register', body: ['email' => $email, 'password' => 'correct-horse-battery'], ipAddress: AuthTesting::uniqueIpAddress()));
                self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => (string) AuthTesting::latestMailCode('Verify your email')], ipAddress: AuthTesting::uniqueIpAddress()));

                self::controller()->recoveryRequest(Request::create('POST', '/api/v1/auth/recovery/request', body: ['email' => $email], ipAddress: AuthTesting::uniqueIpAddress()));

                $failed = false;
                try {
                    self::controller()->recoveryVerify(Request::create(
                        'POST', '/api/v1/auth/recovery/verify',
                        body: ['code' => '999999', 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $failed = true;
                    Assert::same(422, $e->httpStatus());
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                }
                Assert::true($failed, 'Wrong recovery code must be rejected with 422');
            }),

            TestResult::check('Expired recovery code fails with 422', function (): void {
                $email = AuthTesting::uniqueEmail();
                $reg = self::controller()->register(Request::create('POST', '/api/v1/auth/register', body: ['email' => $email, 'password' => 'correct-horse-battery'], ipAddress: AuthTesting::uniqueIpAddress()));
                $accountId = $reg->body()['data']['account']['id'];
                self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => (string) AuthTesting::latestMailCode('Verify your email')], ipAddress: AuthTesting::uniqueIpAddress()));

                self::controller()->recoveryRequest(Request::create('POST', '/api/v1/auth/recovery/request', body: ['email' => $email], ipAddress: AuthTesting::uniqueIpAddress()));
                $token = (string) AuthTesting::latestMailToken('Reset your password');

                // Mark expired
                $pdo = Database::connection();
                $pdo->prepare("UPDATE account_tokens SET expires_at = '2020-01-01 00:00:00' WHERE account_id = :acc AND type = 'PASSWORD_RECOVERY'")
                    ->execute(['acc' => $accountId]);

                $failed = false;
                try {
                    self::controller()->recoveryVerify(Request::create(
                        'POST', '/api/v1/auth/recovery/verify',
                        body: ['code' => $token, 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $failed = true;
                    Assert::same(422, $e->httpStatus());
                    Assert::true(str_contains(strtolower($e->getMessage()), 'expired'));
                }
                Assert::true($failed, 'Expired recovery code must be rejected with 422');
            }),

            TestResult::check('Used recovery code cannot be reused (single-use guard)', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::controller()->register(Request::create('POST', '/api/v1/auth/register', body: ['email' => $email, 'password' => 'correct-horse-battery'], ipAddress: AuthTesting::uniqueIpAddress()));
                self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => (string) AuthTesting::latestMailCode('Verify your email')], ipAddress: AuthTesting::uniqueIpAddress()));

                self::controller()->recoveryRequest(Request::create('POST', '/api/v1/auth/recovery/request', body: ['email' => $email], ipAddress: AuthTesting::uniqueIpAddress()));
                $token = (string) AuthTesting::latestMailToken('Reset your password');

                // First reset succeeds
                $res = self::controller()->recoveryConfirm(Request::create(
                    'POST', '/api/v1/auth/recovery/confirm',
                    body: ['code' => $token, 'password' => 'first-new-password-123'],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $res->status());

                // Second reset with same code must fail
                $failed = false;
                try {
                    self::controller()->recoveryConfirm(Request::create(
                        'POST', '/api/v1/auth/recovery/confirm',
                        body: ['code' => $token, 'password' => 'second-new-password-123'],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $failed = true;
                    Assert::same(422, $e->httpStatus());
                }
                Assert::true($failed, 'Reusing recovery code must fail with 422');
            }),

            TestResult::check('Too many failed recovery attempts blocks verification with 429 RATE_LIMITED', function (): void {
                $email = AuthTesting::uniqueEmail();
                $reg = self::controller()->register(Request::create('POST', '/api/v1/auth/register', body: ['email' => $email, 'password' => 'correct-horse-battery'], ipAddress: AuthTesting::uniqueIpAddress()));
                $accountId = $reg->body()['data']['account']['id'];
                self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => (string) AuthTesting::latestMailCode('Verify your email')], ipAddress: AuthTesting::uniqueIpAddress()));

                self::controller()->recoveryRequest(Request::create('POST', '/api/v1/auth/recovery/request', body: ['email' => $email], ipAddress: AuthTesting::uniqueIpAddress()));
                $token = (string) AuthTesting::latestMailToken('Reset your password');

                // Record 5 PASSWORD_RESET_FAILED events
                $pdo = Database::connection();
                for ($i = 0; $i < 5; $i++) {
                    $pdo->prepare("INSERT INTO security_events (id, event_type, account_id, created_at) VALUES (UUID(), 'PASSWORD_RESET_FAILED', :acc, NOW())")
                        ->execute(['acc' => $accountId]);
                }

                $blocked = false;
                try {
                    self::controller()->recoveryVerify(Request::create(
                        'POST', '/api/v1/auth/recovery/verify',
                        body: ['code' => $token, 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $blocked = true;
                    Assert::same(429, $e->httpStatus());
                    Assert::same('RATE_LIMITED', $e->errorCode());
                }
                Assert::true($blocked, 'Excessive recovery failures must trigger 429 RATE_LIMITED');
            }),

            TestResult::check('New recovery request invalidates prior recovery code', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::controller()->register(Request::create('POST', '/api/v1/auth/register', body: ['email' => $email, 'password' => 'correct-horse-battery'], ipAddress: AuthTesting::uniqueIpAddress()));
                self::controller()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => (string) AuthTesting::latestMailCode('Verify your email')], ipAddress: AuthTesting::uniqueIpAddress()));

                // First recovery request
                self::controller()->recoveryRequest(Request::create('POST', '/api/v1/auth/recovery/request', body: ['email' => $email], ipAddress: AuthTesting::uniqueIpAddress()));
                $firstToken = (string) AuthTesting::latestMailToken('Reset your password');

                // Second recovery request
                self::controller()->recoveryRequest(Request::create('POST', '/api/v1/auth/recovery/request', body: ['email' => $email], ipAddress: AuthTesting::uniqueIpAddress()));
                $secondToken = (string) AuthTesting::latestMailToken('Reset your password');

                // First token must now fail (it was revoked)
                $failed = false;
                try {
                    self::controller()->recoveryVerify(Request::create(
                        'POST', '/api/v1/auth/recovery/verify',
                        body: ['code' => $firstToken, 'email' => $email],
                        ipAddress: AuthTesting::uniqueIpAddress()
                    ));
                } catch (AppException $e) {
                    $failed = true;
                    Assert::same(422, $e->httpStatus());
                }
                Assert::true($failed, 'Prior recovery code must be invalidated upon new request');

                // Second token must succeed
                $res = self::controller()->recoveryVerify(Request::create(
                    'POST', '/api/v1/auth/recovery/verify',
                    body: ['code' => $secondToken, 'email' => $email],
                    ipAddress: AuthTesting::uniqueIpAddress()
                ));
                Assert::same(200, $res->status());
            }),
        ];
    }
}


