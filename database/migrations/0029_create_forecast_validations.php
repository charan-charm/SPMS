<?php

declare(strict_types=1);

/**
 * SPMS F3 — Forecast Walk-Forward Validations Schema (docs/01 §14, docs/02 §2, docs/10 D-040).
 *
 * Persists immutable daily walk-forward validation snapshots comparing predictions against authentic
 * GoodWe SEMS telemetry with real Open-Meteo weather factors.
 */

return [
    'id' => '0029_create_forecast_validations',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forecast_validations (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                forecast_id CHAR(36) NULL,
                target_date DATE NOT NULL,
                forecast_created_at DATETIME NOT NULL,
                model_name VARCHAR(64) NOT NULL,
                model_version VARCHAR(32) NOT NULL,
                validation_type VARCHAR(64) NOT NULL,
                weather_source VARCHAR(64) NOT NULL,
                weather_mode VARCHAR(64) NOT NULL,
                predicted_generation_kwh DECIMAL(10,3) NOT NULL,
                actual_generation_kwh DECIMAL(10,3) NOT NULL,
                mae_kw DECIMAL(10,4) NOT NULL,
                rmse_kw DECIMAL(10,4) NOT NULL,
                accuracy_percent DECIMAL(5,2) NOT NULL,
                daylight_hours_validated INT NOT NULL,
                validation_status VARCHAR(32) NOT NULL DEFAULT 'VALIDATED',
                time_series_json LONGTEXT NULL,
                provenance_json TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_forecast_validations_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                UNIQUE KEY uk_household_date_model (household_id, target_date, model_version),
                KEY idx_val_lookup (household_id, model_version, target_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
