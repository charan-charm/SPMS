<?php

declare(strict_types=1);

/**
 * W1 — Verification and recovery tokens (docs/10 D-053).
 *
 * One typed table serves email verification and password recovery.
 * Tokens are random and opaque; ONLY their SHA-256 hash is stored
 * (token_hash UNIQUE). Single-use via used_at; expiry enforced
 * server-side on lookup.
 *
 * Ratified TTLs (enforced by the service, not the schema): email
 * verification 24 hours, password recovery 1 hour.
 *
 * account_id cascades on account deletion (docs/10 D-056: account →
 * account_tokens CASCADE).
 */

return [
    'id' => '0004_create_account_tokens',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE account_tokens (
                id CHAR(36) NOT NULL,
                account_id CHAR(36) NOT NULL,
                type VARCHAR(32) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_account_tokens_token_hash (token_hash),
                KEY idx_account_tokens_account_type (account_id, type, created_at),
                KEY idx_account_tokens_expires_at (expires_at),
                CONSTRAINT fk_account_tokens_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
