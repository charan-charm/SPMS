<?php

declare(strict_types=1);

/**
 * 0023 — Allow NULL for bills.source and bill_extraction_drafts.source.
 *
 * Ensures fresh bill intake rows can be created prior to document
 * extraction without failing strict integrity constraints.
 *
 * Re-run-safe: MODIFY COLUMN ... NULL DEFAULT NULL is idempotent in MySQL/MariaDB.
 */

return [
    'id' => '0023_allow_null_bill_source',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            'ALTER TABLE bills
             MODIFY COLUMN source VARCHAR(32) NULL DEFAULT NULL'
        );

        $pdo->exec(
            'ALTER TABLE bill_extraction_drafts
             MODIFY COLUMN source VARCHAR(32) NULL DEFAULT NULL'
        );
    },
];
