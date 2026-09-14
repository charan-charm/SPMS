<?php

declare(strict_types=1);

/**
 * B1 — Data-quality / limitation records (docs/10 D-067 §12).
 *
 * Honest, whitelisted quality facts about a bill's data: missing
 * consumption, unreadable field, ambiguous amount, missing billing
 * period, conflicting meter readings, inconsistent net units. These are
 * CONTROLLED codes (App\Domain\Bill\QualityCode), never free-text dumps
 * of document content, and they never mutate the underlying values: a
 * limitation is recorded, the affected value stays NULL because it is
 * genuinely unknown (§12: limitations are never converted into fabricated
 * defaults).
 *
 * field_name is optional (a limitation may concern the whole document).
 * Multiple flags per bill/rule are legitimate, so no unique constraint
 * beyond de-duplicating the exact same (bill, code, field) statement.
 *
 * Aggregate child: bill_id CASCADE (ownership enforced via bills).
 */

return [
    'id' => '0013_create_bill_quality_flags',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE bill_quality_flags (
                id CHAR(36) NOT NULL,
                bill_id CHAR(36) NOT NULL,
                code VARCHAR(48) NOT NULL,
                field_name VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bill_quality_bill_code_field (bill_id, code, field_name),
                KEY idx_bill_quality_code (code),
                CONSTRAINT fk_bill_quality_bill FOREIGN KEY (bill_id)
                    REFERENCES bills (id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
