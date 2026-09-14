<?php

declare(strict_types=1);

/**
 * B1 — Field-level provenance for bills (docs/10 D-067).
 *
 * One row per (bill, field) recording WHERE THE CURRENT bills value came
 * from and, where the AI stated one, the per-field extraction confidence.
 * This is the honest answer to "AI extracted 145 / user corrected to 148 /
 * authoritative 148" (§7/§9): the source column distinguishes AI_EXTRACTION
 * from USER_CORRECTION, while the immutable draft keeps the original AI
 * value forever.
 *
 * confidence is NULL unless the provider actually produced a value for
 * THIS field — document-level confidence is never fanned out here (§8,
 * §14: no fabrication). Zero confidence is only stored when the provider
 * said zero.
 *
 * field_name is a whitelist-checked domain identifier (App\Domain\Bill\
 * BillField), never free text and never a raw-document concept — there are
 * no rows for un-persisted fields like customer name or meter serial,
 * because those columns deliberately do not exist.
 *
 * Aggregate child: bill_id CASCADE (lives and dies with its bill;
 * ownership is enforced through bills.household_id one level up).
 */

return [
    'id' => '0011_create_bill_field_provenance',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE bill_field_provenance (
                id CHAR(36) NOT NULL,
                bill_id CHAR(36) NOT NULL,
                field_name VARCHAR(64) NOT NULL,
                source VARCHAR(24) NOT NULL,
                confidence DECIMAL(5,4) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bill_provenance_field (bill_id, field_name),
                CONSTRAINT fk_bill_provenance_bill FOREIGN KEY (bill_id)
                    REFERENCES bills (id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
