<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\RequestId;
use PDO;

/**
 * W1 — Append-only security/audit event store (docs/10 D-056).
 *
 * Writes only. Rows carry actor identifiers as plain columns (no
 * foreign keys, no cascades) so history survives account/household
 * lifecycle changes. The (event_type, context_key, created_at) index
 * also backs the DB-backed rate limiter (docs/10 D-053.6).
 *
 * Never written here: passwords, raw session tokens, raw verification
 * or recovery tokens.
 */
final class SecurityEventRepository
{
    public const REGISTRATION_SUCCEEDED = 'REGISTRATION_SUCCEEDED';
    public const LOGIN_SUCCEEDED = 'LOGIN_SUCCEEDED';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGOUT_SUCCEEDED = 'LOGOUT_SUCCEEDED';
    public const EMAIL_VERIFICATION_SUCCEEDED = 'EMAIL_VERIFICATION_SUCCEEDED';
    public const VERIFICATION_FAILED = 'VERIFICATION_FAILED';
    public const VERIFICATION_RESEND_REQUESTED = 'VERIFICATION_RESEND_REQUESTED';
    public const VERIFICATION_RESEND_SUPPRESSED = 'VERIFICATION_RESEND_SUPPRESSED';
    public const RECOVERY_REQUESTED = 'RECOVERY_REQUESTED';
    public const RECOVERY_REQUEST_SUPPRESSED = 'RECOVERY_REQUEST_SUPPRESSED';
    public const PASSWORD_RESET_SUCCEEDED = 'PASSWORD_RESET_SUCCEEDED';
    public const PASSWORD_RESET_FAILED = 'PASSWORD_RESET_FAILED';
    public const SESSION_REVOKED = 'SESSION_REVOKED';
    public const CSRF_REJECTED = 'CSRF_REJECTED';
    public const RATE_LIMIT_REJECTED = 'RATE_LIMIT_REJECTED';
    public const MAIL_SEND_FAILED = 'MAIL_SEND_FAILED';

    public const CONTEXT_EMAIL = 'EMAIL';
    public const CONTEXT_IP = 'IP';

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function record(
        string $eventType,
        ?string $contextKey = null,
        ?string $accountId = null,
        ?array $context = null,
        ?string $ipAddress = null
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT INTO security_events
                (id, event_type, context_key, account_id, request_id, ip_address, context, created_at)
             VALUES
                (:id, :event_type, :context_key, :account_id, :request_id, :ip_address, :context, :created_at)'
        );

        $statement->execute([
            'id' => self::uuid(),
            'event_type' => $eventType,
            'context_key' => $contextKey,
            'account_id' => $accountId,
            'request_id' => RequestId::current(),
            'ip_address' => $ipAddress,
            'context' => $context === null ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Count recent events for a rate-limit bucket.
     */
    public function countRecent(string $eventType, string $contextKey, int $windowMinutes): int
    {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) FROM security_events
             WHERE event_type = :event_type AND context_key = :context_key
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :window MINUTE)'
        );
        $statement->execute([
            'event_type' => $eventType,
            'context_key' => $contextKey,
            'window' => $windowMinutes,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Count recent events for a specific account — used for per-account
     * verification attempt limiting (max attempts before rate-lock).
     */
    public function countRecentForAccount(string $eventType, string $accountId, int $windowSeconds): int
    {
        $windowMinutes = max(1, (int) ceil($windowSeconds / 60));

        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) FROM security_events
             WHERE event_type = :event_type AND account_id = :account_id
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :window MINUTE)'
        );
        $statement->execute([
            'event_type' => $eventType,
            'account_id' => $accountId,
            'window' => $windowMinutes,
        ]);

        return (int) $statement->fetchColumn();
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
