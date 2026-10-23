<?php

declare(strict_types=1);

/**
 * SPMS O4 — Scheduled Solar Telemetry Ingestion Worker (docs/10 D-059, D-060, D-061).
 *
 * Usage:
 *   php scripts/collect-solar.php                  # Run a single ingestion pass across all active connections
 *   php scripts/collect-solar.php --household=<id> # Run ingestion for a specific household
 *   php scripts/collect-solar.php --daemon         # Run continuously with configured interval
 *   php scripts/collect-solar.php --daemon --interval=60
 */

$rootPath = dirname(__DIR__);
require_once $rootPath . '/vendor/autoload.php';

use App\Domain\Solar\SolarService;
use App\Repositories\SolarConnectionRepository;
use App\Support\Config;

$rawOptions = getopt('', ['daemon', 'interval:', 'household:', 'env:', 'verbose']);
$targetEnv = isset($rawOptions['env']) && is_string($rawOptions['env']) ? strtolower(trim($rawOptions['env'])) : (getenv('SPMS_DATA_ENVIRONMENT') ?: 'real');
if (in_array($targetEnv, ['real', 'demo', 'test'], true)) {
    putenv("SPMS_DATA_ENVIRONMENT={$targetEnv}");
    $_ENV['SPMS_DATA_ENVIRONMENT'] = $targetEnv;
}

Config::initialize($rootPath);

// Prevent overlapping runs using non-blocking lock file
$lockDir = $rootPath . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . "/solar_collection_{$targetEnv}.lock";
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo sprintf('[%s] Another solar ingestion process is already running for %s environment. Exiting.%s', date('Y-m-d H:i:s'), $targetEnv, PHP_EOL);
    exit(0);
}

register_shutdown_function(static function () use (&$lockHandle): void {
    if ($lockHandle !== null && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});

$options = $rawOptions;
$isDaemon = isset($options['daemon']);
$specificHousehold = isset($options['household']) && is_string($options['household'])
    ? trim($options['household'])
    : null;

$intervalSeconds = (int) ($options['interval'] ?? Config::get('SOLAR_COLLECTION_INTERVAL_SECONDS', '300'));
if ($intervalSeconds < 10) {
    $intervalSeconds = 300;
}

$verbose = isset($options['verbose']);

$solarService = new SolarService();
$connectionsRepo = new SolarConnectionRepository();

echo sprintf(
    '[%s] SPMS Solar Ingestion Worker started (env: %s, daemon: %s, interval: %ds)%s',
    date('Y-m-d H:i:s'),
    $targetEnv,
    $isDaemon ? 'yes' : 'no',
    $intervalSeconds,
    PHP_EOL
);

do {
    $start = microtime(true);

    if ($specificHousehold !== null) {
        $connections = [];
        $conn = $connectionsRepo->findByHouseholdId($specificHousehold);
        if ($conn !== null) {
            $connections[] = $conn;
        } else {
            fwrite(STDERR, "Household {$specificHousehold} has no solar connection configured.\n");
        }
    } else {
        $connections = $connectionsRepo->findAllActiveConnections();
    }

    $count = count($connections);
    echo sprintf('[%s] Polling %d active solar connection(s)...%s', date('Y-m-d H:i:s'), $count, PHP_EOL);

    $successCount = 0;
    $errorCount = 0;

    foreach ($connections as $conn) {
        $householdId = (string) $conn['household_id'];
        $stationId = (string) $conn['station_id'];
        $acc = (string) $conn['account_identifier'];

        try {
            $snapshot = $solarService->syncHouseholdTelemetry($householdId);

            if ($snapshot !== null) {
                ++$successCount;
                echo sprintf(
                    '  [%s] Household %s (Station %s, %s): %s W, %.3f kWh today, status %s%s',
                    date('Y-m-d H:i:s'),
                    substr($householdId, 0, 8),
                    $stationId,
                    $acc,
                    number_format($snapshot->powerW(), 2),
                    $snapshot->dailyGenerationKwh(),
                    $snapshot->inverterStatus(),
                    PHP_EOL
                );
            }
        } catch (Throwable $e) {
            ++$errorCount;
            echo sprintf(
                '  [%s] Household %s ERROR: %s%s',
                date('Y-m-d H:i:s'),
                substr($householdId, 0, 8),
                $e->getMessage(),
                PHP_EOL
            );
        }
    }

    $duration = round(microtime(true) - $start, 2);
    echo sprintf(
        '[%s] Completed pass in %.2fs. Success: %d, Errors: %d%s',
        date('Y-m-d H:i:s'),
        $duration,
        $successCount,
        $errorCount,
        PHP_EOL
    );

    if ($isDaemon) {
        sleep($intervalSeconds);
    }
} while ($isDaemon);

exit(0);
