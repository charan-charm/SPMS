<?php

declare(strict_types=1);

/**
 * SPMS F7 — Recommendations Schema (docs/01 §15, docs/02 §2 F7, docs/05 §13-16, docs/10 D-072).
 *
 * Persists actionable household recommendations derived from authoritative F3/F4/F5/F6 outputs.
 * Invariants:
 *  - RESTRICT foreign key prevents orphaned recommendations.
 *  - Canonical recommendation fields: id, household_id, type, status, priority, title, summary,
 *    action, reason, timing_window, potential_impact, impact_json, evidence_json, confidence,
 *    quality, data_nature, provenance_json, limitations_json, relevant_period_start/end,
 *    valid_from, valid_until, created_at, updated_at, dismissed_at.
 *  - Indexed for household-scoped status queries, validity expiration, and deduplication.
 */

return [
    'id' => '0021_create_recommendations',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE recommendations (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                type VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                priority VARCHAR(16) NOT NULL,
                title VARCHAR(255) NOT NULL,
                summary TEXT NOT NULL,
                action TEXT NOT NULL,
                reason TEXT NOT NULL,
                timing_window VARCHAR(128) NOT NULL,
                potential_impact TEXT NULL,
                impact_json TEXT NULL,
                evidence_json LONGTEXT NOT NULL,
                confidence VARCHAR(16) NOT NULL,
                quality VARCHAR(16) NULL,
                data_nature VARCHAR(16) NOT NULL,
                provenance_json TEXT NOT NULL,
                limitations_json TEXT NULL,
                relevant_period_start VARCHAR(32) NULL,
                relevant_period_end VARCHAR(32) NULL,
                valid_from DATETIME NOT NULL,
                valid_until DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                dismissed_at DATETIME NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_recommendations_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                KEY idx_recommendations_lookup (household_id, status, type, priority),
                KEY idx_recommendations_validity (household_id, status, valid_until),
                KEY idx_recommendations_dedup (household_id, type, status, relevant_period_start, relevant_period_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
