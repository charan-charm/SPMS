<?php

declare(strict_types=1);

/**
 * SPMS O4 — Household Solar Integration Connection Schema (docs/10 D-059, D-061).
 *
 * Persists the encrypted external provider connection for a household.
 *
 * Security & Ownership Rules:
 *  - Enforces 1 active solar connection per household (uq_solar_conn_household).
 *  - RESTRICT foreign key onto households prevents orphaned connections.
 *  - encrypted_credentials stores the AEAD AES-256-GCM ciphertext + IV + tag.
 *  - account_identifier stores only the safe masked identity (e.g. u***@gmail.com).
 *  - inverter_sn is nullable because during initial setup the serial may not yet
 *    be resolved from the station basic info.
 */

return [
    'id' => '0017_create_household_solar_connections',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE household_solar_connections (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                provider VARCHAR(32) NOT NULL DEFAULT 'GOODWE_SEMS',
                station_id VARCHAR(64) NOT NULL,
                inverter_sn VARCHAR(64) NULL,
                account_identifier VARCHAR(255) NOT NULL,
                encrypted_credentials TEXT NOT NULL,
                connection_status VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
                last_successful_sync_at DATETIME NULL,
                last_error_code VARCHAR(64) NULL,
                last_error_message TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_solar_conn_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT,
                CONSTRAINT uq_solar_conn_household UNIQUE (household_id),
                KEY idx_solar_conn_status (connection_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
