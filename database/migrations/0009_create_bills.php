<?php

declare(strict_types=1);

/**
 * B1 — Bills: the authoritative bill aggregate root (docs/10 D-067).
 *
 * A bill belongs to exactly one household; household_id is the ownership
 * boundary, RESTRICT on delete (D-056 convention): bill rows can never be
 * orphaned or silently detached from their owning household, and SET NULL
 * is never used.
 *
 * Data-minimization invariant (D-067 §1): only normalized SPMS-relevant
 * facts are columns. Customer name, account/USC number, bill number,
 * meter serial, DTR code, phone, QR payload and any raw OCR text or raw
 * provider response are deliberately NOT stored anywhere in the bills
 * schema — they are not needed for energy/cost analysis, verification or
 * provenance.
 *
 * NULL semantics: every value column is NULL for "unknown / not extracted
 * / not available". Zero is only ever stored when the source explicitly
 * says zero — no DEFAULT 0 exists on any data column (docs/04 §15
 * no-fake-values rule; docs/01 §6 "not every bill contains everything").
 *
 * Money uses fixed-precision DECIMAL (docs/05: "Monetary values use
 * fixed-precision decimal storage"); no FLOAT/DOUBLE anywhere. Energy
 * keeps three decimal places so decimal kWh never truncates; net_units and
 * arrears are signed (negative values are meaningful, never folded to 0).
 *
 * status is the application-validated lifecycle vocabulary (VARCHAR like
 * accounts/households/insights status columns); the transition rules live
 * in App\Domain\Bill\BillLifecycle, never in the database.
 *
 * billing_period_start/end are plain DATEs; chronology (start <= end) is a
 * domain persistence rule enforced by App\Domain\Bill\BillDataField and
 * the service layer — the same "values validated by the application"
 * convention as every other status/role column in this schema.
 *
 * Timestamps are UTC DATETIME written by the application (docs/05 §13).
 */

return [
    'id' => '0009_create_bills',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE bills (
                id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'UPLOADED',
                billing_period_start DATE NULL,
                billing_period_end DATE NULL,
                consumption_kwh DECIMAL(14,3) NULL,
                previous_meter_reading DECIMAL(14,3) NULL,
                present_meter_reading DECIMAL(14,3) NULL,
                previous_solar_reading DECIMAL(14,3) NULL,
                present_solar_reading DECIMAL(14,3) NULL,
                solar_units_kwh DECIMAL(14,3) NULL,
                net_units_kwh DECIMAL(14,3) NULL,
                bill_amount DECIMAL(12,2) NULL,
                arrears DECIMAL(12,2) NULL,
                total_due DECIMAL(12,2) NULL,
                energy_charges DECIMAL(12,2) NULL,
                fixed_charges DECIMAL(12,2) NULL,
                customer_service_charges DECIMAL(12,2) NULL,
                other_charges DECIMAL(12,2) NULL,
                taxes_adjustments DECIMAL(12,2) NULL,
                utility_provider VARCHAR(120) NULL,
                tariff_category VARCHAR(60) NULL,
                meter_type VARCHAR(60) NULL,
                contracted_load DECIMAL(8,2) NULL,
                verified_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_bills_household_status (household_id, status),
                CONSTRAINT fk_bills_household FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
