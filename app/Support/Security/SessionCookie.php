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
     * @return string|null raw opaque token, or null when absent
     */
    public static function extractToken(?string $cookieHeader): ?string
    {
        if ($cookieHeader === null || $cookieHeader === '') {
            return null;
        }

        foreach (explode(';', $cookieHeader) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && $parts[0] === self::NAME) {
                $token = trim($parts[1]);
                return $token === '' ? null : $token;
            }
        }

        return null;
    }

    private static function build(string $rawToken, int $maxAge): string
    {
        $environment = Config::get('APP_ENV', 'production') ?? 'production';
        $secure = $environment !== 'development' ? '; Secure' : '';

        $parts = [
            self::NAME . '=' . $rawToken,
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
