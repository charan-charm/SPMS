<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * W1 — Server-side session persistence (docs/10 D-051).
 *
 * Raw session/CSRF tokens never reach this repository — callers pass
 * SHA-256 hashes only. Authentication-agnostic: no authorization
 * decisions are made here.
 */
final class SessionRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function insert(array $session): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO sessions
                (id, account_id, session_token_hash, csrf_token, created_at, last_activity_at, expires_at, absolute_expires_at, revoked_at)
             VALUES
                (:id, :account_id, :session_token_hash, :csrf_token, :created_at, :last_activity_at, :expires_at, :absolute_expires_at, :revoked_at)'
        );

        $statement->execute($session);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySessionTokenHash(string $tokenHash): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM sessions WHERE session_token_hash = :hash'
        );
        $statement->execute(['hash' => $tokenHash]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $sessionId): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM sessions WHERE id = :id'
        );
        $statement->execute(['id' => $sessionId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function touchActivity(string $sessionId, string $idleExpiresAt, string $now): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE sessions SET last_activity_at = :now, expires_at = :idle_expires_at WHERE id = :id'
        );
        $statement->execute([
            'now' => $now,
            'idle_expires_at' => $idleExpiresAt,
            'id' => $sessionId,
        ]);
    }

    public function revoke(string $sessionId, string $now): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE sessions SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL'
        );
        $statement->execute(['now' => $now, 'id' => $sessionId]);
    }

    public function revokeAllForAccount(string $accountId, string $now): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE sessions SET revoked_at = :now WHERE account_id = :account_id AND revoked_at IS NULL'
        );
        $statement->execute(['now' => $now, 'account_id' => $accountId]);
    }

    /**
     * Rows for security assertions (e.g. counting active sessions).
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForAccount(string $accountId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM sessions WHERE account_id = :account_id AND revoked_at IS NULL
             AND expires_at > UTC_TIMESTAMP() AND absolute_expires_at > UTC_TIMESTAMP()'
        );
        $statement->execute(['account_id' => $accountId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
