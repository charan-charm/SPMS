<?php

declare(strict_types=1);

/**
 * B1 — Deterministic validation results (docs/10 D-067).
 *
 * Persisted output of the three ratified cross-checks
 * (meter consumption / solar production / net units) with statuses
 * PASS / NOT_CHECKED / CONFLICT. The row keeps exactly the numbers needed
 * to EXPLAIN a conflict — the computed expectation versus the extracted
 * value, plus the compared operands — and nothing else (§10): no raw OCR,
 * no provider text, no document content.
 *
 * The extracted value is stored verbatim as extracted (§10: a CONFLICT
 * never mutates the source value): expected = -177 alongside extracted =
 * 177 for the observed sign-flip case; the user resolves it later during
 * review, not here.
 *
 * NOT_CHECKED rows carry NULL expected/extracted wherever the operands
 * were unavailable (§15 NULL = unknown; absence is never recorded as a
 * zero or as a fake PASS).
 *
 * expected_value / extracted_value / operand values are signed DECIMALS:
 * net units and arrears may legitimately be negative (docs 6.3 semantics
 * inherited from the Mistral testing phase).
 *
 * Aggregate child: bill_id CASCADE; at most one current result per rule
 * (uq (bill_id, rule)) — this is a per-bill validation fact, not a
 * lifecycle or duplicate-detection constraint.
 */

return [
    'id' => '0012_create_bill_validation_results',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE bill_validation_results (
                id CHAR(36) NOT NULL,
                bill_id CHAR(36) NOT NULL,
                rule VARCHAR(48) NOT NULL,
                status VARCHAR(16) NOT NULL,
                expected_value DECIMAL(14,3) NULL,
                extracted_value DECIMAL(14,3) NULL,
                reason_code VARCHAR(48) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bill_validation_bill_rule (bill_id, rule),
                CONSTRAINT fk_bill_validation_bill FOREIGN KEY (bill_id)
                    REFERENCES bills (id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
