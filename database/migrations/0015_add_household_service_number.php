<?php

declare(strict_types=1);

/**
 * D-071 — Household service-number registration (ownership anchor).
 *
 * An electricity bill becomes an authoritative household bill only when
 * the service/USC number printed on it matches the service number the
 * household has registered. This adds exactly that one profile field —
 * no other household profile data is introduced (the minimal correct
 * change per the ownership design).
 *
 * The column stores the CANONICAL form produced by
 * App\Domain\Bill\ServiceNumber (deterministic, non-aggressive: case
 * folded, decorative separators removed); NULL honestly means
 * "not configured" — ownership then cannot be validated and the bill
 * pipeline says so (NEEDS_REVIEW + quality flag), never a silent pass.
 *
 * Re-run-safe (DDL implicit-commits in MariaDB): the presence check
 * follows the 0007 precedent and is scoped to the CURRENT catalog, so
 * the same migration set applies identically to spms_real, spms_demo
 * and spms_test (docs/10 D-071).
 */

return [
    'id' => '0015_add_household_service_number',
    'up' => static function (PDO $pdo): void {
        $exists = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'households'
                AND COLUMN_NAME = 'service_number'"
        );
        $exists->execute();

        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec(
                "ALTER TABLE households
                     ADD COLUMN service_number VARCHAR(32) NULL AFTER location_label"
            );
        }
    },
];
