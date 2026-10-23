<?php

declare(strict_types=1);

/**
 * SPMS — Solar Telemetry Historical Backfill Script.
 *
 * Usage:
 *   php scripts/backfill-solar.php --household=<id> --days=35
 *   php scripts/backfill-solar.php --days=35
 */

$rootPath = dirname(__DIR__);
require_once $rootPath . '/vendor/autoload.php';

use App\Domain\Solar\SolarService;
use App\Repositories\HouseholdRepository;
use App\Repositories\SolarConnectionRepository;
use App\Support\Config;

Config::initialize($rootPath);

$options = getopt('', ['household:', 'days:', 'from:', 'to:', 'refresh']);

$forceRefresh = isset($options['refresh']);
$specificHousehold = isset($options['household']) && is_string($options['household'])
    ? trim($options['household'])
    : null;

$fromDate = isset($options['from']) && is_string($options['from']) ? trim($options['from']) : null;
$toDate = isset($options['to']) && is_string($options['to']) ? trim($options['to']) : null;

$days = isset($options['days']) && is_numeric($options['days'])
    ? max(1, (int)$options['days'])
    : 35;

$solarService = new SolarService();
$connectionsRepo = new SolarConnectionRepository();

if ($specificHousehold !== null) {
    $connections = [];
    $conn = $connectionsRepo->findByHouseholdId($specificHousehold);
    if ($conn !== null) {
        $connections[] = $conn;
    } else {
        fwrite(STDERR, "Household {$specificHousehold} has no solar connection configured.\n");
        exit(1);
    }
} else {
    $connections = $connectionsRepo->findAllActiveConnections();
}

$modeDesc = $fromDate !== null
    ? sprintf('range %s to %s', $fromDate, $toDate ?? 'today')
    : sprintf('past %d days', $days);

echo sprintf(
    "[%s] Starting GoodWe Solar Historical Backfill (%s, connections: %d)...\n",
    date('Y-m-d H:i:s'),
    $modeDesc,
    count($connections)
);

foreach ($connections as $conn) {
    $hid = (string)$conn['household_id'];
    $stationId = (string)$conn['station_id'];
    echo sprintf("\n--- Backfilling Household %s (Station: %s) ---\n", $hid, $stationId);

    if ($fromDate !== null) {
        $startDt = new DateTimeImmutable($fromDate);
        $endDt = $toDate !== null ? new DateTimeImmutable($toDate) : new DateTimeImmutable('now');
        $res = $solarService->backfillHistoricalDateRange($hid, $startDt, $endDt, $forceRefresh);
    } else {
        $res = $solarService->backfillHistoricalTelemetry($hid, $days, $forceRefresh);
    }

    echo sprintf(
        "Result: %s | Days Ingested: %d | Days Skipped: %d | Total Readings Inserted: %d\nRange: %s to %s\n",
        $res['status'],
        $res['days_ingested'],
        $res['days_skipped'],
        $res['readings_inserted'],
        $res['earliest_date'] ?? 'N/A',
        $res['latest_date'] ?? 'N/A'
    );

    foreach ($res['daily_summary'] as $d) {
        if ($d['status'] === 'INGESTED') {
            echo sprintf("  %s: Ingested %3d points (Peak: %7.1f W, Total: %6.3f kWh)\n", $d['date'], $d['reading_count'], (float)$d['max_power_w'], (float)$d['daily_kwh']);
        } elseif ($d['status'] === 'SKIPPED_ALREADY_EXISTS') {
            echo sprintf("  %s: Skipped (already has %d points)\n", $d['date'], $d['reading_count']);
        } else {
            echo sprintf("  %s: %s %s\n", $d['date'], $d['status'], $d['error'] ?? '');
        }
    }
}

echo sprintf("\n[%s] Backfill complete.\n", date('Y-m-d H:i:s'));
