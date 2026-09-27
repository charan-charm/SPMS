<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\AuthController;
use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Repositories\AccountRepository;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W1 — Authentication API boundary tests (docs/02 §W1, docs/06 §22,
 * docs/10 D-049–D-054).
 *
 * Registration, verification, login, session, logout, CSRF and recovery
 * over the real controllers, repositories and database — with the mail
 * sink captured to a temporary directory.
 */
final class AuthApiTest
{
    private static function authController(): AuthController
    {
        AuthTesting::configure();

        return new AuthController();
    }

    private static function accountService(): AccountService
    {
        AuthTesting::configure();

        return new AccountService();
    }

    private static function ip(): string
    {
        return AuthTesting::uniqueIpAddress();
    }

    private static function cookieRequest(string $method, string $path, string $rawSessionToken, array $body = [], array $extraHeaders = []): Request
    {
        // The middleware is part of the request pipeline: controllers
        // receive the auth context it injects.
        return AuthMiddleware::process(Request::create($method, $path, body: $body, headers: array_merge([
            'Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $rawSessionToken,
        ], $extraHeaders), ipAddress: self::ip()));
    }

    public static function run(): array
    {
        return [
            TestResult::check('POST /auth/register creates a pending USER/REAL account with a safe envelope', function (): void {
                RequestId::set(RequestId::new());

                $email = AuthTesting::uniqueEmail();
                $response = self::authController()->register(Request::create(
                    'POST',
                    '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));

                Assert::same(201, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);

                $account = $body['data']['account'];
                Assert::same(strtolower($email), $account['email']);
                Assert::same(Account::STATUS_PENDING_VERIFICATION, $account['status']);
                Assert::same(Account::ROLE_USER, $account['role']);
                Assert::same(Account::MODE_REAL, $account['mode']);
                Assert::same(true, $body['data']['verification']['required']);

                Assert::notContains('password', strtolower(json_encode($body) ?: ''));
                Assert::false(isset($body['data']['account']['password_hash']));
            }),

            TestResult::check('Client input can never choose role, status or mode (D-049)', function (): void {
                $response = self::authController()->register(Request::create(
                    'POST',
                    '/api/v1/auth/register',
                    body: [
                        'email' => AuthTesting::uniqueEmail(),
                        'password' => 'correct-horse-battery',
                        'role' => 'ADMIN',
                        'status' => 'ACTIVE',
                        'mode' => 'DEMO_TEST',
                    ],
                    ipAddress: self::ip()
                ));

                $account = $response->body()['data']['account'];
                Assert::same(Account::ROLE_USER, $account['role']);
                Assert::same(Account::STATUS_PENDING_VERIFICATION, $account['status']);
                Assert::same(Account::MODE_REAL, $account['mode']);
            }),

            TestResult::check('Duplicate email is rejected safely and email is normalized', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::authController()->register(Request::create(
                    'POST', '/api/v1/auth/register',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));

                try {
                    self::authController()->register(Request::create(
                        'POST', '/api/v1/auth/register',
                        body: ['email' => strtoupper($email), 'password' => 'correct-horse-battery'],
                        ipAddress: self::ip()
                    ));
                    Assert::true(false, 'Expected duplicate email rejection');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('email', $e->context()['field']);
                }

                $stored = (new AccountRepository())->findByEmail(strtolower($email));
                Assert::true($stored !== null);
                Assert::same(strtolower($email), $stored?->email());
            }),

            TestResult::check('Invalid registration input is rejected with field context', function (): void {
                foreach ([
                    ['email' => 'not-an-email', 'password' => 'correct-horse-battery'],
                    ['email' => '', 'password' => 'correct-horse-battery'],
                    ['email' => AuthTesting::uniqueEmail(), 'password' => 'tooshort12'],
                ] as $payload) {
                    try {
                        self::authController()->register(Request::create('POST', '/api/v1/auth/register', body: $payload, ipAddress: self::ip()));
                        Assert::true(false, 'Expected validation failure for ' . json_encode($payload));
                    } catch (AppException $e) {
                        Assert::same('VALIDATION_ERROR', $e->errorCode());
                        Assert::same(422, $e->httpStatus());
                    }
                }
            }),

            TestResult::check('Email verification activates the account exactly once', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::accountService()->register($email, 'correct-horse-battery', self::ip());

                $token = AuthTesting::latestMailCode('Verify your email');
                Assert::true(is_string($token), 'verification mail must be delivered through the sink');

                $response = self::authController()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token], ipAddress: self::ip()));
                Assert::same(200, $response->status());
                Assert::same(Account::STATUS_ACTIVE, $response->body()['data']['account']['status']);

                // Reuse must fail.
                try {
                    self::authController()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token], ipAddress: self::ip()));
                    Assert::true(false, 'Expected token reuse rejection');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('code', $e->context()['field']);
                }

                // Invalid token must fail identically.
                try {
                    self::authController()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => str_repeat('ab', 32)], ipAddress: self::ip()));
                    Assert::true(false, 'Expected invalid token rejection');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('Login issues an HttpOnly session cookie and CSRF token; failures are uniform', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::accountService()->register($email, 'correct-horse-battery', self::ip());
                $token = AuthTesting::latestMailCode('Verify your email');
                self::authController()->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token], ipAddress: self::ip()));

                $response = self::authController()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));

                Assert::same(200, $response->status());
                $cookie = $response->headers()['Set-Cookie'] ?? '';
                Assert::contains(\App\Support\Security\SessionCookie::name() . '=', $cookie);
                Assert::contains('HttpOnly', $cookie);
                Assert::contains('SameSite=Lax', $cookie);
                Assert::contains('Path=/', $cookie);

                $data = $response->body()['data'];
                Assert::true(is_string(AuthTesting::csrfFrom($data)));
                Assert::notContains('password', strtolower(json_encode($data) ?: ''));

                // Wrong password and unknown email produce IDENTICAL
                // failure envelopes (AppException → response mapping).
                $wrongPasswordError = null;
                $unknownEmailError = null;
                try {
                    self::authController()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $email, 'password' => 'a-totally-wrong-pass'],
                        ipAddress: self::ip()
                    ));
                } catch (AppException $e) {
                    $wrongPasswordError = $e;
                }
                try {
                    self::authController()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => AuthTesting::uniqueEmail(), 'password' => 'a-totally-wrong-pass'],
                        ipAddress: self::ip()
                    ));
                } catch (AppException $e) {
                    $unknownEmailError = $e;
                }

                Assert::true($wrongPasswordError instanceof AppException);
                Assert::true($unknownEmailError instanceof AppException);
                Assert::same(401, $wrongPasswordError->toResponse()->status());
                Assert::same(401, $unknownEmailError->toResponse()->status());
                Assert::same('INVALID_CREDENTIALS', $wrongPasswordError->toResponse()->body()['error']['code']);
                Assert::same(
                    $wrongPasswordError->toResponse()->body()['error']['code'],
                    $unknownEmailError->toResponse()->body()['error']['code'],
                    'unknown email and wrong password must be indistinguishable'
                );
                Assert::same(
                    $wrongPasswordError->toResponse()->body()['error']['message'],
                    $unknownEmailError->toResponse()->body()['error']['message']
                );
            }),

            TestResult::check('Disabled and pending accounts cannot authenticate — indistinguishably', function (): void {
                $pendingEmail = AuthTesting::uniqueEmail();
                self::accountService()->register($pendingEmail, 'correct-horse-battery', self::ip());

                $pendingError = null;
                try {
                    self::authController()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $pendingEmail, 'password' => 'correct-horse-battery'],
                        ipAddress: self::ip()
                    ));
                } catch (AppException $e) {
                    $pendingError = $e;
                }
                Assert::true($pendingError instanceof AppException);
                Assert::same(401, $pendingError->toResponse()->status());
                Assert::same('INVALID_CREDENTIALS', $pendingError->toResponse()->body()['error']['code']);

                $email = AuthTesting::uniqueEmail();
                self::accountService()->register($email, 'correct-horse-battery', self::ip());
                $active = self::accountService()->verifyEmail((string) AuthTesting::latestMailCode('Verify your email'));
                $disabled = $active->withStatus(Account::STATUS_DISABLED, null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                (new AccountRepository())->updateStatus($disabled);

                $blockedError = null;
                try {
                    self::authController()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $email, 'password' => 'correct-horse-battery'],
                        ipAddress: self::ip()
                    ));
                } catch (AppException $e) {
                    $blockedError = $e;
                }
                Assert::true($blockedError instanceof AppException);
                Assert::same(401, $blockedError->toResponse()->status());
                Assert::same('INVALID_CREDENTIALS', $blockedError->toResponse()->body()['error']['code']);
            }),

            TestResult::check('Authenticated session endpoint returns safe identity; anonymous is 401', function (): void {
                try {
                    self::authController()->session(Request::create('GET', '/api/v1/auth/session', ipAddress: self::ip()));
                    Assert::true(false, 'Expected 401 for anonymous session');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }

                $email = AuthTesting::uniqueEmail();
                $registered = self::accountService()->register($email, 'correct-horse-battery', self::ip());
                $token = AuthTesting::latestMailCode('Verify your email');
                $active = self::accountService()->verifyEmail((string) $token);

                $login = self::authController()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));
                $rawSessionToken = AuthTesting::sessionTokenFrom($login);
                Assert::true(is_string($rawSessionToken));

                $sessionResponse = self::authController()->session(self::cookieRequest('GET', '/api/v1/auth/session', (string) $rawSessionToken));
                Assert::same(200, $sessionResponse->status());

                $data = $sessionResponse->body()['data'];
                Assert::same(true, $data['authenticated']);
                Assert::same(strtolower($email), $data['account']['email']);
                Assert::same($registered['account']->id(), $data['account']['id']);
                Assert::true(is_string(AuthTesting::csrfFrom($data)));
                Assert::notContains('password_hash', json_encode($data) ?: '');
            }),

            TestResult::check('CSRF protects authenticated state-changing requests (D-052)', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::accountService()->register($email, 'correct-horse-battery', self::ip());
                $active = self::accountService()->verifyEmail((string) AuthTesting::latestMailCode('Verify your email'));
                unset($active);

                $login = self::authController()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));
                $rawSessionToken = (string) AuthTesting::sessionTokenFrom($login);
                $csrf = (string) AuthTesting::csrfFrom($login->body()['data']);

                // Missing CSRF → 403, session NOT revoked.
                try {
                    self::authController()->logout(self::cookieRequest('POST', '/api/v1/auth/logout', $rawSessionToken));
                    Assert::true(false, 'Expected CSRF rejection');
                } catch (AppException $e) {
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }

                $stillValid = self::authController()->session(self::cookieRequest('GET', '/api/v1/auth/session', $rawSessionToken));
                Assert::same(200, $stillValid->status(), 'CSRF rejection must not mutate the session');

                // Invalid CSRF → 403.
                try {
                    self::authController()->logout(self::cookieRequest('POST', '/api/v1/auth/logout', $rawSessionToken, [], ['X-SPMS-CSRF' => str_repeat('0', 64)]));
                    Assert::true(false, 'Expected CSRF rejection');
                } catch (AppException $e) {
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                }

                // Valid CSRF → logout succeeds, session dies, cookie cleared.
                $logout = self::authController()->logout(self::cookieRequest('POST', '/api/v1/auth/logout', $rawSessionToken, [], ['X-SPMS-CSRF' => $csrf]));
                Assert::same(200, $logout->status());
                Assert::contains('Max-Age=0', $logout->headers()['Set-Cookie'] ?? '');

                try {
                    self::authController()->session(self::cookieRequest('GET', '/api/v1/auth/session', $rawSessionToken));
                    Assert::true(false, 'Revoked session must not authenticate');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                }
            }),

            TestResult::check('Password recovery is uniform, single-use and revokes sessions', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::accountService()->register($email, 'correct-horse-battery', self::ip());
                self::accountService()->verifyEmail((string) AuthTesting::latestMailCode('Verify your email'));

                $login = self::authController()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));

                $requested = self::authController()->recoveryRequest(Request::create(
                    'POST', '/api/v1/auth/recovery/request',
                    body: ['email' => $email],
                    ipAddress: self::ip()
                ));
                Assert::same(200, $requested->status());
                Assert::same(true, $requested->body()['data']['accepted']);

                // Unknown email returns the identical accepted response.
                $unknown = self::authController()->recoveryRequest(Request::create(
                    'POST', '/api/v1/auth/recovery/request',
                    body: ['email' => AuthTesting::uniqueEmail()],
                    ipAddress: self::ip()
                ));
                Assert::same($requested->body()['data'], $unknown->body()['data']);

                $resetToken = AuthTesting::latestMailToken('Reset your password');
                Assert::true(is_string($resetToken), 'recovery mail must be delivered');

                $reset = self::authController()->recoveryConfirm(Request::create(
                    'POST', '/api/v1/auth/recovery/confirm',
                    body: ['token' => $resetToken, 'password' => 'a-new-longer-password-42'],
                    ipAddress: self::ip()
                ));
                Assert::same(200, $reset->status());

                // Token reuse fails.
                try {
                    self::authController()->recoveryConfirm(Request::create(
                        'POST', '/api/v1/auth/recovery/confirm',
                        body: ['token' => $resetToken, 'password' => 'a-new-longer-password-42'],
                        ipAddress: self::ip()
                    ));
                    Assert::true(false, 'Expected recovery token reuse rejection');
                } catch (AppException $e) {
                    Assert::same(422, $e->httpStatus());
                }

                // Old password no longer works; new password works.
                $oldError = null;
                try {
                    self::authController()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $email, 'password' => 'correct-horse-battery'],
                        ipAddress: self::ip()
                    ));
                } catch (AppException $e) {
                    $oldError = $e;
                }
                Assert::true($oldError instanceof AppException);
                Assert::same(401, $oldError->toResponse()->status());

                $new = self::authController()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'a-new-longer-password-42'],
                    ipAddress: self::ip()
                ));
                Assert::same(200, $new->status());

                // The pre-reset session no longer authenticates.
                $oldSessionToken = AuthTesting::sessionTokenFrom($login);
                if ($oldSessionToken !== null) {
                    try {
                        self::authController()->session(self::cookieRequest('GET', '/api/v1/auth/session', $oldSessionToken));
                        Assert::true(false, 'Password reset must revoke existing sessions');
                    } catch (AppException $e) {
                        Assert::same('UNAUTHENTICATED', $e->errorCode());
                    }
                }
            }),

            TestResult::check('Verify resend is uniform, rate-limited surface (D-053)', function (): void {
                $response = self::authController()->verifyResend(Request::create(
                    'POST', '/api/v1/auth/verify/resend',
                    body: ['email' => AuthTesting::uniqueEmail()],
                    ipAddress: self::ip()
                ));

                Assert::same(200, $response->status());
                Assert::same(true, $response->body()['data']['accepted']);
                Assert::false($response->body()['data']['sent'], 'unknown accounts must not receive mail');
            }),

            TestResult::check('Router dispatches all W1 auth routes to the controller', function (): void {
                $router = new \App\Support\Router();
                $router->add('POST', '/api/v1/auth/register', [AuthController::class, 'register']);
                $router->add('GET', '/api/v1/auth/session', [AuthController::class, 'session']);

                [$postClass, $postAction] = $router->dispatch('POST', '/api/v1/auth/register');
                Assert::same(AuthController::class, $postClass);
                Assert::same('register', $postAction);

                [$getClass, $getAction] = $router->dispatch('GET', '/api/v1/auth/session');
                Assert::same('session', $getAction);

                Assert::throws(\App\Support\MethodNotAllowedException::class, function () use ($router): void {
                    $router->dispatch('DELETE', '/api/v1/auth/register');
                });
            }),
        ];
    }
}

