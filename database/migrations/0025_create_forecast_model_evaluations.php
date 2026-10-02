<?php

declare(strict_types=1);

/**
 * SPMS F3 — Forecast Model Evaluations & Provenance Schema (docs/01 §14, docs/02 §2, docs/10 D-040).
 *
 * Persists ML and baseline forecast evaluation runs, backtesting performance metrics (MAE, RMSE, MAPE),
 * training and evaluation windows, sample sizes, and model selection status for solar and consumption models.
 *
 * Invariants:
 *  - RESTRICT foreign key prevents orphaned evaluation records.
 *  - High-precision DECIMAL types for error metrics (DECIMAL(10,4)). FLOAT is prohibited.
 *  - Indexed on (household_id, model_type, forecast_horizon, created_at).
 */

return [
    'id' => '0025_create_forecast_model_evaluations',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE forecast_model_evaluations (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                model_type VARCHAR(32) NOT NULL,
                model_name VARCHAR(64) NOT NULL,
                model_version VARCHAR(32) NOT NULL,
                forecast_horizon VARCHAR(32) NOT NULL,
                training_start DATETIME NULL,
                training_end DATETIME NULL,
                evaluation_start DATETIME NULL,
                evaluation_end DATETIME NULL,
                training_samples INT NOT NULL DEFAULT 0,
                evaluation_samples INT NOT NULL DEFAULT 0,
                mae DECIMAL(10,4) NULL,
                rmse DECIMAL(10,4) NULL,
                mape DECIMAL(10,4) NULL,
                baseline_mae DECIMAL(10,4) NULL,
                baseline_rmse DECIMAL(10,4) NULL,
                status VARCHAR(32) NOT NULL,
                fallback_reason TEXT NULL,
                feature_set VARCHAR(64) NOT NULL,
                metrics_json TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_forecast_evaluations_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                KEY idx_model_eval_lookup (household_id, model_type, forecast_horizon, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
