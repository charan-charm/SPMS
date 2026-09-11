<?php

declare(strict_types=1);

/**
 * W1 — Server-side database-backed sessions (docs/10 D-051, D-056).
 *
 * The cookie carries an opaque 256-bit random token; this table stores
 * ONLY its SHA-256 hash (session_token_hash), never the raw identifier.
 * The per-session CSRF synchronizer token is stored as the server-side
 * representation (csrf_token) per the OWASP synchronizer pattern: the
 * server must re-deliver it for session bootstrap, and its compromise
 * without the cookie is inert. The authentication-critical session
 * identifier remains hash-only (docs/10 D-051).
 *
 * expires_at is the sliding idle deadline (last_activity_at + idle
 * window, refreshed on authenticated activity); absolute_expires_at is
 * fixed at creation and never extended. revoked_at marks explicit
 * logout/revocation. Expired/revoked sessions never authenticate.
 *
 * account_id cascades on account deletion (docs/10 D-056: account →
 * sessions CASCADE).
 */

return [
    'id' => '0003_create_sessions',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE sessions (
                id CHAR(36) NOT NULL,
                account_id CHAR(36) NOT NULL,
                session_token_hash CHAR(64) NOT NULL,
                csrf_token CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                last_activity_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                absolute_expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sessions_token_hash (session_token_hash),
                KEY idx_sessions_account_revoked (account_id, revoked_at),
                KEY idx_sessions_expires_at (expires_at),
                CONSTRAINT fk_sessions_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
