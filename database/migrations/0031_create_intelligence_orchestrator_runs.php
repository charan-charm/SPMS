<?php

declare(strict_types=1);

/**
 * SPMS Intelligence — Automatic Intelligence Orchestrator Execution Log.
 *
 * Persists execution metrics, timing, error states, and outcome counts
 * for scheduled background intelligence evaluations across households.
 */

return [
    'id' => '0031_create_intelligence_orchestrator_runs',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS intelligence_orchestrator_runs (
                id CHAR(36) NOT NULL,
                environment VARCHAR(16) NOT NULL,
                triggered_by VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL,
                households_evaluated INT UNSIGNED NOT NULL DEFAULT 0,
                insights_created INT UNSIGNED NOT NULL DEFAULT 0,
                insights_updated INT UNSIGNED NOT NULL DEFAULT 0,
                insights_expired INT UNSIGNED NOT NULL DEFAULT 0,
                recommendations_created INT UNSIGNED NOT NULL DEFAULT 0,
                recommendations_updated INT UNSIGNED NOT NULL DEFAULT 0,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                error_message TEXT NULL,
                summary_json LONGTEXT NULL,
                PRIMARY KEY (id),
                KEY idx_orchestrator_runs_started (started_at),
                KEY idx_orchestrator_runs_env_status (environment, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
    'down' => static function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS intelligence_orchestrator_runs');
    },
];
