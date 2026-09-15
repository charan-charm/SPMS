<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Support\Config;
use App\Support\Response;

/**
 * W1 — Shared authentication test helpers.
 *
 * Every call receives a UNIQUE random email and a UNIQUE random source
 * IP so DB-backed rate-limit buckets (docs/10 D-053.6) never leak state
 * between tests or runs. Tests that specifically exercise IP-oriented
 * throttling pin one IP locally for the duration of the test. The mail
 * sink is redirected to a per-run temporary directory so delivered
 * verification/recovery mail can be read by the workflow tests without
 * touching repository files.
 */
final class AuthTesting
{
    private static bool $configured = false;

    public static function uniqueEmail(): string
    {
        return 'w1-' . bin2hex(random_bytes(6)) . '@example.test';
    }

    /**
     * Fresh unique source IP per call — keeps rate-limit IP buckets
     * isolated between tests.
     */
    public static function uniqueIpAddress(): string
    {
        return sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));
    }

    public static function configure(): void
    {
        if (self::$configured) {
            return;
        }

        self::$configured = true;

        Config::overrideForTesting([
            'APP_ENV' => 'development',
            'MAIL_TRANSPORT' => 'file',
            'MAIL_FILE_DIR' => sys_get_temp_dir() . '/spms-w1-mail-' . bin2hex(random_bytes(4)),
        ]);

        // D-071 fail-closed choke point: any DB-touching fixture path must
        // prove it is executing inside the dedicated test database before
        // the first write. If this ever throws, the runner's pin was lost
        // and the suite MUST stop rather than touch REAL or DEMO.
        if (Config::get('SPMS_DATA_ENVIRONMENT') !== 'test') {
            throw new \RuntimeException('Test fixtures require SPMS_DATA_ENVIRONMENT=test.');
        }

        $current = (string) \App\Support\Database::connection()->query('SELECT DATABASE()')->fetchColumn();

        if ($current !== (string) Config::get('SPMS_DB_TEST_DATABASE')) {
            throw new \RuntimeException(
                'Test process is connected to `' . $current . '` instead of the isolated test database.'
            );
        }
    }

    /**
     * @return array{account: Account, verification_sent: bool}
     */
    public static function registerActiveFlow(?string $email = null): array
    {
        self::configure();

        return (new AccountService())->register(
            $email ?? self::uniqueEmail(),
            'correct-horse-battery',
            self::uniqueIpAddress()
        );
    }

    /**
     * Extract the raw token from the newest delivered mail matching the
     * subject. The raw token exists only inside the mail sink file —
     * never in the database. Filenames carry microsecond timestamps, so
     * sorting by name is chronological.
     */
    public static function latestMailToken(string $subjectPart): ?string
    {
        $config = Config::get('MAIL_FILE_DIR') ?? '';
        if ($config === '' || !is_dir($config)) {
            return null;
        }

        $files = glob($config . '/*.eml') ?: [];
        if ($files === []) {
            return null;
        }

        sort($files);

        foreach (array_reverse($files) as $file) {
            $content = (string) file_get_contents($file);
            if (!str_contains($content, $subjectPart)) {
                continue;
            }

            if (preg_match('/\b([0-9a-f]{64})\b/', $content, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Raw session token from a Set-Cookie response header.
     */
    public static function sessionTokenFrom(Response $response): ?string
    {
        $cookie = $response->headers()['Set-Cookie'] ?? null;
        if ($cookie === null) {
            return null;
        }

        if (preg_match('/' . preg_quote(\App\Support\Security\SessionCookie::name(), '/') . '=([0-9a-f]{64})/', $cookie, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Raw CSRF token from an auth session/login response body.
     *
     * @param array<string, mixed> $data
     */
    public static function csrfFrom(array $data): ?string
    {
        $token = $data['session']['csrf_token'] ?? null;

        return is_string($token) ? $token : null;
    }

    // ---------------------------------------------------------------
    // W3 — household ownership fixtures
    // ---------------------------------------------------------------

    /**
     * Register + verify a fresh ACTIVE account through the real auth
     * service and mail sink. Returns the activated Account entity.
     */
    public static function activateAccount(string $prefix = 'w1-'): Account
    {
        self::configure();

        $service = new AccountService();
        $email = $prefix . bin2hex(random_bytes(6)) . '@example.test';

        $service->register($email, 'correct-horse-battery', self::uniqueIpAddress());

        $token = self::latestMailToken('Verify your email');
        if ($token === null) {
            throw new \RuntimeException('W3 fixture: no verification mail token found in sink.');
        }

        return $service->verifyEmail($token);
    }

    /**
     * A synthetic authenticated context for controller-boundary tests.
     * Middleware normally builds this; direct-controller tests inject
     * the equivalent object without weakening the boundary under test
     * (authorization decisions live in the service layer, not here).
     */
    public static function contextFor(Account $account): \App\Security\AuthContext
    {
        return \App\Security\AuthContext::authenticated($account, [
            'id' => 'w3-fixture-session',
            'account_id' => $account->id(),
            'csrf_token' => 'w3-fixture-csrf',
        ]);
    }

    public static function authed(\App\Support\Request $request, Account $account): \App\Support\Request
    {
        return $request->withAuthContext(self::contextFor($account));
    }

    /**
     * Full W3 fixture: ACTIVE account owning one fresh household
     * (real rows, FK-valid — the synthetic 'f6-hh-*' IDs can no longer
     * be referenced since migration 0007).
     *
     * D-071: pass a service number to get a household with REGISTERED
     * ownership (stored canonical, exactly like the API path); omitting
     * it models the honest not-configured state.
     *
     * @return array{account: Account, household_id: string, context: \App\Security\AuthContext}
     */
    public static function householdFixture(?string $label = null, ?string $serviceNumber = null): array
    {
        $account = self::activateAccount();

        $householdService = new \App\Domain\Household\HouseholdService();

        $householdId = $householdService->createForAccount(
            $account,
            $label ?? 'w3-test-' . bin2hex(random_bytes(4)),
            'Asia/Kolkata',
            null,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        )['household']->id();

        if ($serviceNumber !== null) {
            $householdService->updateProfile(
                $account,
                ['service_number' => $serviceNumber],
                new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            );
        }

        return [
            'account' => $account,
            'household_id' => $householdId,
            'context' => self::contextFor($account),
        ];
    }

    /**
     * A real, FK-valid household row (no account/membership) for tests
     * that persist insights at the repository/service layer and only need
     * a household the foreign key can resolve to. Replaces the pre-W3
     * synthetic 'f6-hh-*' identifiers that migration 0007 now rejects.
     */
    public static function bareHouseholdId(string $labelPrefix = 'w3-fixture'): string
    {
        self::configure();

        $household = \App\Domain\Household\Household::create(
            $labelPrefix . '-' . bin2hex(random_bytes(4)),
            'Asia/Kolkata',
            null,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );

        (new \App\Repositories\HouseholdRepository())->insert($household);

        return $household->id();
    }
}
