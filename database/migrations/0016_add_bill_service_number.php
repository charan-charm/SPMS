<?php

declare(strict_types=1);

/**
 * D-071 — Service number on the bill aggregate and its immutable draft.
 *
 * The bill UUID remains the primary key (one service number legitimately
 * has many monthly bills — no uniqueness is imposed here). The normalized
 * bills column stores the CANONICAL extracted number for deterministic
 * ownership matching; the draft column is part of the immutable AI
 * extraction snapshot ("the AI said this value, verbatim after text
 * cleaning") and is NEVER rewritten by ownership logic.
 *
 * Data minimization stays intact: this is an operational service
 * identifier the owner registered or supplied documents already carry —
 * it is not customer name / mobile / Aadhaar / bill number / meter
 * serial, and nothing else about those distinctions changes (§P).
 *
 * Same migration set for spms_real / spms_demo / spms_test; re-run-safe
 * per the 0007/0015 DATABASE()-scoped presence-check precedent.
 */

return [
    'id' => '0016_add_bill_service_number',
    'up' => static function (PDO $pdo): void {
        $columnExists = static function (string $table) use ($pdo): bool {
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table
                    AND COLUMN_NAME = 'service_number'"
            );
            $statement->bindValue(':table', $table);
            $statement->execute();

            return (int) $statement->fetchColumn() > 0;
        };

        if (!$columnExists('bills')) {
            $pdo->exec(
                'ALTER TABLE bills
                     ADD COLUMN service_number VARCHAR(32) NULL AFTER household_id,
                     ADD KEY idx_bills_service_number (service_number)'
            );
        }

        if (!$columnExists('bill_extraction_drafts')) {
            $pdo->exec(
                'ALTER TABLE bill_extraction_drafts
                     ADD COLUMN service_number VARCHAR(32) NULL AFTER document_confidence'
            );
        }
    },
];
