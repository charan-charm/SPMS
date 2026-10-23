<?php

declare(strict_types=1);

/**
 * SPMS — Automatic Intelligence & Recommendation Orchestration Worker.
 *
 * Runs scheduled deterministic analysis over telemetry, forecasts, weather, and bills:
 * - Ingests weather forecasts and evaluates atmospheric planning windows (Rule 8).
 * - Identifies solar generation patterns, telemetry freshness, and hardware status (Rule 9).
 * - Detects consumption and billing trends from verified records (Rule 10).
 * - Generates, prioritizes, and deduplicates actionable recommendations (Rule 13, 14).
 * - Expires obsolete forecast items and manages lifecycle states (Rule 14, 15).
 * - Preserves strict REAL vs DEMO data isolation (Rule 16).
 *
 * Usage:
 *   php scripts/orchestrate-intelligence.php                  # Single orchestration pass across all households
 *   php scripts/orchestrate-intelligence.php --household=<id> # Target specific household
 *   php scripts/orchestrate-intelligence.php --daemon         # Run continuously with interval
 *   php scripts/orchestrate-intelligence.php --daemon --interval=300
 *   php scripts/orchestrate-intelligence.php --environment=demo
 */

$rootPath = dirname(__DIR__);
require_once $rootPath . '/vendor/autoload.php';

use App\Domain\Intelligence\IntelligenceOrchestrator;
use App\Support\Config;

$options = getopt('', [
    'daemon',
    'interval:',
    'household:',
    'environment:',
    'verbose',
]);

$environment = isset($options['environment']) && is_string($options['environment'])
    ? strtolower(trim($options['environment']))
    : null;

if ($environment !== null && in_array($environment, ['real', 'demo', 'test'], true)) {
    putenv('SPMS_DATA_ENVIRONMENT=' . $environment);
    $_ENV['SPMS_DATA_ENVIRONMENT'] = $environment;
}

Config::initialize($rootPath);

$isDaemon = isset($options['daemon']);
$targetHousehold = isset($options['household']) && is_string($options['household'])
    ? trim($options['household'])
    : null;

$intervalSeconds = (int) ($options['interval'] ?? Config::get('INTELLIGENCE_INTERVAL_SECONDS', '300'));
if ($intervalSeconds < 15) {
    $intervalSeconds = 300;
}

$verbose = isset($options['verbose']);

// Concurrency Control: Ensure only one orchestrator process executes at a time
$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spms_orchestrator_' . (Config::get('SPMS_DATA_ENVIRONMENT', 'real')) . '.lock';
$lockHandle = fopen($lockFile, 'c+');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo sprintf(
        '[%s] Another SPMS intelligence orchestrator process is already running. Exiting safely.%s',
        date('Y-m-d H:i:s'),
        PHP_EOL
    );
    exit(0);
}

register_shutdown_function(static function () use ($lockHandle, $lockFile): void {
    if ($lockHandle !== false) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockFile);
    }
});

$orchestrator = new IntelligenceOrchestrator();

echo sprintf(
    '[%s] SPMS Automatic Intelligence Orchestrator started (env: %s, daemon: %s, interval: %ds)%s',
    date('Y-m-d H:i:s'),
    Config::get('SPMS_DATA_ENVIRONMENT', 'real'),
    $isDaemon ? 'yes' : 'no',
    $intervalSeconds,
    PHP_EOL
);

do {
    $passStart = microtime(true);
    echo sprintf('[%s] Starting intelligence orchestration pass...%s', date('Y-m-d H:i:s'), PHP_EOL);

    try {
        $result = $orchestrator->orchestrate(
            targetHouseholdId: $targetHousehold,
            referenceTime: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            triggeredBy: $isDaemon ? 'DAEMON' : 'CLI_CRON'
        );

        echo sprintf(
            '  [%s] Status: %s | Evaluated: %d household(s) | Insights: +%d new, %d updated, %d expired | Recs: +%d new, %d updated%s',
            date('Y-m-d H:i:s'),
            $result['status'],
            $result['households_evaluated'],
            $result['insights_created'],
            $result['insights_updated'],
            $result['insights_expired'],
            $result['recommendations_created'],
            $result['recommendations_updated'],
            PHP_EOL
        );

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $hid => $msg) {
                echo sprintf('  [%s] Household %s ERROR: %s%s', date('Y-m-d H:i:s'), substr((string)$hid, 0, 8), $msg, PHP_EOL);
            }
        }
    } catch (Throwable $e) {
        echo sprintf('  [%s] FATAL ORCHESTRATION ERROR: %s%s', date('Y-m-d H:i:s'), $e->getMessage(), PHP_EOL);
    }

    $duration = round(microtime(true) - $passStart, 2);
    echo sprintf('[%s] Completed orchestration pass in %.2fs%s', date('Y-m-d H:i:s'), $duration, PHP_EOL);

    if ($isDaemon) {
        echo sprintf('[%s] Sleeping for %d seconds until next pass...%s', date('Y-m-d H:i:s'), $intervalSeconds, PHP_EOL);
        sleep($intervalSeconds);
    }
} while ($isDaemon);

exit(0);
