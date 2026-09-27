<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * W1 — Opaque token generation and hashing (docs/10 D-051, D-053).
 *
 * Raw tokens are generated with CSPRNG entropy and exist only at the
 * moment of creation/delivery: the database stores exclusively their
 * SHA-256 hash. Session identifiers, CSRF tokens and recovery tokens
 * all flow through the standard generate()/hash() path.
 *
 * Email verification specifically uses a 6-digit numeric code
 * (generateSixDigit()) whose SHA-256 hash is stored identically to
 * the standard path. The code itself is NEVER logged, stored in plain
 * text, emitted in error responses, or placed in any URL.
 */
final class TokenHasher
{
    public const TOKEN_BYTES = 32;
    public const HASH_ALGO = 'sha256';

    /** 64 hex characters of CSPRNG entropy (256-bit) — for session/recovery tokens. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Cryptographically secure 6-digit numeric verification code.
     *
     * Uses random_int() (CSPRNG) for uniform distribution across [0, 999999].
     * Zero-padded to exactly 6 decimal digits so the user always sees a
     * consistent 6-character string. The code is never stored — only its
     * SHA-256 hash (via hash()) reaches the database.
     */
    public static function generateSixDigit(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** SHA-256 hex hash — the only token/code representation ever persisted. */
    public static function hash(string $token): string
    {
        return hash(self::HASH_ALGO, $token);
    }
}
