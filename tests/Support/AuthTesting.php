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

        if (preg_match('/spms_session=([0-9a-f]{64})/', $cookie, $matches) === 1) {
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
}
