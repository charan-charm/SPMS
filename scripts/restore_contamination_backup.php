<?php

/**
 * SPMS Recovery Tool - Restoration Script
 * Restores records backed up in storage/backups/solar_contamination_backup_20261023.json.
 *
 * Usage:
 *   php scripts/restore_contamination_backup.php --dry-run
 *   php scripts/restore_contamination_backup.php --execute
 */

declare(strict_types=1);

$dryRun = !in_array('--execute', $argv, true);
$backupFile = __DIR__ . '/../storage/backups/solar_contamination_backup_20261023.json';

if (!file_exists($backupFile)) {
    fwrite(STDERR, "ERROR: Backup file not found: {$backupFile}\n");
    exit(1);
}

$rawJson = file_get_contents($backupFile);
$backupData = json_decode($rawJson, true);
if (!is_array($backupData) || !isset($backupData['records'])) {
    fwrite(STDERR, "ERROR: Invalid backup format in {$backupFile}\n");
    exit(1);
}

$dbHost = '127.0.0.1';
$dbName = 'spms_real';
$dbUser = 'root';
$dbPass = '';

$pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Proper dependency order for insertion:
// 1. Independent parent records: solar_readings, household_forecasts, insights, recommendations
// 2. Dependent records: recommendation_outcomes, notifications
$restoreOrder = [
    'solar_readings',
    'household_forecasts',
    'insights',
    'recommendations',
    'recommendation_outcomes',
    'notifications',
];

echo "======================================================\n";
echo "SPMS Contamination Restoration Procedure\n";
echo "Mode: " . ($dryRun ? "DRY-RUN (no database changes)" : "EXECUTE (restoring records)") . "\n";
echo "Target DB: {$dbName}\n";
echo "Backup File: {$backupFile}\n";
echo "======================================================\n\n";

$pdo->beginTransaction();

try {
    $restoredCount = 0;
    foreach ($restoreOrder as $table) {
        $rows = $backupData['records'][$table] ?? [];
        echo "Processing {$table} (" . count($rows) . " records)...\n";

        foreach ($rows as $row) {
            $cols = array_keys($row);
            $colList = implode('`, `', $cols);
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO `{$table}` (`{$colList}`) VALUES ({$placeholders})";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($row));
            $restoredCount++;
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "\n[DRY RUN] {$restoredCount} records successfully simulated for restoration. Transaction rolled back.\n";
    } else {
        $pdo->commit();
        echo "\n[SUCCESS] {$restoredCount} records successfully restored. Transaction committed.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n[FAILED] Restoration aborted and rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
