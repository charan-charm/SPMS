<?php

declare(strict_types=1);

/**
 * W3 — Insights household foreign key (docs/10 D-057, D-058).
 *
 * D-058 requires the fake/test household residue to be handled before
 * this FK may exist; step 1 performs that cleanup deterministically and
 * verbosely. Step 2 narrows insights.household_id to CHAR(36) so the
 * FK is type-valid (the dedup generated column and its unique key are
 * rebuilt around the change, keeping their exact original definition).
 * Step 3 adds the ratified RESTRICT constraint: a household referenced
 * by insights can never be silently deleted, and SET NULL is never used
 * (a deleted household's insights must not quietly become system-scope).
 *
 * NULL household_id keeps its ratified meaning (D-046/D-057): system /
 * undetermined-scope insights. Only un-referenceable non-NULL values are
 * removed — every surviving insight still points at a real household.
 *
 * Every step is guarded against partial re-run (MariaDB DDL is not
 * transactional on this server, so statements must be safe to resume).
 */

return [
    'id' => '0007_add_insights_household_foreign_key',
    'up' => static function (PDO $pdo): void {
        // 1) Deterministic residue cleanup (documented, D-058): insight
        //    rows whose non-NULL household scope references nothing that
        //    exists in households. These are F6 synthetic fixtures
        //    ('f6-hh-*' family); no pre-W3 insight ever pointed at a real
        //    household because none existed. System-scope NULL rows are
        //    preserved untouched.
        $removed = $pdo->exec(
            'DELETE i FROM insights i
             LEFT JOIN households h ON h.id = i.household_id
             WHERE i.household_id IS NOT NULL AND h.id IS NULL'
        );
        echo "[info] 0007: removed {$removed} insight row(s) with un-referenceable synthetic household scope (D-058 residue handling)" . PHP_EOL;

        // 2) Narrow household_id to CHAR(36), rebuilding the dependent
        //    stored generated column and dedup unique key around it.
        //
        //    The rebuild of the generated column and its unique key is
        //    driven by PRESENCE, not by the column type, so a re-run after
        //    a mid-way DDL failure (MariaDB DDL auto-commits) completes the
        //    missing pieces instead of skipping them. On the normal fresh and
        //    no-op paths the executed statements are identical to before.
        $columnType = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insights' AND COLUMN_NAME = 'household_id'"
        )->fetchColumn();
        $isChar36 = strtolower((string) $columnType) === 'char(36)';

        $hasDedupColumn = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insights' AND COLUMN_NAME = 'dedup_household'"
        )->fetchColumn() > 0;

        $hasDedupIndex = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insights' AND INDEX_NAME = 'uq_insights_dedup'"
        )->fetchColumn() > 0;

        // Narrowing the base column requires dropping the stored generated
        // column (and the unique key over it) first.
        if (!$isChar36) {
            if ($hasDedupIndex) {
                $pdo->exec('ALTER TABLE insights DROP INDEX uq_insights_dedup');
                $hasDedupIndex = false;
            }
            if ($hasDedupColumn) {
                $pdo->exec('ALTER TABLE insights DROP COLUMN dedup_household');
                $hasDedupColumn = false;
            }

            $pdo->exec('ALTER TABLE insights MODIFY household_id CHAR(36) NULL');
        }

        // Rebuild the generated column, then the unique key over it, if
        // either is absent (covers the fresh path and a crash-resume).
        if (!$hasDedupColumn) {
            $pdo->exec(
                "ALTER TABLE insights ADD COLUMN dedup_household VARCHAR(66) GENERATED ALWAYS AS (COALESCE(household_id, '')) STORED"
            );
        }
        if (!$hasDedupIndex) {
            $pdo->exec('ALTER TABLE insights ADD UNIQUE KEY uq_insights_dedup (dedup_household, type, dedup_period)');
        }

        // 3) The ratified foreign key: RESTRICT both directions, never
        //    SET NULL (docs/10 D-056, D-057). InnoDB uses the existing
        //    idx_insights_household_status for the child-side index.
        $hasFk = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'insights'
               AND CONSTRAINT_NAME = 'fk_insights_household'"
        )->fetchColumn();

        if ($hasFk === 0) {
            $pdo->exec(
                'ALTER TABLE insights
                 ADD CONSTRAINT fk_insights_household FOREIGN KEY (household_id)
                 REFERENCES households (id) ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }
    },
];
