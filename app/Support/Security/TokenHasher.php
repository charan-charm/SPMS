<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * W1 — Opaque token generation and hashing (docs/10 D-051, D-053).
 *
 * Raw tokens are generated with CSPRNG entropy (256-bit) and exist only
 * at the moment of creation/delivery: the database stores exclusively
 * their SHA-256 hash. Session identifiers, CSRF tokens and verification/
 * recovery tokens all flow through this one path.
 */
final class TokenHasher
{
    public const TOKEN_BYTES = 32;
    public const HASH_ALGO = 'sha256';

    /** 64 hex characters of CSPRNG entropy (256-bit). */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /** SHA-256 hex hash — the only token representation ever persisted. */
    public static function hash(string $token): string
    {
        return hash(self::HASH_ALGO, $token);
    }
}
