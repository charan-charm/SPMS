<?php

declare(strict_types=1);

/**
 * W3 — Households (docs/10 D-050, D-056, D-058; docs/01 §31).
 *
 * Household-domain identity lives here, never as accounts.household_id.
 * V1 binds one account to one household by application rule, but the
 * schema shape is N:N-capable (memberships table, 0006).
 *
 * status is the controlled lifecycle mirror of the account lifecycle
 * (docs/01 §31, D-049): ACTIVE, DELETION_PENDING (access disabled, data
 * retained pending policy), DELETED (soft terminal state — rows kept so
 * analytical history referenced by insights/memberships never silently
 * disappears). Hard removal is a W5 retention decision, not implied.
 *
 * Timestamps are UTC DATETIME written by the application (docs/05 §13).
 */

return [
    'id' => '0005_create_households',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE households (
                id CHAR(36) NOT NULL,
                label VARCHAR(120) NULL,
                timezone VARCHAR(64) NOT NULL,
                location_label VARCHAR(190) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_households_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
