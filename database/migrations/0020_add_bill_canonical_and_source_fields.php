<?php

declare(strict_types=1);

/**
 * 0020 — Add minimal canonical bill fields and source provenance.
 *
 * Adds:
 * - source: explicit source vocabulary (ELECTRICITY_BILL, APSPDCL_EXPORT, USER_MANUAL);
 * - bill_date / due_date: issue and due dates where available;
 * - opening_kvah / closing_kvah / consumption_kvah: fixed-precision electrical measurements;
 * - source_metadata_json: structured non-canonical line items (e.g. FSA, Surcharge, True-Up)
 *   preserving 100% auditability without polluting the core schema.
 *
 * Same migration set for spms_real / spms_demo / spms_test; re-run-safe
 * per the DATABASE()-scoped presence check.
 */

return [
    'id' => '0020_add_bill_canonical_and_source_fields',
    'up' => static function (PDO $pdo): void {
        $columnExists = static function (string $table, string $column) use ($pdo): bool {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table
                    AND COLUMN_NAME = :column'
            );
            $statement->bindValue(':table', $table);
            $statement->bindValue(':column', $column);
            $statement->execute();

            return (int) $statement->fetchColumn() > 0;
        };

        if (!$columnExists('bills', 'source')) {
            $pdo->exec(
                "ALTER TABLE bills
                     ADD COLUMN source VARCHAR(32) NULL DEFAULT NULL AFTER status,
                     ADD COLUMN bill_date DATE NULL AFTER billing_period_end,
                     ADD COLUMN due_date DATE NULL AFTER bill_date,
                     ADD COLUMN opening_kvah DECIMAL(14,3) NULL AFTER net_units_kwh,
                     ADD COLUMN closing_kvah DECIMAL(14,3) NULL AFTER opening_kvah,
                     ADD COLUMN consumption_kvah DECIMAL(14,3) NULL AFTER closing_kvah,
                     ADD COLUMN source_metadata_json VARCHAR(4000) NULL DEFAULT NULL AFTER contracted_load"
            );
        } else {
            $pdo->exec("ALTER TABLE bills MODIFY COLUMN source VARCHAR(32) NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE bills MODIFY COLUMN source_metadata_json VARCHAR(4000) NULL DEFAULT NULL");
        }

        if (!$columnExists('bill_extraction_drafts', 'source')) {
            $pdo->exec(
                "ALTER TABLE bill_extraction_drafts
                     ADD COLUMN source VARCHAR(32) NULL DEFAULT NULL AFTER service_number,
                     ADD COLUMN bill_date DATE NULL AFTER billing_period_end,
                     ADD COLUMN due_date DATE NULL AFTER bill_date,
                     ADD COLUMN opening_kvah DECIMAL(14,3) NULL AFTER net_units_kwh,
                     ADD COLUMN closing_kvah DECIMAL(14,3) NULL AFTER opening_kvah,
                     ADD COLUMN consumption_kvah DECIMAL(14,3) NULL AFTER closing_kvah,
                     ADD COLUMN source_metadata_json VARCHAR(4000) NULL DEFAULT NULL AFTER contracted_load"
            );
        } else {
            $pdo->exec("ALTER TABLE bill_extraction_drafts MODIFY COLUMN source VARCHAR(32) NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE bill_extraction_drafts MODIFY COLUMN source_metadata_json VARCHAR(4000) NULL DEFAULT NULL");
        }
    },
];
