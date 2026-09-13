<?php

declare(strict_types=1);

/**
 * W3 — Household memberships: the authorization boundary (docs/10 D-050, D-056).
 *
 * One row per (household, account). role VARCHAR mirrors the accounts
 * status/role convention (values validated by the application, D-049
 * style); the only v1 role is OWNER — MEMBER/VIEWER are future, not
 * pre-created. N:N-capable: nothing here prevents multiple members per
 * household, and single-household-per-account is a v1 application rule
 * enforced in HouseholdService, never by schema truncation.
 *
 * Delete behavior (D-056): account -> memberships CASCADE (a deleted
 * account's authorizations vanish with it); household -> memberships
 * RESTRICT (household removal must go through the controlled lifecycle,
 * never silent loss of the authorizing link). SET NULL is never used.
 */

return [
    'id' => '0006_create_household_memberships',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE household_memberships (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                account_id CHAR(36) NOT NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'OWNER',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_memberships_household_account (household_id, account_id),
                KEY idx_memberships_account (account_id),
                CONSTRAINT fk_memberships_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT fk_memberships_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
