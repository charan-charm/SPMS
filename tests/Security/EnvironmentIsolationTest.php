<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Domain\Auth\SessionService;
use App\Repositories\AccountRepository;
use App\Repositories\AccountTokenRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\Mail\Mailer;
use App\Support\Config;
use App\Support\Database;
use App\Support\DataEnvironments;
use App\Domain\Auth\RateLimiter;
use App\Support\Security\SessionCookie;
use App\Support\Security\TokenHasher;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * EnvironmentIsolationTest — Proves strict physical database and session
 * isolation between REAL (spms_real), DEMO (spms_demo), and TEST (spms_test).
 * (docs/10 D-071, SPMS O2).
 */
final class EnvironmentIsolationTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Config-driven environment resolution: process env overrides .env, defaults to real', function (): void {
                // Priority 1: process env overrides everything
                putenv('SPMS_DATA_ENVIRONMENT=demo');
                $profileFromProcess = DataEnvironments::resolveProfile(['SPMS_DATA_ENVIRONMENT' => 'real']);
                Assert::same('demo', $profileFromProcess, 'Process env SPMS_DATA_ENVIRONMENT=demo must win over .env value');

                // Priority 2: .env value used when process env is absent
                putenv('SPMS_DATA_ENVIRONMENT=');
                $profileFromDotEnv = DataEnvironments::resolveProfile(['SPMS_DATA_ENVIRONMENT' => 'demo']);
                Assert::same('demo', $profileFromDotEnv, '.env SPMS_DATA_ENVIRONMENT=demo must be used when process env is absent');

                // Priority 3: default is real when neither is set
                putenv('SPMS_DATA_ENVIRONMENT=');
                $profileDefault = DataEnvironments::resolveProfile([]);
                Assert::same('real', $profileDefault, 'Default profile must be real when no config is present');

                // Port numbers must NOT influence profile selection (single-port architecture)
                putenv('SPMS_DATA_ENVIRONMENT=');
                $_SERVER['SERVER_PORT'] = '8081';
                $profileWithOldDemoPort = DataEnvironments::resolveProfile(['SPMS_DATA_ENVIRONMENT' => 'real']);
                unset($_SERVER['SERVER_PORT']);
                Assert::same('real', $profileWithOldDemoPort, 'Port 8081 must NOT trigger demo mode (port detection removed)');

                // Restore test process env
                putenv('SPMS_DATA_ENVIRONMENT=test');
            }),

            TestResult::check('DEMO registration persists exclusively to spms_demo with DEMO_TEST mode', function (): void {
                $testEmail = 'iso_demo_' . bin2hex(random_bytes(4)) . '@test.spms.local';
                $password = 'Password123!Safe';

                // Establish direct PDO connections to verify physical database rows
                $pdoDemo = new PDO('mysql:host=127.0.0.1;dbname=spms_demo', 'root', '');
                $pdoReal = new PDO('mysql:host=127.0.0.1;dbname=spms_real', 'root', '');

                // Save current config state
                $originalConfig = [
                    'DB_DATABASE' => Config::get('DB_DATABASE'),
                    'SPMS_DATA_ENVIRONMENT' => Config::get('SPMS_DATA_ENVIRONMENT'),
                    'SPMS_SESSION_COOKIE' => Config::get('SPMS_SESSION_COOKIE'),
                ];

                $testIp = '10.88.99.' . random_int(1, 250);

                try {
                    // Switch to DEMO environment connection
                    Database::switchTo('demo');

                    // Perform registration via real service pipeline
                    $service = new AccountService();

                    $result = $service->register($testEmail, $password, $testIp);
                    Assert::same(true, is_array($result));
                    Assert::same('DEMO_TEST', $result['account']->mode(), 'DEMO registration must assign DEMO_TEST mode');

                    // 1. Verify row exists in spms_demo
                    $stmtDemo = $pdoDemo->prepare('SELECT id, email, mode FROM accounts WHERE email = :email');
                    $stmtDemo->execute(['email' => $testEmail]);
                    $demoRow = $stmtDemo->fetch(PDO::FETCH_ASSOC);
                    Assert::same(true, is_array($demoRow), 'Account must exist in spms_demo');
                    Assert::same('DEMO_TEST', $demoRow['mode']);

                    // 2. Verify row does NOT exist in spms_real
                    $stmtReal = $pdoReal->prepare('SELECT COUNT(*) FROM accounts WHERE email = :email');
                    $stmtReal->execute(['email' => $testEmail]);
                    $realCount = (int) $stmtReal->fetchColumn();
                    Assert::same(0, $realCount, 'DEMO account must NEVER exist in spms_real');

                    // 3. Verify session cookie name is spms_demo_session
                    Assert::same('spms_demo_session', SessionCookie::name(), 'Cookie name must be spms_demo_session');
                } finally {
                    // Clean up test account from spms_demo
                    $pdoDemo->prepare('DELETE FROM accounts WHERE email = :email')->execute(['email' => $testEmail]);
                    Database::switchTo('test');
                }
            }),

            TestResult::check('Cross-environment authentication fails closed (401)', function (): void {
                $demoEmail = 'iso_cross_' . bin2hex(random_bytes(4)) . '@test.spms.local';
                $password = 'Password123!Safe';
                $testIp = '10.88.99.' . random_int(1, 250);

                $pdoDemo = new PDO('mysql:host=127.0.0.1;dbname=spms_demo', 'root', '');
                $pdoReal = new PDO('mysql:host=127.0.0.1;dbname=spms_real', 'root', '');

                try {
                    // 1. Register in DEMO
                    Database::switchTo('demo');

                    $demoService = new AccountService();
                    $demoReg = $demoService->register($demoEmail, $password, $testIp);
                    $demoAccount = $demoReg['account'];

                    // Activate account in DEMO so it is authenticatable
                    $pdoDemo->prepare("UPDATE accounts SET status = 'ACTIVE', email_verified_at = NOW() WHERE id = :id")
                        ->execute(['id' => $demoAccount->id()]);

                    // Login in DEMO succeeds
                    $loginResult = $demoService->login($demoEmail, $password, $testIp, null);
                    Assert::same(true, is_array($loginResult), 'Login in DEMO must succeed');
                    $demoToken = $loginResult['raw_token'];

                    // 2. Switch to REAL environment
                    Database::switchTo('real');

                    $realService = new AccountService();

                    // Login with DEMO credentials in REAL must fail (INVALID_CREDENTIALS)
                    $failedLogin = false;
                    try {
                        $realService->login($demoEmail, $password, $testIp, null);
                    } catch (\Exception $e) {
                        $failedLogin = true;
                    }
                    Assert::same(true, $failedLogin, 'Login with DEMO account in REAL must fail');

                    // Authenticating DEMO session token against REAL session repository must return null
                    $sessionService = new SessionService();
                    $resolved = $sessionService->resolve($demoToken, new DateTimeImmutable('now', new DateTimeZone('UTC')));
                    Assert::same(null, $resolved, 'DEMO session token must NOT authenticate against REAL database');
                } finally {
                    // Clean up
                    $pdoDemo->prepare('DELETE FROM accounts WHERE email = :email')->execute(['email' => $demoEmail]);
                    Database::switchTo('test');
                }
            }),

            TestResult::check('Session environment binding is authoritative: forged client headers/cookies cannot switch DB context', function (): void {
                $realEmail = 'real_bind_' . bin2hex(random_bytes(4)) . '@test.spms.local';
                $password = 'Password123!Safe';
                $testIp = '10.88.99.' . random_int(1, 250);

                $pdoReal = new PDO('mysql:host=127.0.0.1;dbname=spms_real', 'root', '');

                try {
                    // Register and activate in REAL
                    Database::switchTo('real');
                    $service = new AccountService();
                    $reg = $service->register($realEmail, $password, $testIp);
                    $account = $reg['account'];
                    $pdoReal->prepare("UPDATE accounts SET status = 'ACTIVE', email_verified_at = NOW() WHERE id = :id")
                        ->execute(['id' => $account->id()]);

                    $login = $service->login($realEmail, $password, $testIp, null);
                    $token = $login['raw_token'];

                    // Craft request carrying REAL session cookie AND forged DEMO header
                    $req = \App\Support\Request::create(
                        method: 'GET',
                        path: '/api/v1/household',
                        headers: [
                            'cookie' => 'spms_session=' . $token,
                            'x-spms-environment' => 'DEMO',
                        ]
                    );

                    $processed = \App\Security\AuthMiddleware::process($req);
                    Assert::same(true, $processed->authContext()?->isAuthenticated(), 'Session must authenticate successfully');
                    Assert::same('real', Database::currentProfile(), 'Authenticated request MUST stay bound to real database despite DEMO header');

                    // Check with cookie hint spms_env=demo
                    $reqCookie = \App\Support\Request::create(
                        method: 'GET',
                        path: '/api/v1/household',
                        headers: [
                            'cookie' => 'spms_session=' . $token . '; spms_env=demo',
                        ]
                    );
                    $processedCookie = \App\Security\AuthMiddleware::process($reqCookie);
                    Assert::same('real', Database::currentProfile(), 'Authenticated request MUST stay bound to real database despite spms_env cookie');

                } finally {
                    $pdoReal->prepare('DELETE FROM accounts WHERE email = :email')->execute(['email' => $realEmail]);
                    Database::switchTo('test');
                }
            }),

            TestResult::check('TEST environment cannot be selected via HTTP request hint', function (): void {
                $req = \App\Support\Request::create(
                    method: 'POST',
                    path: '/api/v1/auth/register',
                    body: ['email' => 'test@test.local', 'password' => 'Pass123!45678', 'environment' => 'TEST']
                );

                $rejected = false;
                try {
                    \App\Security\AuthMiddleware::process($req);
                } catch (\App\Support\AppException $e) {
                    Assert::same(400, $e->httpStatus());
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    $rejected = true;
                }
                Assert::same(true, $rejected, 'TEST environment request hint must be rejected with 400');
            }),

            TestResult::check('Verification isolation: DEMO token in spms_demo and REAL token in spms_real cannot cross-verify', function (): void {
                $demoEmail = 'iso_demo_v_' . bin2hex(random_bytes(4)) . '@test.spms.local';
                $realEmail = 'iso_real_v_' . bin2hex(random_bytes(4)) . '@test.spms.local';
                $password = 'Password123!Safe';
                $testIp = '10.88.99.' . random_int(1, 250);

                $pdoDemo = new PDO('mysql:host=127.0.0.1;dbname=spms_demo', 'root', '');
                $pdoReal = new PDO('mysql:host=127.0.0.1;dbname=spms_real', 'root', '');

                try {
                    // 1. Register in DEMO
                    Database::switchTo('demo');
                    $demoService = new AccountService();
                    $demoReg = $demoService->register($demoEmail, $password, $testIp);
                    $demoAccount = $demoReg['account'];

                    // Overwrite demo code with '111222'
                    $demoCode = '111222';
                    $pdoDemo->prepare("UPDATE account_tokens SET token_hash = :hash WHERE account_id = :id")
                        ->execute(['hash' => TokenHasher::hash($demoCode), 'id' => $demoAccount->id()]);

                    // 2. Register in REAL
                    Database::switchTo('real');
                    $realService = new AccountService();
                    $realReg = $realService->register($realEmail, $password, $testIp);
                    $realAccount = $realReg['account'];

                    // Overwrite real code with '333444'
                    $realCode = '333444';
                    $pdoReal->prepare("UPDATE account_tokens SET token_hash = :hash WHERE account_id = :id")
                        ->execute(['hash' => TokenHasher::hash($realCode), 'id' => $realAccount->id()]);

                    // 3. Verify DEMO code against REAL environment MUST fail (422)
                    Database::switchTo('real');
                    $crossDemoInRealFailed = false;
                    try {
                        $realService->verifyEmail($demoCode, $demoEmail);
                    } catch (\App\Support\AppException $e) {
                        $crossDemoInRealFailed = true;
                        Assert::same(422, $e->httpStatus());
                    }
                    Assert::true($crossDemoInRealFailed, 'DEMO code must NOT verify in REAL database');

                    // 4. Verify REAL code against DEMO environment MUST fail (422)
                    Database::switchTo('demo');
                    $crossRealInDemoFailed = false;
                    try {
                        $demoService->verifyEmail($realCode, $realEmail);
                    } catch (\App\Support\AppException $e) {
                        $crossRealInDemoFailed = true;
                        Assert::same(422, $e->httpStatus());
                    }
                    Assert::true($crossRealInDemoFailed, 'REAL code must NOT verify in DEMO database');

                    // 5. Verify DEMO code in DEMO environment succeeds
                    $demoVerified = $demoService->verifyEmail($demoCode, $demoEmail);
                    Assert::same('ACTIVE', $demoVerified->status(), 'DEMO code verifies successfully in DEMO database');

                    // 6. Verify REAL code in REAL environment succeeds
                    Database::switchTo('real');
                    $realVerified = $realService->verifyEmail($realCode, $realEmail);
                    Assert::same('ACTIVE', $realVerified->status(), 'REAL code verifies successfully in REAL database');

                } finally {
                    $pdoDemo->prepare('DELETE FROM accounts WHERE email = :email')->execute(['email' => $demoEmail]);
                    $pdoReal->prepare('DELETE FROM accounts WHERE email = :email')->execute(['email' => $realEmail]);
                    Database::switchTo('test');
                }
            }),
        ];
    }
}
