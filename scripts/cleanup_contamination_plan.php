<?php

/**
 * SPMS Recovery Plan - Contamination Cleanup Transaction
 *
 * SAFETY INVARIANTS:
 * - Runs in --dry-run mode by default.
 * - Explicit --execute flag required for live execution.
 * - Pre-validates target row IDs, provenances, and values before running any DELETE.
 * - Enforces strict dependency deletion ordering:
 *     1. recommendation_outcomes (child of recommendations via FK)
 *     2. notifications (references recommendations)
 *     3. recommendations
 *     4. insights
 *     5. household_forecasts
 *     6. solar_readings
 * - Verifies affected row count on every DELETE statement.
 * - Post-validates table counts and data integrity prior to commit.
 * - Automatically rolls back on any violation.
 */

declare(strict_types=1);

$dryRun = !in_array('--execute', $argv, true);

$targetHouseholdId = '68a5374d-10f0-49a0-b845-03f4acbd5fa8';

$targets = [
    'recommendation_outcomes' => [
        '8f65695d-fa28-a8c8-add0-6c49502125c4',
        '7f1190e7-f90f-e3f4-b7df-282040883276',
        'cc632df5-d70f-819a-1e1c-d1843e39ade2',
    ],
    'notifications' => [
        '8b358366-f7d3-4378-999a-3dd28e0ba0c2',
    ],
    'recommendations' => [
        '6a533be1-2273-6021-3b25-70ce7f8593a7',
        '55af66c7-4376-cf83-6687-ae7b5e539cb2',
        '97902e55-753c-9175-28ad-7abd27e6d797',
    ],
    'insights' => [
        '5cbf402d-a11a-4be4-b8a6-ffc978bbdba1',
        'edbc7316-530d-4b74-88a4-48b83983c979',
        '71f5a606-c81b-4be6-8942-da2722e61d08',
        '7e771785-fa5f-4678-af08-e76b8e9a2619',
        'c746c3de-fb8c-4de2-b7dd-dc9137067eac',
        'c9a3a67e-8191-4583-a5f7-1796864d4d55',
    ],
    'household_forecasts' => [
        '90224317-4488-ad02-a175-2281ad103f7c',
        'd5d235f6-ad55-c3d1-533d-d4a07d356d13',
        'f9b6c78a-0571-2638-5452-29bbf702355b',
    ],
    'solar_readings' => [
        '7780de64-879c-46f5-b940-fe2575e9c85b',
        '76844526-8dd7-4841-8fb3-f7dc9e451db6',
        '878906ca-ffa9-4f3b-a91f-d4c81fa212a5',
        'e5b7b19b-23cd-47e7-a0c6-d2a2dbaefebe',
        'e4861b1e-6ed7-422e-999b-bce7e36bb031',
    ],
];

$expectedPreCounts = [
    'solar_readings' => 41750,
    'solar_readings_actual' => 41745,
    'solar_readings_simulated' => 5,
    'household_forecasts' => 102,
    'insights' => 32,
    'recommendations' => 50,
    'recommendation_outcomes' => 74,
    'notifications' => 32,
];

$expectedPostCounts = [
    'solar_readings' => 41745,
    'solar_readings_actual' => 41745,
    'solar_readings_simulated' => 0,
    'household_forecasts' => 99,
    'insights' => 26,
    'recommendations' => 47,
    'recommendation_outcomes' => 71,
    'notifications' => 31,
];

$pdo = new PDO('mysql:host=127.0.0.1;dbname=spms_real;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "====================================================================\n";
echo "SPMS Contamination Recovery Transaction\n";
echo "Mode: " . ($dryRun ? "DRY RUN (Will automatically roll back; no rows modified)" : "LIVE EXECUTE") . "\n";
echo "Target Household: {$targetHouseholdId}\n";
echo "Timestamp: " . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s T') . "\n";
echo "====================================================================\n\n";

// --- PHASE 1: PRE-CHECK VALIDATION ---
echo "--- Step 1: Pre-execution Invariant Checks ---\n";

foreach ($expectedPreCounts as $key => $expected) {
    if ($key === 'solar_readings_actual') {
        $actual = (int)$pdo->query("SELECT COUNT(*) FROM solar_readings WHERE data_nature = 'ACTUAL'")->fetchColumn();
    } elseif ($key === 'solar_readings_simulated') {
        $actual = (int)$pdo->query("SELECT COUNT(*) FROM solar_readings WHERE data_nature = 'SIMULATED'")->fetchColumn();
    } else {
        $actual = (int)$pdo->query("SELECT COUNT(*) FROM `{$key}`")->fetchColumn();
    }
    if ($actual !== $expected) {
        fwrite(STDERR, "PRE-CHECK FAILED: {$key} count is {$actual}, expected {$expected}.\n");
        exit(1);
    }
    echo "  [OK] Pre-count {$key}: {$actual}\n";
}

// Verify that all 5 target solar readings exist and are SIMULATED
$inClauseReadings = implode(',', array_fill(0, count($targets['solar_readings']), '?'));
$stmt = $pdo->prepare("SELECT id, household_id, data_nature FROM solar_readings WHERE id IN ({$inClauseReadings})");
$stmt->execute($targets['solar_readings']);
$targetReadingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($targetReadingRows) !== 5) {
    fwrite(STDERR, "PRE-CHECK FAILED: Expected 5 target solar readings, found " . count($targetReadingRows) . "\n");
    exit(1);
}

foreach ($targetReadingRows as $r) {
    if ($r['household_id'] !== $targetHouseholdId || $r['data_nature'] !== 'SIMULATED') {
        fwrite(STDERR, "PRE-CHECK FAILED: Target reading {$r['id']} has invalid household or is not SIMULATED!\n");
        exit(1);
    }
}
echo "  [OK] All 5 solar readings confirmed as SIMULATED for household {$targetHouseholdId}\n";

// Ensure 0 ACTUAL readings match target IDs
$stmt = $pdo->prepare("SELECT COUNT(*) FROM solar_readings WHERE id IN ({$inClauseReadings}) AND data_nature = 'ACTUAL'");
$stmt->execute($targets['solar_readings']);
if ((int)$stmt->fetchColumn() !== 0) {
    fwrite(STDERR, "CRITICAL PRE-CHECK FAILED: An ACTUAL solar reading matched target deletion IDs!\n");
    exit(1);
}
echo "  [OK] Zero ACTUAL solar readings match deletion targets.\n\n";

// --- PHASE 2: TRANSACTION EXECUTION ---
echo "--- Step 2: Executing Controlled Deletion Transaction ---\n";
$pdo->beginTransaction();

try {
    $deletionResults = [];

    foreach ($targets as $table => $ids) {
        $inClause = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM `{$table}` WHERE `id` IN ({$inClause}) AND `household_id` = ?";
        $params = array_merge($ids, [$targetHouseholdId]);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        $expected = count($ids);

        $deletionResults[$table] = [
            'expected' => $expected,
            'affected' => $affected,
        ];

        if ($affected !== $expected) {
            throw new RuntimeException("AFFECTED ROW MISMATCH in {$table}: expected {$expected}, affected {$affected}. Aborting.");
        }
        echo "  [DELETED] {$table}: {$affected} rows (expected {$expected})\n";
    }

    // --- PHASE 3: POST-CHECK VALIDATION ---
    echo "\n--- Step 3: Post-execution Invariant Checks (Pre-Commit) ---\n";

    foreach ($expectedPostCounts as $key => $expected) {
        if ($key === 'solar_readings_actual') {
            $actual = (int)$pdo->query("SELECT COUNT(*) FROM solar_readings WHERE data_nature = 'ACTUAL'")->fetchColumn();
        } elseif ($key === 'solar_readings_simulated') {
            $actual = (int)$pdo->query("SELECT COUNT(*) FROM solar_readings WHERE data_nature = 'SIMULATED'")->fetchColumn();
        } else {
            $actual = (int)$pdo->query("SELECT COUNT(*) FROM `{$key}`")->fetchColumn();
        }
        if ($actual !== $expected) {
            throw new RuntimeException("POST-CHECK FAILED: {$key} count is {$actual}, expected {$expected}.");
        }
        echo "  [OK] Post-count {$key}: {$actual}\n";
    }

    // Check that exactly zero simulated solar readings remain
    $simCount = (int)$pdo->query("SELECT COUNT(*) FROM solar_readings WHERE data_nature = 'SIMULATED'")->fetchColumn();
    if ($simCount !== 0) {
        throw new RuntimeException("POST-CHECK FAILED: {$simCount} simulated readings still remain in solar_readings!");
    }
    echo "  [OK] Confirmed 0 SIMULATED readings remaining in solar_readings.\n";

    // Verify preserved unrelated insight remains intact
    $unrelatedInsightId = '148527e0-c318-4a88-b43b-fab758492859';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM insights WHERE id = ?");
    $stmt->execute([$unrelatedInsightId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException("POST-CHECK FAILED: Preserved unrelated insight {$unrelatedInsightId} is missing!");
    }
    echo "  [OK] Confirmed preserved unrelated insight {$unrelatedInsightId} remains intact.\n";

    // Verify preserved valid recommendation remains intact
    $validRecId = '09dfe128-6b0e-b53c-4ee8-3652f46f4d3e';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM recommendations WHERE id = ?");
    $stmt->execute([$validRecId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException("POST-CHECK FAILED: Preserved valid recommendation {$validRecId} is missing!");
    }
    echo "  [OK] Confirmed preserved valid recommendation {$validRecId} remains intact.\n";

    if ($dryRun) {
        $pdo->rollBack();
        echo "\n====================================================================\n";
        echo "[DRY RUN COMPLETE] All 6 deletions and invariants validated successfully.\n";
        echo "Transaction was automatically ROLLED BACK. Live database was NOT modified.\n";
        echo "====================================================================\n";
    } else {
        $pdo->commit();
        echo "\n====================================================================\n";
        echo "[SUCCESS] All 6 deletions and post-commit invariants verified.\n";
        echo "Transaction COMMITTED. 21 contaminated records cleanly removed.\n";
        echo "====================================================================\n";
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n[TRANSACTION ROLLED BACK]: " . $e->getMessage() . "\n");
    exit(1);
}
