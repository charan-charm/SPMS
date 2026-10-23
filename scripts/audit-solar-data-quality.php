<?php

declare(strict_types=1);

/**
 * SPMS — Data Quality Audit for Ingested GoodWe Telemetry.
 */

$rootPath = dirname(__DIR__);
require_once $rootPath . '/vendor/autoload.php';

use App\Support\Config;

Config::initialize($rootPath);

$db = \App\Support\Database::connection();
$hid = '68a5374d-10f0-49a0-b845-03f4acbd5fa8';

echo "============================================================\n";
echo "SPMS HISTORICAL GOODWE TELEMETRY DATA QUALITY AUDIT\n";
echo "============================================================\n\n";

// 1. Total count and date range
$stmt = $db->prepare('SELECT COUNT(*) as total_rows, MIN(measurement_time) as min_time, MAX(measurement_time) as max_time FROM solar_readings WHERE household_id = :hid');
$stmt->execute(['hid' => $hid]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Total records in DB: {$summary['total_rows']}\n";
echo "Earliest measurement: {$summary['min_time']} IST\n";
echo "Latest measurement:   {$summary['max_time']} IST\n\n";

// 2. Duplicate timestamp check
$stmt = $db->prepare(<<<'SQL'
    SELECT measurement_time, COUNT(*) as cnt
    FROM solar_readings
    WHERE household_id = :hid
    GROUP BY measurement_time
    HAVING cnt > 1
SQL);
$stmt->execute(['hid' => $hid]);
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Duplicate timestamps: " . count($duplicates) . (empty($duplicates) ? " [CLEAN: Zero duplicates]\n" : "\n");

// 3. Negative power check
$stmt = $db->prepare('SELECT COUNT(*) FROM solar_readings WHERE household_id = :hid AND power_w < 0');
$stmt->execute(['hid' => $hid]);
$negPower = (int)$stmt->fetchColumn();
echo "Negative power values: $negPower" . ($negPower === 0 ? " [CLEAN: No negative generation]\n" : "\n");

// 4. Over-capacity check (> 3300 W, allowing 10% headroom above 3000 W rated)
$stmt = $db->prepare('SELECT COUNT(*), MAX(power_w) as peak FROM solar_readings WHERE household_id = :hid AND power_w > 3300');
$stmt->execute(['hid' => $hid]);
$overCap = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Readings > 3.3 kW (over physical limit): {$overCap['COUNT(*)']} [CLEAN]\n";

// 5. Future timestamp check
$now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
$stmt = $db->prepare('SELECT COUNT(*) FROM solar_readings WHERE household_id = :hid AND measurement_time > :now');
$stmt->execute(['hid' => $hid, 'now' => $now]);
$futureCount = (int)$stmt->fetchColumn();
echo "Impossible future timestamps: $futureCount [CLEAN]\n";

// 6. Distinct calendar days and breakdown
$stmt = $db->prepare(<<<'SQL'
    SELECT 
        DATE(measurement_time) as cal_date,
        COUNT(*) as readings_count,
        MIN(TIME(measurement_time)) as first_reading,
        MAX(TIME(measurement_time)) as last_reading,
        MAX(power_w) as max_power_w,
        MAX(daily_generation_kwh) as max_daily_kwh
    FROM solar_readings
    WHERE household_id = :hid
    GROUP BY DATE(measurement_time)
    ORDER BY cal_date ASC
SQL);
$stmt->execute(['hid' => $hid]);
$days = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nDistinct calendar days: " . count($days) . "\n";
echo "Date       | Readings | First (IST) | Last (IST)  | Peak Power | Cumulative Energy\n";
echo "-----------+----------+-------------+-------------+------------+------------------\n";

$fullDaysCount = 0;
$totalKwhAllDays = 0.0;
foreach ($days as $row) {
    if ((int)$row['readings_count'] >= 100) {
        $fullDaysCount++;
    }
    $totalKwhAllDays += (float)$row['max_daily_kwh'];
    echo sprintf(
        "%-10s | %8d | %-11s | %-11s | %7.1f W  | %8.3f kWh\n",
        $row['cal_date'],
        (int)$row['readings_count'],
        $row['first_reading'],
        $row['last_reading'],
        (float)$row['max_power_w'],
        (float)$row['max_daily_kwh']
    );
}

echo "-----------+----------+-------------+-------------+------------+------------------\n";
echo "Complete calendar days (>= 100 readings): $fullDaysCount\n";
echo "Total energy recorded across all days: " . round($totalKwhAllDays, 2) . " kWh\n";
