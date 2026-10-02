<?php

declare(strict_types=1);

/**
 * 0027 — Add unique billing period constraint per household for active bills.
 *
 * Prevents duplicate active bills for the same household and billing period.
 * Terminal failure states (NOT_A_BILL, UNREADABLE, UNCERTAIN, PROCESSING_FAILED)
 * and rows without periods produce NULL in dedup_active_period and are not constrained.
 *
 * Deduplicates any legacy duplicate periods prior to indexing:
 * - Selects the most authoritative record (APSPDCL statement > verified > complete).
 * - Carries forward verification timestamp if any duplicate was verified.
 * - Deletes the redundant records.
 */

return [
    'id' => '0027_add_bills_household_period_unique_index',
    'up' => static function (PDO $pdo): void {
        // 1. Deduplicate legacy records if present
        $stmt = $pdo->query(
            "SELECT household_id, billing_period_start, billing_period_end, COUNT(*) as c
             FROM bills
             WHERE billing_period_start IS NOT NULL AND billing_period_end IS NOT NULL
               AND status NOT IN ('NOT_A_BILL', 'UNREADABLE', 'UNCERTAIN', 'PROCESSING_FAILED')
             GROUP BY household_id, billing_period_start, billing_period_end
             HAVING c > 1"
        );
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groups as $group) {
            $selectStmt = $pdo->prepare(
                "SELECT id, status, source, billing_period_start, billing_period_end, bill_amount, verified_at, created_at
                 FROM bills
                 WHERE household_id = :h AND billing_period_start = :s AND billing_period_end = :e
                 ORDER BY
                   (CASE WHEN source = 'APSPDCL_EXPORT' THEN 4 WHEN status = 'VERIFIED' THEN 3 WHEN bill_amount IS NOT NULL THEN 2 ELSE 1 END) DESC,
                   created_at DESC"
            );
            $selectStmt->execute([
                ':h' => $group['household_id'],
                ':s' => $group['billing_period_start'],
                ':e' => $group['billing_period_end'],
            ]);
            $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) <= 1) {
                continue;
            }

            $keeper = $rows[0];
            $hasVerified = false;
            $verifiedAt = null;
            foreach ($rows as $row) {
                if ($row['status'] === 'VERIFIED') {
                    $hasVerified = true;
                    $verifiedAt = $row['verified_at'] ?? date('Y-m-d H:i:s');
                    break;
                }
            }

            if ($hasVerified && $keeper['status'] !== 'VERIFIED') {
                $updateStmt = $pdo->prepare(
                    "UPDATE bills SET status = 'VERIFIED', verified_at = :vat WHERE id = :id"
                );
                $updateStmt->execute([':vat' => $verifiedAt, ':id' => $keeper['id']]);
            }

            for ($i = 1; $i < count($rows); $i++) {
                $deleteStmt = $pdo->prepare('DELETE FROM bills WHERE id = :id');
                $deleteStmt->execute([':id' => $rows[$i]['id']]);
            }
        }

        // 2. Add generated column and unique index
        $columnCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table
                AND COLUMN_NAME = :column'
        );
        $columnCheck->execute([':table' => 'bills', ':column' => 'dedup_active_period']);
        $hasColumn = (int) $columnCheck->fetchColumn() > 0;

        if (!$hasColumn) {
            $pdo->exec(
                "ALTER TABLE bills
                 ADD COLUMN dedup_active_period VARCHAR(32) GENERATED ALWAYS AS (
                     CASE
                         WHEN status IN ('NOT_A_BILL', 'UNREADABLE', 'UNCERTAIN', 'PROCESSING_FAILED') THEN NULL
                         WHEN billing_period_start IS NULL OR billing_period_end IS NULL THEN NULL
                         ELSE CONCAT(billing_period_start, '|', billing_period_end)
                     END
                 ) STORED AFTER billing_period_end,
                 ADD UNIQUE KEY uq_bills_household_active_period (household_id, dedup_active_period)"
            );
        }
    },
];
