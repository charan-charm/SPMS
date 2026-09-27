<?php

declare(strict_types=1);

/**
 * SPMS F3 — Household Forecasts Schema (docs/01 §14, docs/02 §2, docs/05 §12, docs/10 D-040).
 *
 * Persists normalized solar and consumption forecast snapshots, projected energy balance,
 * confidence levels, uncertainty ranges, provenance, and data limitations.
 *
 * Invariants:
 *  - RESTRICT foreign key prevents orphaned forecasts.
 *  - Data nature is explicitly PREDICTED or ESTIMATED (never ACTUAL).
 *  - High-precision DECIMAL types for energy (kWh, DECIMAL(14,3)). FLOAT is prohibited.
 *  - Indexed on (household_id, forecast_type, horizon, created_at).
 */

return [
    'id' => '0019_create_household_forecasts',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE household_forecasts (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                forecast_type VARCHAR(32) NOT NULL,
                horizon VARCHAR(32) NOT NULL,
                forecast_start DATETIME NOT NULL,
                forecast_end DATETIME NOT NULL,
                resolution VARCHAR(16) NOT NULL,
                predicted_value DECIMAL(14,3) NOT NULL,
                uncertainty_lower DECIMAL(14,3) NULL,
                uncertainty_upper DECIMAL(14,3) NULL,
                confidence VARCHAR(16) NOT NULL,
                data_nature VARCHAR(16) NOT NULL,
                data_quality VARCHAR(16) NOT NULL,
                points_json LONGTEXT NOT NULL,
                limitations_json TEXT NULL,
                provenance_json TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_household_forecasts_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                KEY idx_forecasts_lookup (household_id, forecast_type, horizon, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
