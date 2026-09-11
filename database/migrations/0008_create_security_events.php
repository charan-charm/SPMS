<?php

declare(strict_types=1);

/**
 * W1 — Append-oriented security/audit event store (docs/10 D-056).
 *
 * Records security-sensitive actions (registration, login success and
 * failure, logout, verification, recovery, password reset, session
 * revocation, CSRF and rate-limit rejections).
 *
 * History must survive account/household lifecycle changes: account_id
 * is a plain identifier column with NO foreign key and NO cascade —
 * events outlive the entities they refer to (docs/01 §39, docs/01 §31).
 *
 * context_key is a denormalized lookup key (e.g. hashed email bucket or
 * IP bucket for rate limiting; event type for audit queries) so the
 * append-only event stream can also back DB-backed rate limiting
 * (docs/10 D-053.6) without a second state table.
 *
 * Never stored here: passwords, raw session tokens, raw verification or
 * recovery tokens.
 */

return [
    'id' => '0008_create_security_events',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE security_events (
                id CHAR(36) NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                context_key VARCHAR(128) NULL,
                account_id CHAR(36) NULL,
                request_id VARCHAR(64) NULL,
                ip_address VARCHAR(45) NULL,
                context LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_security_events_type_key_time (event_type, context_key, created_at),
                KEY idx_security_events_account_time (account_id, created_at),
                KEY idx_security_events_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
