<?php

declare(strict_types=1);

/**
 * F6 — Insight persistence schema (docs/10 D-046, docs/05 §3, §7, §13, §16).
 *
 * NULL household_id means the household scope is not yet determinable in
 * v1 (no household linkage exists before the account/household
 * milestones) — documented "not available" semantics, not zero.
 *
 * NULL data_nature means no analytical value was involved (e.g. a data
 * limitation insight) — again honest "not available", not zero.
 *
 * The dedup identity (household + type + affected period) is enforced by a
 * database unique key over NULL-safe generated columns, because MySQL
 * unique indexes ignore rows containing NULL.
 */

return [
    'id' => '0001_create_insights',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE insights (
                id CHAR(36) NOT NULL,
                household_id VARCHAR(64) NULL,
                category VARCHAR(32) NOT NULL,
                type VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                summary TEXT NOT NULL,
                significance VARCHAR(16) NOT NULL,
                confidence VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                affected_period_start VARCHAR(32) NULL,
                affected_period_end VARCHAR(32) NULL,
                affected_period_resolution VARCHAR(32) NULL,
                detected_at DATETIME NOT NULL,
                first_detected_at DATETIME NOT NULL,
                last_observed_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                observation_count INT UNSIGNED NOT NULL DEFAULT 1,
                data_nature VARCHAR(16) NULL,
                data_status VARCHAR(16) NOT NULL,
                quality VARCHAR(16) NULL,
                explanation TEXT NULL,
                explanation_source VARCHAR(64) NOT NULL,
                evidence_summary TEXT NULL,
                supporting_facts LONGTEXT NOT NULL,
                limitations LONGTEXT NULL,
                provenance LONGTEXT NOT NULL,
                dedup_household VARCHAR(66) GENERATED ALWAYS AS (COALESCE(household_id, '')) STORED,
                dedup_period VARCHAR(70) GENERATED ALWAYS AS (CONCAT(COALESCE(affected_period_start, ''), '|', COALESCE(affected_period_end, ''))) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uq_insights_dedup (dedup_household, type, dedup_period),
                KEY idx_insights_household_status (household_id, status),
                KEY idx_insights_status_significance (status, significance)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
