<?php

declare(strict_types=1);

/**
 * SPMS Automated Database Setup & Seeder Script.
 *
 * Runs all 31 schema migrations (creates all tables) and seeds rich demo
 * data for all 5 scenarios (32,000+ solar readings, 72 monthly bills, forecasts).
 *
 * Works in both:
 * 1. CLI Terminal: php scripts/setup-database.php
 * 2. Web Browser: http://your-domain.com/setup-database.php
 */

set_time_limit(600); // 10 minutes max execution time for high-density inserts
ini_set('memory_limit', '512M');

$isCli = (PHP_SAPI === 'cli');

function output(string $msg, bool $isError = false): void {
    global $isCli;
    if ($isCli) {
        if ($isError) {
            fwrite(STDERR, "[ERROR] " . $msg . PHP_EOL);
        } else {
            echo $msg . PHP_EOL;
        }
    } else {
        $color = $isError ? '#dc2626' : '#16a34a';
        echo "<div style='font-family: monospace; font-size: 13px; margin: 4px 0; color: {$color};'>" . htmlspecialchars($msg) . "</div>";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>SPMS Database Setup</title>";
    echo "<style>body{background:#0f172a;color:#f8fafc;padding:30px;font-family:system-ui,sans-serif;}h1{color:#38bdf8;}pre{background:#1e293b;padding:15px;border-radius:8px;overflow-x:auto;}</style></head><body>";
    echo "<h1>SPMS Automated Database Setup</h1><p>Creating database tables and seeding rich demo data...</p><hr style='border-color:#334155;margin:20px 0;'>";
}

$rootPath = dirname(__DIR__);
if (!file_exists($rootPath . '/vendor/autoload.php')) {
    // Check if called from public/
    $rootPath = dirname(__DIR__);
}

if (!file_exists($rootPath . '/vendor/autoload.php')) {
    output("Cannot find vendor/autoload.php. Run 'composer install' first.", true);
    exit(1);
}

// Check if demo mode was explicitly requested via CLI flag or query param
$requestedDemo = (
    in_array('--demo', $argv ?? [], true) ||
    (isset($_GET['mode']) && $_GET['mode'] === 'demo') ||
    strtolower((string) (getenv('SPMS_DATA_ENVIRONMENT') ?: '')) === 'demo'
);

if ($requestedDemo) {
    putenv('SPMS_DATA_ENVIRONMENT=demo');
}

// Auto-bridge .env if SPMS_DB_DEMO_DATABASE is missing
$envPath = $rootPath . '/.env';
if (!file_exists($envPath) && file_exists($rootPath . '/.env.example')) {
    copy($rootPath . '/.env.example', $envPath);
}

if (file_exists($envPath) && $requestedDemo) {
    $envContent = (string) file_get_contents($envPath);
    if (!str_contains($envContent, 'SPMS_DB_DEMO_DATABASE')) {
        $lines = explode("\n", $envContent);
        $parsed = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#') && strpos($line, '=') !== false) {
                [$k, $v] = explode('=', $line, 2);
                $parsed[trim($k)] = trim($v, "\"' \t\r\n");
            }
        }

        $host = $parsed['DB_HOST'] ?? '127.0.0.1';
        $port = $parsed['DB_PORT'] ?? '3306';
        $db = $parsed['DB_DATABASE'] ?? 'spms_demo';
        $user = $parsed['DB_USERNAME'] ?? 'root';
        $pass = $parsed['DB_PASSWORD'] ?? '';

        $bridge = "\n# SPMS Demo Database Isolation (Auto-configured by setup script)\n"
            . "SPMS_DB_DEMO_HOST={$host}\n"
            . "SPMS_DB_DEMO_PORT={$port}\n"
            . "SPMS_DB_DEMO_DATABASE={$db}\n"
            . "SPMS_DB_DEMO_USERNAME={$user}\n"
            . "SPMS_DB_DEMO_PASSWORD={$pass}\n";

        file_put_contents($envPath, $envContent . $bridge);
        output("  [Notice] Auto-bridged SPMS_DB_DEMO_* in .env using your database credentials.");
    }
}

require $rootPath . '/vendor/autoload.php';

use App\Support\Config;
Config::initialize($rootPath);

// Determine database credentials from .env
$envProfile = $requestedDemo ? 'demo' : (string) (Config::get('SPMS_DATA_ENVIRONMENT') ?: 'demo');
if ($envProfile === 'real') {
    $dbHost = (string) (Config::get('SPMS_DB_REAL_HOST') ?: Config::get('DB_HOST') ?: '127.0.0.1');
    $dbPort = (string) (Config::get('SPMS_DB_REAL_PORT') ?: Config::get('DB_PORT') ?: '3306');
    $dbName = (string) (Config::get('SPMS_DB_REAL_DATABASE') ?: Config::get('DB_DATABASE') ?: 'spms_real');
    $dbUser = (string) (Config::get('SPMS_DB_REAL_USERNAME') ?: Config::get('DB_USERNAME') ?: 'root');
    $dbPass = (string) (Config::get('SPMS_DB_REAL_PASSWORD') ?: Config::get('DB_PASSWORD') ?: '');
    $dbCharset = (string) (Config::get('SPMS_DB_REAL_CHARSET') ?: Config::get('DB_CHARSET') ?: 'utf8mb4');
} else {
    $dbHost = (string) (Config::get('SPMS_DB_DEMO_HOST') ?: Config::get('DB_HOST') ?: '127.0.0.1');
    $dbPort = (string) (Config::get('SPMS_DB_DEMO_PORT') ?: Config::get('DB_PORT') ?: '3306');
    $dbName = (string) (Config::get('SPMS_DB_DEMO_DATABASE') ?: Config::get('DB_DATABASE') ?: 'spms_demo');
    $dbUser = (string) (Config::get('SPMS_DB_DEMO_USERNAME') ?: Config::get('DB_USERNAME') ?: 'root');
    $dbPass = (string) (Config::get('SPMS_DB_DEMO_PASSWORD') ?: Config::get('DB_PASSWORD') ?: '');
    $dbCharset = (string) (Config::get('SPMS_DB_DEMO_CHARSET') ?: Config::get('DB_CHARSET') ?: 'utf8mb4');
}

output(">>> [1/4] Connecting to MySQL server ({$dbHost}:{$dbPort}) for database: '{$dbName}'...");

try {
    // 1. First connect without db to ensure database exists
    $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=%s', $dbHost, $dbPort, $dbCharset);
    $pdoRoot = new PDO($dsnNoDb, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    unset($pdoRoot);

    // 2. Connect directly to target database
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    output("  ✓ Connected to database: '{$dbName}'");
} catch (Throwable $e) {
    output("Database Connection Failed: " . $e->getMessage(), true);
    output("Check your .env settings for DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD.", true);
    exit(1);
}

// -----------------------------------------------------------------------------
// [2/4] Run All 31 Schema Migrations (Create All Tables)
// -----------------------------------------------------------------------------
output("\n>>> [2/4] Applying database migrations (creating all 31 tables)...");

$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id` VARCHAR(255) NOT NULL,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$migrationFiles = glob($rootPath . '/database/migrations/*.php') ?: [];
sort($migrationFiles);

$appliedStmt = $pdo->query('SELECT id FROM `migrations`');
$applied = array_flip($appliedStmt->fetchAll(PDO::FETCH_COLUMN));

$newlyAppliedCount = 0;
foreach ($migrationFiles as $file) {
    $migration = require $file;
    $id = $migration['id'] ?? basename($file, '.php');

    if (!isset($applied[$id])) {
        try {
            $pdo->beginTransaction();
            $migration['up']($pdo);
            $stmt = $pdo->prepare('INSERT INTO `migrations` (id) VALUES (:id)');
            $stmt->execute([':id' => $id]);
            $pdo->commit();
            output("  ✓ Applied migration: {$id}");
            $newlyAppliedCount++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            output("Failed applying migration '{$id}': " . $e->getMessage(), true);
            exit(1);
        }
    }
}

if ($newlyAppliedCount === 0) {
    output("  All 31 migrations are already up to date.");
} else {
    output("  ✓ Successfully created tables for {$newlyAppliedCount} migrations.");
}

// -----------------------------------------------------------------------------
// [3/4] Seed Rich Demo Data (High-Density Solar Readings, Bills, Scenarios)
// -----------------------------------------------------------------------------
output("\n>>> [3/4] Seeding rich demo scenarios, solar readings, and bills...");

// Include seeder classes
require_once $rootPath . '/database/seeders/DemoBillScenarioSeeder.php';
require_once $rootPath . '/database/seeders/DemoScenarioSeeder.php';

use Database\Seeders\DemoScenarioSeeder;

$passwords = [];
try {
    DemoScenarioSeeder::seed($pdo, $passwords, static function (string $msg): void {
        output("  " . $msg);
    });
} catch (Throwable $e) {
    output("Warning during base scenario seed: " . $e->getMessage());
}

// Now run rich generator (90 days of high density readings + 12 monthly bills)
function makeUuidV4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$stmt = $pdo->query("SELECT id, label, service_number FROM households WHERE label LIKE '%DEMO%' OR label LIKE '%Solar Surplus%' OR label LIKE '%Demo household%'");
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

$scenarioMap = [
    'SCEN-01' => ['match' => 'Solar Surplus', 'capacity_kw' => 3.0, 'peak_w' => 2850, 'curve_type' => 'surplus', 'station_id' => 'DEMO_STATION_SURPLUS_01', 'inverter_sn' => 'GW3000-DNS', 'tariff' => 'LT-1(A)', 'monthly_consumption' => 180],
    'SCEN-02' => ['match' => 'Persistent Grid Deficit', 'capacity_kw' => 3.0, 'peak_w' => 1350, 'curve_type' => 'deficit', 'station_id' => 'DEMO_STATION_DEFICIT_02', 'inverter_sn' => 'GW3000-DNS', 'tariff' => 'LT-1(B)', 'monthly_consumption' => 490],
    'SCEN-03' => ['match' => 'Sustained Solar Surplus', 'capacity_kw' => 5.0, 'peak_w' => 4800, 'curve_type' => 'overproduction', 'station_id' => 'DEMO_STATION_OVERPROD_03', 'inverter_sn' => 'GW5000-DNS', 'tariff' => 'LT-1(A)', 'monthly_consumption' => 220],
    'SCEN-04' => ['match' => 'Solar Decline', 'capacity_kw' => 4.0, 'peak_w' => 3600, 'curve_type' => 'decline', 'station_id' => 'DEMO_STATION_DECLINE_04', 'inverter_sn' => 'GW4000-DNS', 'tariff' => 'LT-1(B)', 'monthly_consumption' => 310],
    'SCEN-05' => ['match' => 'Consumption Surge', 'capacity_kw' => 3.5, 'peak_w' => 3200, 'curve_type' => 'surge', 'station_id' => 'DEMO_STATION_SURGE_05', 'inverter_sn' => 'GW3600-DNS', 'tariff' => 'LT-1(A)', 'monthly_consumption' => 260],
    'SCEN-MAIN' => ['match' => 'Demo household — full scenarios', 'capacity_kw' => 3.3, 'peak_w' => 3100, 'curve_type' => 'surplus', 'station_id' => 'DEMO_STATION_MAIN_00', 'inverter_sn' => 'GW3300-DNS', 'tariff' => 'LT-1(A)', 'monthly_consumption' => 210],
];

$hhIds = array_column($households, 'id');
if (!empty($hhIds)) {
    $inClause = "'" . implode("','", $hhIds) . "'";
    $pdo->exec("DELETE FROM solar_readings WHERE household_id IN ($inClause)");
    $pdo->exec("DELETE FROM bills WHERE household_id IN ($inClause)");

    // Ensure solar connection records exist for each
    foreach ($households as $hh) {
        $hid = $hh['id'];
        $label = $hh['label'];
        $cfg = null;
        foreach ($scenarioMap as $k => $c) {
            if (str_contains($label, $c['match'])) { $cfg = $c; break; }
        }
        if (!$cfg) continue;

        $pdo->prepare("
            INSERT INTO household_solar_connections (
                id, household_id, provider, station_id, inverter_sn, account_identifier,
                encrypted_credentials, connection_status, created_at, updated_at
            ) VALUES (
                :id, :hid, 'GOODWE_SEMS', :sid, :sn, 'demo.user@spms.local',
                'DEMO_SIMULATED_CREDENTIALS', 'CONNECTED', '2026-07-01 00:00:00', '2026-10-24 00:00:00'
            ) ON DUPLICATE KEY UPDATE station_id = VALUES(station_id), inverter_sn = VALUES(inverter_sn), connection_status = 'CONNECTED'
        ")->execute([':id' => makeUuidV4(), ':hid' => $hid, ':sid' => $cfg['station_id'], ':sn' => $cfg['inverter_sn']]);
    }

    // High density solar readings (90 days)
    $startDate = new DateTimeImmutable('2026-07-26 00:00:00', new DateTimeZone('UTC'));
    $endDate = new DateTimeImmutable('2026-10-24 18:30:00', new DateTimeZone('UTC'));

    $insertSql = "INSERT INTO solar_readings (
        id, household_id, station_id, inverter_sn, measurement_time, received_at,
        power_w, daily_generation_kwh, total_generation_kwh, pv_power_w,
        ac_voltage_v, ac_current_a, ac_frequency_hz, temperature_c,
        grid_power_w, consumption_power_w, inverter_status,
        data_nature, data_status, data_quality, sync_batch_id, created_at
    ) VALUES ";

    $totalInsertedReadings = 0;

    foreach ($households as $hh) {
        $hid = $hh['id'];
        $label = $hh['label'];
        $cfg = null;
        foreach ($scenarioMap as $k => $c) {
            if (str_contains($label, $c['match'])) { $cfg = $c; break; }
        }
        if (!$cfg) continue;

        $syncBatchId = makeUuidV4();
        $cursor = $startDate;
        $cumulativeKwh = 1450.0;
        $dailyKwh = 0.0;
        $lastDay = '';
        $rows = [];

        while ($cursor <= $endDate) {
            $dayStr = $cursor->format('Y-m-d');
            if ($dayStr !== $lastDay) {
                $lastDay = $dayStr;
                $dailyKwh = 0.0;
            }

            $hour = (int) $cursor->format('G');
            $min = (int) $cursor->format('i');
            $hourFraction = $hour + ($min / 60.0);
            $dayIndex = (int) $startDate->diff($cursor)->days;

            $powerW = 0.0;
            $quality = 'GOOD';
            $inverterStatus = 'WAITING';

            if ($hourFraction >= 5.75 && $hourFraction <= 18.25) {
                $x = ($hourFraction - 12.25) / 2.75;
                $bell = exp(-0.5 * $x * $x);
                $weatherFactor = 0.88 + 0.12 * sin($dayIndex * 0.45) + 0.05 * sin($hourFraction * 2.0);
                $effectivePeak = $cfg['peak_w'];

                if ($cfg['curve_type'] === 'decline') {
                    $effectivePeak *= (1.0 - (0.65 * ($dayIndex / 90.0)));
                    if ($dayIndex > 60 && ($dayIndex % 7 === 0) && ($hour === 11 || $hour === 14)) {
                        $quality = 'DEGRADED';
                    }
                } elseif ($cfg['curve_type'] === 'deficit') {
                    $effectivePeak *= 0.85;
                }

                $powerW = round(max(0.0, $effectivePeak * $bell * $weatherFactor), 1);
                if ($powerW > 25.0) {
                    $inverterStatus = 'NORMAL';
                }
            }

            $intervalHours = ($hourFraction >= 5.75 && $hourFraction <= 18.25) ? 0.25 : 1.0;
            $stepKwh = ($powerW / 1000.0) * $intervalHours;
            $dailyKwh += $stepKwh;
            $cumulativeKwh += $stepKwh;

            if ($cfg['curve_type'] === 'surplus') {
                $consumptionW = ($hour >= 8 && $hour <= 17) ? 350.0 + (sin($hourFraction) * 80.0) : 850.0;
            } elseif ($cfg['curve_type'] === 'deficit') {
                $consumptionW = ($hour >= 18 || $hour <= 7) ? 2800.0 : 1500.0;
            } elseif ($cfg['curve_type'] === 'overproduction') {
                $consumptionW = 400.0 + (sin($hourFraction) * 50.0);
            } elseif ($cfg['curve_type'] === 'surge') {
                $consumptionW = ($dayIndex > 65 && ($hour >= 11 && $hour <= 15)) ? 4250.0 : 600.0;
            } else {
                $consumptionW = 650.0;
            }

            $consumptionW = round($consumptionW, 1);
            $gridPowerW = round($consumptionW - $powerW, 1);
            $acVoltage = round(231.0 + 3.5 * sin($hourFraction * 0.8), 2);
            $acCurrent = $powerW > 0 ? round($powerW / $acVoltage, 2) : 0.0;
            $acFreq = round(50.00 + 0.06 * cos($hourFraction * 1.2), 2);
            $tempC = $powerW > 0 ? round(32.0 + 12.0 * ($powerW / max(1.0, $cfg['peak_w'])), 1) : 26.5;
            $pvPower = $powerW > 0 ? round($powerW * 1.025 + 10.0, 1) : 0.0;

            $timeStr = $cursor->format('Y-m-d H:i:s');
            $receivedStr = $cursor->modify('+1 minute')->format('Y-m-d H:i:s');

            $rows[] = sprintf(
                "('%s','%s','%s','%s','%s','%s',%.2f,%.3f,%.3f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,'%s','SIMULATED','FRESH','%s','%s','%s')",
                makeUuidV4(), $hid, $cfg['station_id'], $cfg['inverter_sn'],
                $timeStr, $receivedStr, $powerW, $dailyKwh, $cumulativeKwh, $pvPower,
                $acVoltage, $acCurrent, $acFreq, $tempC, $gridPowerW, $consumptionW,
                $inverterStatus, $quality, $syncBatchId, $timeStr
            );

            if (count($rows) >= 1000) {
                $pdo->exec($insertSql . implode(',', $rows));
                $totalInsertedReadings += count($rows);
                $rows = [];
            }

            $cursor = ($hourFraction >= 5.75 && $hourFraction <= 18.25)
                ? $cursor->modify('+15 minutes')
                : $cursor->modify('+1 hour');
        }

        if (!empty($rows)) {
            $pdo->exec($insertSql . implode(',', $rows));
            $totalInsertedReadings += count($rows);
        }
    }

    output("  ✓ Seeded {$totalInsertedReadings} high-density solar telemetry readings.");

    // Consecutive 12-month electricity bills
    $billInsertSql = "INSERT INTO bills (
        id, household_id, service_number, fingerprint, status, source,
        billing_period_start, billing_period_end,
        bill_date, due_date, consumption_kwh, previous_meter_reading, present_meter_reading,
        previous_solar_reading, present_solar_reading, solar_units_kwh, net_units_kwh,
        bill_amount, arrears, total_due, energy_charges, fixed_charges,
        customer_service_charges, other_charges, taxes_adjustments,
        utility_provider, tariff_category, meter_type, contracted_load,
        verified_at, created_at, updated_at
    ) VALUES ";

    $billRows = [];
    foreach ($households as $hh) {
        $hid = $hh['id'];
        $sn = $hh['service_number'] ?: '10002000300';
        $cfg = null;
        foreach ($scenarioMap as $k => $c) {
            if (str_contains($hh['label'], $c['match'])) { $cfg = $c; break; }
        }
        if (!$cfg) continue;

        $baseKwh = $cfg['monthly_consumption'];
        $meterRead = 12000;
        $solarRead = 4500;

        for ($m = 12; $m >= 1; $m--) {
            $pStart = (new DateTimeImmutable("first day of -{$m} month"))->format('Y-m-d');
            $pEnd = (new DateTimeImmutable("last day of -{$m} month"))->format('Y-m-d');
            $billDate = (new DateTimeImmutable("first day of -{$m} month"))->modify('+3 days')->format('Y-m-d');
            $dueDate = (new DateTimeImmutable("first day of -{$m} month"))->modify('+18 days')->format('Y-m-d');

            $seasonFactor = 1.0 + 0.25 * sin((12 - $m) * 0.52);
            $consumed = round($baseKwh * $seasonFactor);
            $solarGen = round($cfg['capacity_kw'] * 125 * (0.85 + 0.15 * cos((12 - $m) * 0.52)));
            if ($cfg['curve_type'] === 'overproduction') {
                $solarGen = round($solarGen * 1.3);
            } elseif ($cfg['curve_type'] === 'deficit') {
                $solarGen = round($solarGen * 0.7);
            }

            $netUnits = $consumed - $solarGen;
            $prevMeter = $meterRead;
            $meterRead += $consumed;
            $prevSolar = $solarRead;
            $solarRead += $solarGen;

            $fixedCharge = 65.0;
            $custCharge = 40.0;
            $ratePerUnit = $cfg['tariff'] === 'LT-1(A)' ? 6.20 : 8.10;
            $energyCharge = $netUnits > 0 ? round($netUnits * $ratePerUnit, 2) : 0.0;
            $tax = round(($energyCharge + $fixedCharge) * 0.09, 2);
            $totalDue = round($energyCharge + $fixedCharge + $custCharge + $tax, 2);
            $fingerprint = hash('sha256', $hid . $pStart . $pEnd . $sn);

            $billRows[] = sprintf(
                "('%s','%s','%s','%s','VERIFIED','MANUAL_ENTRY','%s','%s','%s','%s',%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,0.00,%.2f,%.2f,%.2f,%.2f,0.00,%.2f,'APCPDCL / Southern Power Distribution','%s','THREE_PHASE_BI_DIRECTIONAL',%.1f,'%s','%s','%s')",
                makeUuidV4(), $hid, $sn, $fingerprint, $pStart, $pEnd, $billDate, $dueDate,
                $consumed, $prevMeter, $meterRead, $prevSolar, $solarRead, $solarGen, $netUnits,
                $totalDue, $totalDue, $energyCharge, $fixedCharge, $custCharge, $tax,
                $cfg['tariff'], $cfg['capacity_kw'],
                $billDate . ' 10:00:00', $billDate . ' 10:00:00', $billDate . ' 10:00:00'
            );
        }
    }

    if (!empty($billRows)) {
        $pdo->exec($billInsertSql . implode(',', $billRows));
        output("  ✓ Seeded " . count($billRows) . " verified monthly electricity bills.");
    }
}

// -----------------------------------------------------------------------------
// [4/4] Summary & Verification
// -----------------------------------------------------------------------------
output("\n>>> [4/4] Final Verification of Database Contents:\n");
$tables = ['accounts', 'households', 'solar_readings', 'bills', 'household_forecasts', 'insights', 'recommendations', 'migrations'];
$total = 0;
foreach ($tables as $t) {
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $total += $count;
        output(sprintf("  %-25s : %d rows", $t, $count));
    } catch (Throwable) {
        output(sprintf("  %-25s : (not found)", $t));
    }
}

output("\n======================================================================");
output("✓ DATABASE SETUP & SEEDING COMPLETED SUCCESSFULLY! ({$total} total records)");
output("======================================================================");

if (!$isCli) {
    echo "<p style='color:#38bdf8;margin-top:20px;font-weight:bold;'>All tables created and data seeded! You can now browse your website at <a href='/' style='color:#4ade80;'>/ (Home Page)</a>.</p>";
    echo "<p style='color:#94a3b8;font-size:12px;'>Security Notice: You may delete or rename <code>public/setup-database.php</code> when done.</p>";
    echo "</body></html>";
}
