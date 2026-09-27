<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Support\Config;

/**
 * W1 — Session cookie policy (docs/10 D-051).
 *
 * The cookie carries ONLY the opaque session identifier — no account
 * data, no permissions, no application state. Flags follow the ratified
 * policy: HttpOnly, SameSite=Lax, Path=/, Secure in production; Secure
 * is omitted only for local development over plain HTTP (APP_ENV
 * development), never in production.
 */
final class SessionCookie
{
    public const NAME = 'spms_session';
    public const LIFETIME_SECONDS = 7 * 24 * 3600; // mirrors the absolute session lifetime

    /**
     * D-071 — the ACTIVE session cookie name follows the server-selected
     * data environment (spms_session / spms_demo_session /
     * spms_test_session). Cookies ignore ports, so without distinct names
     * a real and a demo server on one host would clobber each other's
     * login; the names are not a security boundary (sessions live
     * entirely inside their own database) — they are session-hygiene
     * isolation.
     */
    public static function name(): string
    {
        $profile = \App\Support\Database::currentProfile();
        return $profile === \App\Support\DataEnvironments::REAL
            ? self::NAME
            : 'spms_' . $profile . '_session';
    }

    /** Set-Cookie header value establishing the session. */
    public static function issueHeaderValue(string $rawToken): string
    {
        return self::build($rawToken, self::LIFETIME_SECONDS);
    }

    /** Set-Cookie header value expiring the session cookie. */
    public static function clearHeaderValue(): string
    {
        return self::build('', 0);
    }

    /**
     * Extract the raw session token from a Cookie request header.
     *
     * @param string|null $cookieHeader
     * @param string|null $matchedName Output parameter capturing which cookie name was matched
     * @return string|null raw opaque token, or null when absent
     */
    public static function extractToken(?string $cookieHeader, ?string &$matchedName = null): ?string
    {
        if ($cookieHeader === null || $cookieHeader === '') {
            return null;
        }

        $possibleNames = ['spms_session', 'spms_demo_session', 'spms_test_session'];

        foreach (explode(';', $cookieHeader) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2) {
                $cookieName = trim($parts[0]);
                if (in_array($cookieName, $possibleNames, true)) {
                    $token = trim($parts[1]);
                    if ($token !== '') {
                        $matchedName = $cookieName;
                        return $token;
                    }
                }
            }
        }

        return null;
    }

    private static function build(string $rawToken, int $maxAge): string
    {
        $environment = Config::get('APP_ENV', 'production') ?? 'production';
        $secure = $environment !== 'development' ? '; Secure' : '';

        $parts = [
            self::name() . '=' . $rawToken,
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($maxAge === 0) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        } else {
            $parts[] = 'Max-Age=' . $maxAge;
        }

        return implode('; ', $parts) . $secure;
    }
}
