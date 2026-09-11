<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\AuthController;
use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\RateLimiter;
use App\Domain\Auth\SessionService;
use App\Repositories\AccountRepository;
use App\Repositories\SessionRepository;
use App\Security\AuthMiddleware;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Response;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W1 — Security assertions (docs/10 D-051–D-053, docs/08 §5–§12).
 *
 * Cookie flags, raw-token never-persisted guarantees, session fixation
 * defense, DB-backed throttling with no permanent lockout, password
 * log redaction, authentication bypass and CSRF mutation safety.
 */
final class AuthSecurityTest
{
    private static function ip(): string
    {
        return AuthTesting::uniqueIpAddress();
    }

    private static function controller(): AuthController
    {
        AuthTesting::configure();

        return new AuthController();
    }

    private static function service(): AccountService
    {
        AuthTesting::configure();

        return new AccountService();
    }

    private static function activeAccountWithLogin(?string &$email, ?string &$rawToken, ?string &$csrf): void
    {
        $email = AuthTesting::uniqueEmail();
        self::service()->register($email, 'correct-horse-battery', self::ip());
        self::service()->verifyEmail((string) AuthTesting::latestMailToken('Verify your email'));

        $login = self::controller()->login(Request::create(
            'POST', '/api/v1/auth/login',
            body: ['email' => $email, 'password' => 'correct-horse-battery'],
            ipAddress: self::ip()
        ));

        $rawToken = (string) AuthTesting::sessionTokenFrom($login);
        $csrf = (string) AuthTesting::csrfFrom($login->body()['data']);
    }

    public static function run(): array
    {
        return [
            TestResult::check('Session cookie carries the ratified flags; Secure appears only in production (D-051)', function (): void {
                AuthTesting::configure();
                $dev = SessionCookieHeader::issue();

                Assert::contains('HttpOnly', $dev);
                Assert::contains('SameSite=Lax', $dev);
                Assert::contains('Path=/', $dev);
                Assert::notContains('Secure', $dev, 'local HTTP development must not set Secure');

                Config::overrideForTesting(['APP_ENV' => 'production']);
                $prod = SessionCookieHeader::issue();
                Config::overrideForTesting(['APP_ENV' => 'development']);

                Assert::contains('Secure', $prod);
                Assert::contains('Max-Age=', $prod);
            }),

            TestResult::check('Raw session identifiers are never stored — only SHA-256 hashes (D-051)', function (): void {
                $email = null; $rawToken = null; $csrf = null;
                self::activeAccountWithLogin($email, $rawToken, $csrf);

                $pdo = Database::connection();

                $rawStored = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE session_token_hash = :raw');
                $rawStored->execute(['raw' => $rawToken]);
                Assert::same(0, (int) $rawStored->fetchColumn(), 'raw session token must never be stored');

                $hashStored = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE session_token_hash = :hash');
                $hashStored->execute(['hash' => hash('sha256', (string) $rawToken)]);
                Assert::same(1, (int) $hashStored->fetchColumn());
            }),

            TestResult::check('Raw verification and recovery tokens are never stored (D-053)', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::service()->register($email, 'correct-horse-battery', self::ip());

                $verificationToken = (string) AuthTesting::latestMailToken('Verify your email');
                self::service()->verifyEmail($verificationToken);

                self::service()->requestPasswordRecovery($email, self::ip());
                $recoveryToken = (string) AuthTesting::latestMailToken('Reset your password');

                $pdo = Database::connection();
                $check = $pdo->prepare('SELECT COUNT(*) FROM account_tokens WHERE token_hash = :raw');
                $check->execute(['raw' => $verificationToken]);
                Assert::same(0, (int) $check->fetchColumn(), 'raw verification token must never be stored');
                $check->execute(['raw' => $recoveryToken]);
                Assert::same(0, (int) $check->fetchColumn(), 'raw recovery token must never be stored');

                $hashed = $pdo->prepare('SELECT COUNT(*) FROM account_tokens WHERE token_hash = :hash');
                $hashed->execute(['hash' => hash('sha256', $verificationToken)]);
                Assert::same(1, (int) $hashed->fetchColumn());
            }),

            TestResult::check('Password hashes never appear in any API response body', function (): void {
                $email = null; $rawToken = null; $csrf = null;
                self::activeAccountWithLogin($email, $rawToken, $csrf);

                $login = self::controller()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => (string) $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));
                $session = self::controller()->session(AuthMiddleware::process(Request::create(
                    'GET', '/api/v1/auth/session',
                    headers: ['Cookie' => 'spms_session=' . $rawToken],
                    ipAddress: self::ip()
                )));

                foreach ([$login, $session] as $response) {
                    $encoded = json_encode($response->body()) ?: '';
                    Assert::notContains('password_hash', $encoded);
                    Assert::notContains('$2y$', $encoded);
                    Assert::notContains('$argon2', $encoded);
                }
            }),

            TestResult::check('Plaintext passwords never reach the logs (docs/08 §23)', function (): void {
                $logDir = sys_get_temp_dir() . '/spms-w1-logs-' . bin2hex(random_bytes(4));
                $logger = new Logger($logDir, 'debug');
                $secret = 'my-plain-text-password-77';

                $logger->info('auth.test', ['password' => $secret, 'email' => 'x@example.test']);

                $logFile = $logDir . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log';
                Assert::true(is_file($logFile), 'log file must exist');

                $content = (string) file_get_contents($logFile);
                Assert::notContains($secret, $content, 'sensitive keys must be redacted');
                Assert::contains('x@example.test', $content);
                Assert::contains('[REDACTED]', $content);

                unlink($logFile);
            }),

            TestResult::check('Session fixation defense: login regenerates the identifier (D-051)', function (): void {
                $email = null; $rawToken = null; $csrf = null;
                self::activeAccountWithLogin($email, $rawToken, $csrf);

                // Present the stolen/known cookie through the real
                // middleware pipeline so the prior session is resolved and
                // revoked by the regeneration path. The prior session is
                // authenticated, so its CSRF token is required (D-052).
                $secondLogin = self::controller()->login(AuthMiddleware::process(Request::create(
                    'POST', '/api/v1/auth/login',
                    headers: ['Cookie' => 'spms_session=' . $rawToken, 'X-SPMS-CSRF' => (string) $csrf],
                    body: ['email' => (string) $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                )));

                $newToken = AuthTesting::sessionTokenFrom($secondLogin);

                Assert::true(is_string($newToken));
                Assert::true($newToken !== $rawToken, 'identifier must be regenerated on login');

                $resolved = (new SessionService())->resolve((string) $rawToken, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                Assert::true($resolved === null, 'the pre-login identifier must be revoked');
            }),

            TestResult::check('Expired and revoked sessions cannot authenticate (D-051)', function (): void {
                $email = null; $rawToken = null; $csrf = null;
                self::activeAccountWithLogin($email, $rawToken, $csrf);

                $pdo = Database::connection();
                $hash = hash('sha256', (string) $rawToken);

                // Idle expiry.
                $pdo->prepare("UPDATE sessions SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE session_token_hash = ?")
                    ->execute([$hash]);

                $request = Request::create('GET', '/api/v1/auth/session', headers: ['Cookie' => 'spms_session=' . $rawToken], ipAddress: self::ip());
                $context = AuthMiddleware::process($request)->authContext();
                Assert::true($context !== null && !$context->isAuthenticated(), 'idle-expired session must not authenticate');

                // Lazily revoked by the middleware.
                $revokedAt = $pdo->prepare('SELECT revoked_at FROM sessions WHERE session_token_hash = ?');
                $revokedAt->execute([$hash]);
                Assert::true($revokedAt->fetchColumn() !== null, 'expired session must be lazily revoked');

                // Absolute expiry.
                $pdo->prepare("UPDATE sessions SET revoked_at = NULL, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), absolute_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE session_token_hash = ?")
                    ->execute([$hash]);

                $request = Request::create('GET', '/api/v1/auth/session', headers: ['Cookie' => 'spms_session=' . $rawToken], ipAddress: self::ip());
                $context = AuthMiddleware::process($request)->authContext();
                Assert::true($context !== null && !$context->isAuthenticated(), 'absolute-expired session must not authenticate');

                // Explicit revocation.
                $pdo->prepare('UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE session_token_hash = ?')->execute([$hash]);
                $request = Request::create('GET', '/api/v1/auth/session', headers: ['Cookie' => 'spms_session=' . $rawToken], ipAddress: self::ip());
                $context = AuthMiddleware::process($request)->authContext();
                Assert::true($context !== null && !$context->isAuthenticated(), 'revoked session must not authenticate');
            }),

            TestResult::check('Authentication bypass attempts fail closed', function (): void {
                $attempts = [
                    'spms_session=' . str_repeat('f', 64),
                    'spms_session=not-even-hex',
                    'spms_session=',
                ];

                foreach ($attempts as $cookie) {
                    $request = Request::create('GET', '/api/v1/auth/session', headers: ['Cookie' => $cookie], ipAddress: self::ip());
                    $context = AuthMiddleware::process($request)->authContext();
                    Assert::true($context !== null && !$context->isAuthenticated(), 'garbage cookie must stay anonymous: ' . $cookie);
                }

                // A disabled account with a live session is revoked, not honored.
                $email = null; $rawToken = null; $csrf = null;
                self::activeAccountWithLogin($email, $rawToken, $csrf);

                $account = (new AccountRepository())->findByEmail((string) $email);
                $disabled = $account?->withStatus(Account::STATUS_DISABLED, null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                if ($account !== null && $disabled !== null) {
                    (new AccountRepository())->updateStatus($disabled);
                }

                $request = Request::create('GET', '/api/v1/auth/session', headers: ['Cookie' => 'spms_session=' . $rawToken], ipAddress: self::ip());
                $context = AuthMiddleware::process($request)->authContext();
                Assert::true($context !== null && !$context->isAuthenticated(), 'disabled accounts must not authenticate');
            }),

            TestResult::check('Login throttling: bounded failures, cooldown, no permanent lockout (D-053)', function (): void {
                $email = AuthTesting::uniqueEmail();
                self::service()->register($email, 'correct-horse-battery', self::ip());
                self::service()->verifyEmail((string) AuthTesting::latestMailToken('Verify your email'));

                // Failures 1..5 are accepted as 401s; the 6th attempt is
                // throttled by the EMAIL bucket (each attempt uses a fresh
                // IP so only the email bucket accumulates here).
                for ($i = 0; $i < 5; $i++) {
                    try {
                        self::controller()->login(Request::create(
                            'POST', '/api/v1/auth/login',
                            body: ['email' => $email, 'password' => 'a-wrong-password-' . $i],
                            ipAddress: self::ip()
                        ));
                    } catch (AppException $e) {
                        Assert::same('INVALID_CREDENTIALS', $e->errorCode());
                    }
                }

                try {
                    self::controller()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $email, 'password' => 'a-wrong-password-6'],
                        ipAddress: self::ip()
                    ));
                    Assert::true(false, 'Expected rate-limit rejection');
                } catch (AppException $e) {
                    Assert::same('RATE_LIMITED', $e->errorCode());
                    Assert::same(429, $e->httpStatus());
                }

                // Even the CORRECT password is denied during the cooldown.
                try {
                    self::controller()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => $email, 'password' => 'correct-horse-battery'],
                        ipAddress: self::ip()
                    ));
                    Assert::true(false, 'Cooldown must deny correct credentials too');
                } catch (AppException $e) {
                    Assert::same('RATE_LIMITED', $e->errorCode());
                }

                // No permanent lockout: once the window slides, login works again.
                Database::connection()->prepare(
                    "UPDATE security_events SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE) WHERE event_type = 'LOGIN_FAILED' AND context_key = ?"
                )->execute([RateLimiter::emailBucket(strtolower($email))]);

                $recovered = self::controller()->login(Request::create(
                    'POST', '/api/v1/auth/login',
                    body: ['email' => $email, 'password' => 'correct-horse-battery'],
                    ipAddress: self::ip()
                ));
                Assert::same(200, $recovered->status(), 'throttling must be temporary, never a permanent lockout');
            }),

            TestResult::check('IP-oriented throttling is independent of email buckets (D-053)', function (): void {
                $ip = self::ip();

                for ($i = 0; $i < 5; $i++) {
                    try {
                        self::controller()->login(Request::create(
                            'POST', '/api/v1/auth/login',
                            body: ['email' => AuthTesting::uniqueEmail(), 'password' => 'a-wrong-password'],
                            ipAddress: $ip
                        ));
                    } catch (AppException $e) {
                        Assert::same('INVALID_CREDENTIALS', $e->errorCode());
                    }
                }

                try {
                    self::controller()->login(Request::create(
                        'POST', '/api/v1/auth/login',
                        body: ['email' => AuthTesting::uniqueEmail(), 'password' => 'a-wrong-password'],
                        ipAddress: $ip
                    ));
                    Assert::true(false, 'Expected IP-bucket throttling');
                } catch (AppException $e) {
                    Assert::same('RATE_LIMITED', $e->errorCode());
                }
            }),

            TestResult::check('Recovery request throttling protects the resend path (D-053)', function (): void {
                $ip = self::ip();

                for ($i = 0; $i < 5; $i++) {
                    self::controller()->recoveryRequest(Request::create(
                        'POST', '/api/v1/auth/recovery/request',
                        body: ['email' => AuthTesting::uniqueEmail()],
                        ipAddress: $ip
                    ));
                }

                try {
                    self::controller()->recoveryRequest(Request::create(
                        'POST', '/api/v1/auth/recovery/request',
                        body: ['email' => AuthTesting::uniqueEmail()],
                        ipAddress: $ip
                    ));
                    Assert::true(false, 'Expected recovery throttling');
                } catch (AppException $e) {
                    Assert::same('RATE_LIMITED', $e->errorCode());
                    Assert::same(429, $e->httpStatus());
                }
            }),
        ];
    }
}

/**
 * Small indirection so the cookie-flag test can exercise the exact
 * builder with the current config overrides.
 */
final class SessionCookieHeader
{
    public static function issue(): string
    {
        return \App\Support\Security\SessionCookie::issueHeaderValue(\App\Support\Security\TokenHasher::generate());
    }
}
