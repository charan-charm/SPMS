<?php

declare(strict_types=1);

/**
 * SPMS O4 — Solar Inverter Readings Schema (docs/10 D-059, D-060, D-061, D-062).
 *
 * Persists high-precision solar generation and electrical parameters ingested
 * from external solar inverter APIs (GoodWe SEMS+).
 *
 * Requirements & Invariants:
 *  - High-precision DECIMAL types for power (W, DECIMAL(10,2)) and energy (kWh, DECIMAL(14,3)).
 *    FLOAT is strictly prohibited.
 *  - Inverter status, data_nature (ACTUAL), data_status (FRESH/STALE), data_quality (GOOD/PARTIAL).
 *  - Smart meter reality: If a smart meter is not installed, grid_power_w and consumption_power_w
 *    are strictly NULL (never fabricated from solar generation).
 *  - RESTRICT foreign key prevents orphaned solar readings.
 *  - Idempotent unique constraint on (household_id, station_id, measurement_time).
 */

return [
    'id' => '0018_create_solar_readings',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE solar_readings (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                station_id VARCHAR(64) NOT NULL,
                inverter_sn VARCHAR(64) NULL,
                measurement_time DATETIME NOT NULL,
                received_at DATETIME NOT NULL,
                power_w DECIMAL(10,2) NOT NULL,
                daily_generation_kwh DECIMAL(14,3) NOT NULL,
                total_generation_kwh DECIMAL(14,3) NULL,
                pv_power_w DECIMAL(10,2) NULL,
                ac_voltage_v DECIMAL(8,2) NULL,
                ac_current_a DECIMAL(8,2) NULL,
                ac_frequency_hz DECIMAL(6,2) NULL,
                temperature_c DECIMAL(6,2) NULL,
                grid_power_w DECIMAL(10,2) NULL,
                consumption_power_w DECIMAL(10,2) NULL,
                inverter_status VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
                data_nature VARCHAR(16) NOT NULL DEFAULT 'ACTUAL',
                data_status VARCHAR(16) NOT NULL DEFAULT 'FRESH',
                data_quality VARCHAR(16) NOT NULL DEFAULT 'GOOD',
                sync_batch_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_solar_readings_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                CONSTRAINT uq_solar_reading_bucket UNIQUE (household_id, station_id, measurement_time),
                KEY idx_solar_readings_household_time (household_id, measurement_time),
                KEY idx_solar_readings_batch (sync_batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
