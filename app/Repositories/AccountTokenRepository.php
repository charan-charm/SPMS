<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * W1 — Verification/recovery token persistence (docs/10 D-053).
 *
 * Only SHA-256 token hashes are stored. Single-use semantics are
 * enforced on lookup (used_at IS NULL) plus an explicit markUsed guard,
 * so concurrent reuse cannot double-consume a token.
 *
 * For email verification (6-digit code flow): revokeUnusedByAccountAndType()
 * invalidates all prior unused codes before a new one is issued (resend
 * invalidation — only the newest code is ever valid).
 */
final class AccountTokenRepository
{
    public const TYPE_EMAIL_VERIFICATION = 'EMAIL_VERIFICATION';
    public const TYPE_PASSWORD_RECOVERY = 'PASSWORD_RECOVERY';

    public const TYPES = [self::TYPE_EMAIL_VERIFICATION, self::TYPE_PASSWORD_RECOVERY];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed> stored row (hashes only)
     */
    public function create(string $accountId, string $type, string $tokenHash, string $expiresAt, string $now): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown account token type: ' . $type);
        }

        $row = [
            'id' => $this->uuid(),
            'account_id' => $accountId,
            'type' => $type,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => $now,
        ];

        $statement = $this->connection()->prepare(
            'INSERT INTO account_tokens
                (id, account_id, type, token_hash, expires_at, used_at, created_at)
             VALUES
                (:id, :account_id, :type, :token_hash, :expires_at, :used_at, :created_at)'
        );

        $statement->execute($row);

        return $row;
    }

    /**
     * Valid = hash matches, type matches, unused, unexpired.
     *
     * @return array<string, mixed>|null
     */
    public function findValidByHash(string $type, string $tokenHash, string $now): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM account_tokens
             WHERE type = :type AND token_hash = :hash AND used_at IS NULL AND expires_at > :now'
        );
        $statement->execute([
            'type' => $type,
            'hash' => $tokenHash,
            'now' => $now,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Look up the most recent token record by hash and type, regardless of used/expiry state.
     * Used by AccountService to provide exact diagnostic error states (expired vs used vs invalid).
     *
     * @return array<string, mixed>|null
     */
    public function findByHash(string $type, string $tokenHash): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM account_tokens
             WHERE type = :type AND token_hash = :hash
             ORDER BY created_at DESC LIMIT 1'
        );
        $statement->execute([
            'type' => $type,
            'hash' => $tokenHash,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Single-use guard: only the first caller consumes the token. */
    public function markUsed(string $tokenId, string $now): bool
    {
        $statement = $this->connection()->prepare(
            'UPDATE account_tokens SET used_at = :now WHERE id = :id AND used_at IS NULL'
        );
        $statement->execute(['now' => $now, 'id' => $tokenId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Resend invalidation: mark all current unused tokens of a given type for
     * an account as used (with the provided timestamp) before issuing a new one.
     * This ensures only the newest code is ever valid — prior codes cannot be
     * used even if they were not yet expired.
     */
    public function revokeUnusedByAccountAndType(string $accountId, string $type, string $now): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown account token type: ' . $type);
        }

        $statement = $this->connection()->prepare(
            'UPDATE account_tokens
             SET used_at = :now
             WHERE account_id = :account_id AND type = :type AND used_at IS NULL'
        );
        $statement->execute([
            'now' => $now,
            'account_id' => $accountId,
            'type' => $type,
        ]);
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
