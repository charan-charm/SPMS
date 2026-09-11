<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Support\AppException;
use RuntimeException;

/**
 * W1 — Server-enforced password security (docs/10 D-053.4–5, docs/08 §5).
 *
 * Hashing prefers Argon2id where the PHP runtime supports it and falls
 * back to the strongest algorithm the runtime provides (bcrypt). The
 * policy is centralized here: React never duplicates it as the
 * authoritative implementation.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 200;

    public static function validate(mixed $rawPassword): string
    {
        if (!is_string($rawPassword)) {
            throw self::invalid('password must be a string.');
        }

        $length = mb_strlen($rawPassword);
        if ($length < self::MIN_LENGTH) {
            throw self::invalid(sprintf('password must be at least %d characters.', self::MIN_LENGTH));
        }

        if ($length > self::MAX_LENGTH) {
            throw self::invalid(sprintf('password must be at most %d characters.', self::MAX_LENGTH));
        }

        return $rawPassword;
    }

    public static function hash(string $rawPassword): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        $hash = password_hash($rawPassword, $algorithm);

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public static function verify(string $rawPassword, string $passwordHash): bool
    {
        return password_verify($rawPassword, $passwordHash);
    }

    private static function invalid(string $message): AppException
    {
        return new AppException('VALIDATION_ERROR', $message, 422, ['field' => 'password']);
    }
}
