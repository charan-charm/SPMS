<?php

declare(strict_types=1);

/**
 * W1 — Canonical account identity (docs/10 D-049).
 *
 * One accounts table: no separate user/profile authentication tables.
 * role/status/mode are server-assigned only — registration creates
 * USER + REAL with PENDING_VERIFICATION status; client input can never
 * set them. Email is stored normalized lowercase; the case-insensitive
 * utf8mb4_unicode_ci unique index backs the uniqueness guarantee.
 *
 * Timestamps are UTC DATETIME written by the application (docs/05 §13).
 */

return [
    'id' => '0002_create_accounts',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE accounts (
                id CHAR(36) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'PENDING_VERIFICATION',
                role VARCHAR(16) NOT NULL DEFAULT 'USER',
                mode VARCHAR(16) NOT NULL DEFAULT 'REAL',
                email_verified_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounts_email (email),
                KEY idx_accounts_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
