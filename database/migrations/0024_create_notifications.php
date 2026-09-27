<?php

declare(strict_types=1);

/**
 * SPMS F9 — Notifications Schema.
 *
 * Persists household-scoped system, audit, and actionable notifications derived
 * from real domain events (GoodWe sync, bill lifecycle, recommendations, and insights).
 * Invariants:
 *  - RESTRICT foreign key onto households prevents orphaned notifications.
 *  - Deduplication via uk_notifications_household_event (household_id, event_key).
 *  - Indexed for household-scoped chronological ordering and unread filtering.
 */

return [
    'id' => '0024_create_notifications',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE notifications (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                type VARCHAR(64) NOT NULL,
                severity VARCHAR(16) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                related_resource_type VARCHAR(32) NULL,
                related_resource_id VARCHAR(64) NULL,
                event_key VARCHAR(128) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_notifications_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                KEY idx_notifications_household_time (household_id, created_at),
                KEY idx_notifications_household_unread (household_id, is_read, created_at),
                UNIQUE KEY uk_notifications_household_event (household_id, event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
