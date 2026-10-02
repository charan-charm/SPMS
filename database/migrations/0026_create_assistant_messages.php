<?php

declare(strict_types=1);

/**
 * SPMS F8 — AI Energy Assistant Conversation Schema.
 *
 * Persists household-scoped conversation messages exchanged with the SPMS Energy Assistant.
 * Invariants:
 *  - Strict household and account ownership: RESTRICT foreign keys onto households and accounts.
 *  - Household isolation: all queries scoped strictly by household_id.
 *  - Provider and model metadata recorded for auditability and transparency.
 */

return [
    'id' => '0026_create_assistant_messages',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE assistant_messages (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                account_id CHAR(36) NOT NULL,
                role VARCHAR(16) NOT NULL,
                content MEDIUMTEXT NOT NULL,
                context_domains_json TEXT NULL,
                provider VARCHAR(32) NULL,
                model VARCHAR(64) NULL,
                fallback_used TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_assistant_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                CONSTRAINT fk_assistant_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id) ON DELETE RESTRICT,
                KEY idx_assistant_household_time (household_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
