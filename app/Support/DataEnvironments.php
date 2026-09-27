<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Milestone D-071 — server-side REAL/DEMO/TEST data-environment resolution.
 *
 * SPMS runs against physically isolated databases (spms_real / spms_demo /
 * spms_test, identical migration set, no cross-database references). The
 * active environment is a TRUSTED SERVER-SIDE choice, never a request
 * input: no cookie, header, query, body or session value can select a
 * database. Resolution happens exactly once per process, inside
 * Config::initialize(), and is immutable afterwards (the same guarantee
 * that keeps a running server honest).
 *
 * Precedence for the profile name itself:
 *   1. process environment (SPMS_DATA_ENVIRONMENT — how a demo/real/test
 *      server is started without touching source),
 *   2. the .env value,
 *   3. 'real'.
 *
 * For the selected profile, the effective DB_* connection keys are read
 * from the profile group SPMS_DB_{PROFILE}_{KEY}. The 'real' profile may
 * fall back to the legacy single DB_* keys (pre-isolation development
 * configuration); demo and test FAIL CLOSED — a server or test run that
 * cannot prove its own database identity refuses to start rather than
 * silently sharing the real database.
 */
final class DataEnvironments
{
    public const REAL = 'real';
    public const DEMO = 'demo';
    public const TEST = 'test';

    private const PROFILES = [self::REAL, self::DEMO, self::TEST];

    private const KEYS = ['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD', 'CHARSET'];

    /** Database names SPMS tooling may address (identifier whitelist — migrate CLI). */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * Resolve + validate the process profile from trusted inputs only.
     *
     * @param array<string, string> $values parsed .env values (by reference-free read)
     */
    public static function resolveProfile(array $values): string
    {
        $fromProcess = getenv('SPMS_DATA_ENVIRONMENT');

        if (is_string($fromProcess) && trim($fromProcess) !== '') {
            $profile = strtolower(trim($fromProcess));
        } else {
            $profile = strtolower(trim((string) ($values['SPMS_DATA_ENVIRONMENT'] ?? '')));
        }

        if ($profile === '') {
            return self::REAL;
        }

        if (!in_array($profile, self::PROFILES, true)) {
            throw new \RuntimeException(
                'SPMS_DATA_ENVIRONMENT must be one of: ' . implode(', ', self::PROFILES) . '.'
            );
        }

        return $profile;
    }

    /**
     * Overlay the selected profile's DB_* connection values onto the
     * config bag and return the resolved bag (pure: the caller's array
     * is never mutated in place). Throws when a profile cannot prove its
     * own database identity.
     *
     * @param array<string, string> $values config values
     * @return array<string, string> resolved values, with DB_*,
     *                               SPMS_DATA_ENVIRONMENT and
     *                               SPMS_SESSION_COOKIE applied
     */
    public static function apply(array $values): array
    {
        $profile = self::resolveProfile($values);

        $prefix = 'SPMS_DB_' . strtoupper($profile) . '_';

        // HOST/PORT/CHARSET may fall back to the shared connection context;
        // DATABASE and USERNAME are the security-relevant identity of the
        // profile and DEMO/TEST must carry their own explicitly.
        foreach (self::KEYS as $key) {
            $value = $values[$prefix . $key] ?? null;

            if (is_string($value) && $value !== '') {
                $values['DB_' . $key] = $value;
            } elseif ($value === null && $profile !== self::REAL && in_array($key, ['DATABASE', 'USERNAME'], true)) {
                // Fail closed: DEMO and TEST must carry their own database
                // identity; they can never inherit REAL's.
                throw new \RuntimeException(
                    'The ' . $profile . ' data environment is not configured (' . $prefix . $key . ' missing).'
                );
            }
        }

        $database = $values['DB_DATABASE'] ?? '';

        if (preg_match(self::NAME_PATTERN, $database) !== 1) {
            throw new \RuntimeException('Resolved DB_DATABASE is not a valid database name.');
        }

        // Physical isolation must be visible in the name: profile/test DB
        // may never point at another profile's database.
        foreach (self::PROFILES as $other) {
            if ($other === $profile) {
                continue;
            }

            $otherName = $values['SPMS_DB_' . strtoupper($other) . '_DATABASE'] ?? '';

            if ($otherName !== '' && $otherName === $database) {
                throw new \RuntimeException(
                    'Data environments must not share one database (' . $profile . ' and ' . $other . ' both target ' . $otherName . ').'
                );
            }
        }

        $values['SPMS_DATA_ENVIRONMENT'] = $profile;
        $values['SPMS_SESSION_COOKIE'] = $profile === self::REAL
            ? 'spms_session'
            : 'spms_' . $profile . '_session';

        return $values;
    }

    /**
     * Validate an environment/profile name coming from a trusted CLI flag
     * (never from a request). Returns the canonical lowercase name.
     */
    public static function assertProfile(string $candidate): string
    {
        $profile = strtolower(trim($candidate));

        if (!in_array($profile, self::PROFILES, true)) {
            throw new \RuntimeException(
                'Unknown data environment: ' . $candidate . ' (allowed: ' . implode(', ', self::PROFILES) . ').'
            );
        }

        return $profile;
    }

    /** Connection settings for a profile, straight from the config bag. */
    public static function connectionConfig(string $profile): array
    {
        $prefix = 'SPMS_DB_' . strtoupper(self::assertProfile($profile)) . '_';
        $values = [];

        foreach (self::KEYS as $key) {
            $values[$key] = Config::get($prefix . $key, Config::get('DB_' . $key, '')) ?? '';
        }

        // Allow test overrides on DB_PORT, DB_USERNAME, DB_PASSWORD to take effect
        if ($profile === self::TEST || Config::get('SPMS_DATA_ENVIRONMENT') === $profile) {
            if (Config::get('DB_PORT') !== null && Config::get('DB_PORT') !== '') {
                $values['PORT'] = Config::get('DB_PORT');
            }
            if (Config::get('DB_USERNAME') !== null && Config::get('DB_USERNAME') !== '') {
                $values['USERNAME'] = Config::get('DB_USERNAME');
            }
            if (Config::get('DB_PASSWORD') !== null && Config::get('DB_PASSWORD') !== '') {
                $values['PASSWORD'] = Config::get('DB_PASSWORD');
            }
        }

        if ($values['DATABASE'] === '' || $values['USERNAME'] === ''
            || preg_match(self::NAME_PATTERN, $values['DATABASE']) !== 1) {
            throw new \RuntimeException('The ' . $profile . ' data environment is not configured for this operation.');
        }

        return $values;
    }
}
