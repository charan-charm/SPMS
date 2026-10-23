<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\App;
use Tests\Support\Assert;
use Tests\TestResult;

final class CorsPolicyTest
{
    public static function run(): array
    {
        return [
            // 1. Mixed-case configured hostname versus lowercase browser origin
            TestResult::check('Mixed-case configured hostname matches lowercase browser origin', function (): void {
                Assert::true(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://SPMS.Energy.Internal'),
                    'Configured mixed-case host must match browser lowercase host'
                );
            }),

            // 2. Mixed-case scheme and hostname
            TestResult::check('Mixed-case scheme and hostname are normalized consistently', function (): void {
                Assert::true(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'HTTPS://SPMS.ENERGY.INTERNAL'),
                    'Uppercase scheme and host in config must match canonical browser origin'
                );
                Assert::true(
                    App::isOriginAllowed('HTTPS://SPMS.ENERGY.INTERNAL', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Uppercase scheme and host in candidate must match canonical configured origin'
                );
            }),

            // 3. Equivalent HTTPS origins with and without explicit port 443
            TestResult::check('Equivalent HTTPS origins with and without explicit port 443 match', function (): void {
                // Configured with explicit :443 matches browser without port
                Assert::true(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal:443'),
                    'Configured origin with default HTTPS port 443 must match browser origin without port'
                );
                // Browser with explicit :443 matches configured without port
                Assert::true(
                    App::isOriginAllowed('https://spms.energy.internal:443', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Browser origin with default HTTPS port 443 must match configured origin without port'
                );
            }),

            // 4. Equivalent HTTP origins with and without explicit port 80
            TestResult::check('Equivalent HTTP origins with and without explicit port 80 match', function (): void {
                // Configured with explicit :80 matches browser without port
                Assert::true(
                    App::isOriginAllowed('http://spms.energy.internal', appEnv: 'production', configuredOrigin: 'http://spms.energy.internal:80'),
                    'Configured origin with default HTTP port 80 must match browser origin without port'
                );
                // Browser with explicit :80 matches configured without port
                Assert::true(
                    App::isOriginAllowed('http://spms.energy.internal:80', appEnv: 'production', configuredOrigin: 'http://spms.energy.internal'),
                    'Browser origin with default HTTP port 80 must match configured origin without port'
                );
            }),

            // 5. Different non-default ports are rejected
            TestResult::check('Different non-default ports are rejected and valid non-default ports are preserved', function (): void {
                // Exact non-default port match succeeds
                Assert::true(
                    App::isOriginAllowed('https://spms.energy.internal:8443', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal:8443'),
                    'Exact non-default port must be permitted'
                );
                // Mismatched non-default ports fail
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal:8443', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal:9443'),
                    'Different non-default port must be rejected'
                );
                // Non-default port vs default/no-port fails
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal:8443', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Non-default port must not match origin without port'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal:8443'),
                    'Origin without port must not match configured non-default port'
                );
            }),

            // 6. HTTP and HTTPS origins are not interchangeable
            TestResult::check('HTTP and HTTPS origins are not interchangeable', function (): void {
                Assert::false(
                    App::isOriginAllowed('http://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'HTTP candidate must not match HTTPS configured origin'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'http://spms.energy.internal'),
                    'HTTPS candidate must not match HTTP configured origin'
                );
            }),

            // 7. Subdomain mismatches are rejected
            TestResult::check('Subdomain mismatches are rejected', function (): void {
                Assert::false(
                    App::isOriginAllowed('https://sub.spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Subdomain origin must be rejected when parent domain is configured'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://sub.spms.energy.internal'),
                    'Parent domain must be rejected when subdomain is configured'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal.attacker.com', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Attacker domain suffix must be rejected'
                );
            }),

            // 8. Paths, credentials/user information, query strings, and fragments are rejected as origins
            TestResult::check('Paths, credentials, query strings, and fragments are rejected as origins', function (): void {
                // Non-root paths in configuration or candidate
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal/api/v1'),
                    'Configured origin with non-root path must be rejected'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal/dashboard', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Candidate origin with non-root path must be rejected'
                );

                // User information (credentials)
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://user:pass@spms.energy.internal'),
                    'Configured origin with user info must be rejected'
                );
                Assert::false(
                    App::isOriginAllowed('https://user:pass@spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Candidate origin with user info must be rejected'
                );

                // Query strings
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal?env=prod'),
                    'Configured origin with query string must be rejected'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal?env=prod', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Candidate origin with query string must be rejected'
                );

                // Fragments / anchors
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal#anchor'),
                    'Configured origin with fragment must be rejected'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal#anchor', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Candidate origin with fragment must be rejected'
                );

                // Trailing slash handling (allowed without altering identity)
                Assert::true(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal/'),
                    'Configured origin with trailing slash must match browser origin without trailing slash'
                );
                Assert::true(
                    App::isOriginAllowed('https://spms.energy.internal/', appEnv: 'production', configuredOrigin: 'https://spms.energy.internal'),
                    'Candidate origin with trailing slash must match configured origin without trailing slash'
                );
            }),

            // 9. Wildcards remain rejected
            TestResult::check('Wildcard origins in configuration or candidate are strictly disallowed', function (): void {
                Assert::false(
                    App::isOriginAllowed('https://any-domain.com', appEnv: 'production', configuredOrigin: '*'),
                    'Wildcard * in CORS_ALLOWED_ORIGIN must be rejected'
                );
                Assert::false(
                    App::isOriginAllowed('https://sub.domain.com', appEnv: 'production', configuredOrigin: 'https://*.domain.com'),
                    'Partial wildcard in CORS_ALLOWED_ORIGIN must be rejected'
                );
                Assert::false(
                    App::isOriginAllowed('https://*.domain.com', appEnv: 'production', configuredOrigin: 'https://sub.domain.com'),
                    'Wildcard candidate origin must be rejected'
                );
            }),

            // 10. Development loopback behavior remains unchanged
            TestResult::check('Loopback origins (localhost and 127.0.0.1) are permitted in development', function (): void {
                Assert::true(
                    App::isOriginAllowed('http://localhost:5173', appEnv: 'development', configuredOrigin: null),
                    'localhost with port must be permitted in development'
                );
                Assert::true(
                    App::isOriginAllowed('http://127.0.0.1:8080', appEnv: 'development', configuredOrigin: null),
                    '127.0.0.1 with port must be permitted in development'
                );
                Assert::true(
                    App::isOriginAllowed('https://localhost', appEnv: 'development', configuredOrigin: null),
                    'localhost https must be permitted in development'
                );
            }),

            // 11. Production loopback behavior remains unchanged
            TestResult::check('Loopback origins are denied in production unless explicitly configured', function (): void {
                Assert::false(
                    App::isOriginAllowed('http://localhost:5173', appEnv: 'production', configuredOrigin: null),
                    'localhost must be denied in production when unconfigured'
                );
                Assert::false(
                    App::isOriginAllowed('http://127.0.0.1:8080', appEnv: 'production', configuredOrigin: 'https://spms.example.com'),
                    '127.0.0.1 must be denied in production when external origin is configured'
                );
            }),

            // 12. Missing configuration and missing Origin headers remain fail-closed
            TestResult::check('Missing configuration and missing Origin headers remain fail-closed', function (): void {
                Assert::false(
                    App::isOriginAllowed('', appEnv: 'development', configuredOrigin: null),
                    'Empty origin must return false in development'
                );
                Assert::false(
                    App::isOriginAllowed('', appEnv: 'production', configuredOrigin: 'https://spms.example.com'),
                    'Empty origin must return false in production'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: null),
                    'Unconfigured origin must be rejected in production'
                );
                Assert::false(
                    App::isOriginAllowed('https://spms.energy.internal', appEnv: 'production', configuredOrigin: ''),
                    'Empty string configured origin must be rejected in production'
                );
                Assert::false(
                    App::isOriginAllowed('https://attacker.com', appEnv: 'development', configuredOrigin: null),
                    'Arbitrary external origin must be rejected in development'
                );
                Assert::false(
                    App::isOriginAllowed('https://attacker.com', appEnv: 'production', configuredOrigin: 'https://spms.example.com'),
                    'Arbitrary external origin must be rejected in production'
                );
            }),
        ];
    }
}
