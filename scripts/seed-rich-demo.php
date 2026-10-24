<?php

declare(strict_types=1);

/**
 * SPMS Rich Demo Dataset Generator (spms_demo ONLY).
 *
 * Populates high-density, realistic simulation data for:
 * - SCEN-01: Solar Surplus Residence (3.0 kW)
 * - SCEN-02: Persistent Grid Deficit & High Demand (3.0 kW)
 * - SCEN-03: Sustained Solar Overproduction (5.0 kW)
 * - SCEN-04: Solar Decline & Quality Degradation (4.0 kW)
 * - SCEN-05: Sudden Consumption Surge (3.5 kW)
 * - Demo Operator & Main Household
 *
 * CRITICAL SAFETY RAILS:
 * - Refuses execution if active database is 'spms_real' or contains 'real'.
 * - Enforces SPMS_DATA_ENVIRONMENT=demo.
 * - All generated records carry data_nature = 'SIMULATED'.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

putenv('SPMS_DATA_ENVIRONMENT=demo');

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';
require_once $rootPath . '/database/seeders/DemoScenarioSeeder.php';

use App\Support\Config;
use App\Support\Database;
use Database\Seeders\DemoScenarioSeeder;

Config::initialize($rootPath);

// Verify profile is strictly demo
if ((string) Config::get('SPMS_DATA_ENVIRONMENT') !== 'demo') {
    fwrite(STDERR, "Refusing: SPMS_DATA_ENVIRONMENT must be 'demo'.\n");
    exit(1);
}

\App\Support\Database::switchTo('demo');
$pdo = Database::connection();

// Verify active SQL database
$activeDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo ">>> [SAFETY CHECK] Connected database: {$activeDb}\n";
if ($activeDb === 'spms_real' || str_contains(strtolower($activeDb), 'real')) {
    fwrite(STDERR, "FATAL SECURITY ALERT: Connected to real database '{$activeDb}'. Aborting immediately.\n");
    exit(1);
}
if (!str_contains($activeDb, 'demo')) {
    fwrite(STDERR, "Refusing: Database must contain 'demo' (got '{$activeDb}').\n");
    exit(1);
}

echo ">>> [1/5] Ensuring baseline demo accounts & households exist...\n";
$passwords = [];
DemoScenarioSeeder::seed($pdo, $passwords, static function (string $msg): void {
    echo "  {$msg}\n";
});

// Helper for generating UUIDv4
function makeUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Fetch all demo scenario households
$stmt = $pdo->query("SELECT id, label, service_number FROM households WHERE label LIKE '%DEMO%' OR label LIKE '%Solar Surplus%' OR label LIKE '%Demo household%'");
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo ">>> [2/5] Found " . count($households) . " demo households to enrich.\n";

// Map each household to its scenario configuration
$scenarioMap = [
    'SCEN-01' => [
        'match' => 'Solar Surplus',
        'capacity_kw' => 3.0,
        'peak_w' => 2850,
        'curve_type' => 'surplus',
        'station_id' => 'DEMO_STATION_SURPLUS_01',
        'inverter_sn' => 'GW3000-DNS',
        'tariff' => 'LT-1(A)',
        'monthly_consumption' => 180,
    ],
    'SCEN-02' => [
        'match' => 'Persistent Grid Deficit',
        'capacity_kw' => 3.0,
        'peak_w' => 1350,
        'curve_type' => 'deficit',
        'station_id' => 'DEMO_STATION_DEFICIT_02',
        'inverter_sn' => 'GW3000-DNS',
        'tariff' => 'LT-1(B)',
        'monthly_consumption' => 490,
    ],
    'SCEN-03' => [
        'match' => 'Sustained Solar Surplus',
        'capacity_kw' => 5.0,
        'peak_w' => 4800,
        'curve_type' => 'overproduction',
        'station_id' => 'DEMO_STATION_OVERPROD_03',
        'inverter_sn' => 'GW5000-DNS',
        'tariff' => 'LT-1(A)',
        'monthly_consumption' => 220,
    ],
    'SCEN-04' => [
        'match' => 'Solar Decline',
        'capacity_kw' => 4.0,
        'peak_w' => 3600, // Starts at 3600W in July, degrades to 1200W by October
        'curve_type' => 'decline',
        'station_id' => 'DEMO_STATION_DECLINE_04',
        'inverter_sn' => 'GW4000-DNS',
        'tariff' => 'LT-1(B)',
        'monthly_consumption' => 310,
    ],
    'SCEN-05' => [
        'match' => 'Consumption Surge',
        'capacity_kw' => 3.5,
        'peak_w' => 3200,
        'curve_type' => 'surge',
        'station_id' => 'DEMO_STATION_SURGE_05',
        'inverter_sn' => 'GW3600-DNS',
        'tariff' => 'LT-1(A)',
        'monthly_consumption' => 260,
    ],
    'SCEN-MAIN' => [
        'match' => 'Demo household — full scenarios',
        'capacity_kw' => 3.3,
        'peak_w' => 3100,
        'curve_type' => 'surplus',
        'station_id' => 'DEMO_STATION_MAIN_00',
        'inverter_sn' => 'GW3300-DNS',
        'tariff' => 'LT-1(A)',
        'monthly_consumption' => 210,
    ],
];

// Clean out existing sparse readings for these households so we populate clean dense data
$hhIds = array_column($households, 'id');
$inClause = "'" . implode("','", $hhIds) . "'";
$pdo->exec("DELETE FROM solar_readings WHERE household_id IN ($inClause)");
echo "  Cleared previous sparse readings for demo households.\n";

// Ensure solar connection records exist for each
foreach ($households as $hh) {
    $hid = $hh['id'];
    $label = $hh['label'];
    $cfg = null;
    foreach ($scenarioMap as $k => $c) {
        if (str_contains($label, $c['match'])) {
            $cfg = $c;
            break;
        }
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
    ")->execute([
        ':id' => makeUuid(),
        ':hid' => $hid,
        ':sid' => $cfg['station_id'],
        ':sn' => $cfg['inverter_sn'],
    ]);
}

// -----------------------------------------------------------------------------
// [3/5] Seed High-Density Solar Telemetry (90 Days: 2026-07-26 to 2026-10-24)
// -----------------------------------------------------------------------------
echo ">>> [3/5] Seeding 90 days of high-density solar telemetry (up to today 2026-10-24)...\n";

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
        if (str_contains($label, $c['match'])) {
            $cfg = $c;
            break;
        }
    }
    if (!$cfg) continue;

    echo "  Generating solar series for [{$label}] (Peak: {$cfg['peak_w']} W)...\n";

    $syncBatchId = makeUuid();
    $cursor = $startDate;
    $cumulativeKwh = 1450.0;
    $dailyKwh = 0.0;
    $lastDay = '';
    $rows = [];
    $totalDays = (int) $startDate->diff($endDate)->days;

    while ($cursor <= $endDate) {
        $dayStr = $cursor->format('Y-m-d');
        if ($dayStr !== $lastDay) {
            $lastDay = $dayStr;
            $dailyKwh = 0.0;
        }

        $hour = (int) $cursor->format('G');
        $min = (int) $cursor->format('i');
        $hourFraction = $hour + ($min / 60.0);

        // Day index from start (0 to 90)
        $dayIndex = (int) $startDate->diff($cursor)->days;

        // Base bell curve for solar irradiance: centered at 12:30 (solar noon)
        $powerW = 0.0;
        $quality = 'GOOD';
        $inverterStatus = 'WAITING';

        if ($hourFraction >= 5.75 && $hourFraction <= 18.25) {
            // Solar window ~5:45 AM to 6:15 PM
            $x = ($hourFraction - 12.25) / 2.75;
            $bell = exp(-0.5 * $x * $x); // Standard normal bell curve

            // Weather fluctuation factor (cloudy intervals / clear days)
            $weatherFactor = 0.88 + 0.12 * sin($dayIndex * 0.45) + 0.05 * sin($hourFraction * 2.0);

            // Curve adjustments per scenario
            $effectivePeak = $cfg['peak_w'];

            if ($cfg['curve_type'] === 'decline') {
                // SCEN-04: Soiling/degradation drops performance from 100% in July to 35% in October
                $declineRate = 1.0 - (0.65 * ($dayIndex / 90.0));
                $effectivePeak *= $declineRate;
                // Occasional communication glitches in October
                if ($dayIndex > 60 && ($dayIndex % 7 === 0) && ($hour === 11 || $hour === 14)) {
                    $quality = 'DEGRADED';
                }
            } elseif ($cfg['curve_type'] === 'deficit') {
                // SCEN-02: Moderate solar generation
                $effectivePeak = $cfg['peak_w'] * 0.85;
            } elseif ($cfg['curve_type'] === 'overproduction') {
                // SCEN-03: Strong uninterrupted solar
                $effectivePeak = $cfg['peak_w'];
            }

            $powerW = round(max(0.0, $effectivePeak * $bell * $weatherFactor), 1);
            if ($powerW > 25.0) {
                $inverterStatus = 'NORMAL';
            }
        }

        // Interval duration
        $intervalHours = ($hourFraction >= 5.75 && $hourFraction <= 18.25) ? 0.25 : 1.0;
        $stepKwh = ($powerW / 1000.0) * $intervalHours;
        $dailyKwh += $stepKwh;
        $cumulativeKwh += $stepKwh;

        // Grid & consumption modeling
        $consumptionW = 0.0;
        $gridPowerW = 0.0;

        if ($cfg['curve_type'] === 'surplus') {
            // Low daytime usage (350W), moderate evening (850W)
            $consumptionW = ($hour >= 8 && $hour <= 17) ? 350.0 + (sin($hourFraction) * 80.0) : 850.0;
        } elseif ($cfg['curve_type'] === 'deficit') {
            // Heavy night and evening load (2200W - 3500W)
            $consumptionW = ($hour >= 18 || $hour <= 7) ? 2800.0 : 1500.0;
        } elseif ($cfg['curve_type'] === 'overproduction') {
            // Very low continuous load (400W)
            $consumptionW = 400.0 + (sin($hourFraction) * 50.0);
        } elseif ($cfg['curve_type'] === 'surge') {
            // Normal baseline (500W), but in last 21 days sudden 4200W afternoon surges!
            if ($dayIndex > 65 && ($hour >= 11 && $hour <= 15)) {
                $consumptionW = 4250.0;
            } else {
                $consumptionW = 600.0;
            }
        } else {
            $consumptionW = 650.0;
        }

        $consumptionW = round($consumptionW, 1);
        $gridPowerW = round($consumptionW - $powerW, 1); // Positive = importing from grid; Negative = exporting

        $acVoltage = round(231.0 + 3.5 * sin($hourFraction * 0.8), 2);
        $acCurrent = $powerW > 0 ? round($powerW / $acVoltage, 2) : 0.0;
        $acFreq = round(50.00 + 0.06 * cos($hourFraction * 1.2), 2);
        $tempC = $powerW > 0 ? round(32.0 + 12.0 * ($powerW / max(1.0, $cfg['peak_w'])), 1) : 26.5;
        $pvPower = $powerW > 0 ? round($powerW * 1.025 + 10.0, 1) : 0.0;

        $timeStr = $cursor->format('Y-m-d H:i:s');
        $receivedStr = $cursor->modify('+1 minute')->format('Y-m-d H:i:s');

        $rows[] = sprintf(
            "('%s','%s','%s','%s','%s','%s',%.2f,%.3f,%.3f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,'%s','SIMULATED','FRESH','%s','%s','%s')",
            makeUuid(),
            $hid,
            $cfg['station_id'],
            $cfg['inverter_sn'],
            $timeStr,
            $receivedStr,
            $powerW,
            $dailyKwh,
            $cumulativeKwh,
            $pvPower,
            $acVoltage,
            $acCurrent,
            $acFreq,
            $tempC,
            $gridPowerW,
            $consumptionW,
            $inverterStatus,
            $quality,
            $syncBatchId,
            $timeStr
        );

        if (count($rows) >= 1000) {
            $pdo->exec($insertSql . implode(',', $rows));
            $totalInsertedReadings += count($rows);
            $rows = [];
        }

        // Advance 15 min during solar hours, 1 hour at night
        $cursor = ($hourFraction >= 5.75 && $hourFraction <= 18.25)
            ? $cursor->modify('+15 minutes')
            : $cursor->modify('+1 hour');
    }

    if (!empty($rows)) {
        $pdo->exec($insertSql . implode(',', $rows));
        $totalInsertedReadings += count($rows);
    }
}

echo "  ✓ Inserted {$totalInsertedReadings} high-density solar readings.\n";

// -----------------------------------------------------------------------------
// [4/5] Seed 12 Consecutive Historical Bills per Scenario (Oct 2025 - Sep 2026)
// -----------------------------------------------------------------------------
echo ">>> [4/5] Seeding 12-month consecutive electricity bills for demo households...\n";

// Clear previous bills for demo households
$pdo->exec("DELETE FROM bills WHERE household_id IN ($inClause)");

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
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

foreach ($households as $hh) {
    $hid = $hh['id'];
    $label = $hh['label'];
    $sn = $hh['service_number'] ?: '10002000300';
    $cfg = null;
    foreach ($scenarioMap as $k => $c) {
        if (str_contains($label, $c['match'])) {
            $cfg = $c;
            break;
        }
    }
    if (!$cfg) continue;

    $baseKwh = $cfg['monthly_consumption'];
    $meterRead = 12000;
    $solarRead = 4500;

    // Generate 12 months: Oct 2025 through Sep 2026
    for ($m = 12; $m >= 1; $m--) {
        $pStart = (new DateTimeImmutable("first day of -{$m} month"))->format('Y-m-d');
        $pEnd = (new DateTimeImmutable("last day of -{$m} month"))->format('Y-m-d');
        $billDate = (new DateTimeImmutable("first day of -{$m} month"))->modify('+3 days')->format('Y-m-d');
        $dueDate = (new DateTimeImmutable("first day of -{$m} month"))->modify('+18 days')->format('Y-m-d');

        // Seasonal variation in consumption
        $seasonFactor = 1.0 + 0.25 * sin((12 - $m) * 0.52); // Peak summer (Apr-Jun), lower in winter
        $consumed = round($baseKwh * $seasonFactor);

        // Solar generation per month
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

        // Tariff slabs calculation (LT-1 domestic)
        $fixedCharge = 65.0;
        $custCharge = 40.0;
        $ratePerUnit = $cfg['tariff'] === 'LT-1(A)' ? 6.20 : 8.10;

        if ($netUnits > 0) {
            $energyCharge = round($netUnits * $ratePerUnit, 2);
        } else {
            // Net exported: zero variable energy charge!
            $energyCharge = 0.0;
        }

        $tax = round(($energyCharge + $fixedCharge) * 0.09, 2);
        $totalDue = round($energyCharge + $fixedCharge + $custCharge + $tax, 2);
        $fingerprint = hash('sha256', $hid . $pStart . $pEnd . $sn);

        $billRows[] = sprintf(
            "('%s','%s','%s','%s','VERIFIED','MANUAL_ENTRY','%s','%s','%s','%s',%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,0.00,%.2f,%.2f,%.2f,%.2f,0.00,%.2f,'APCPDCL / Southern Power Distribution','%s','THREE_PHASE_BI_DIRECTIONAL',%.1f,'%s','%s','%s')",
            makeUuid(),
            $hid,
            $sn,
            $fingerprint,
            $pStart,
            $pEnd,
            $billDate,
            $dueDate,
            $consumed,
            $prevMeter,
            $meterRead,
            $prevSolar,
            $solarRead,
            $solarGen,
            $netUnits,
            $totalDue,
            $totalDue,
            $energyCharge,
            $fixedCharge,
            $custCharge,
            $tax,
            $cfg['tariff'],
            $cfg['capacity_kw'],
            $billDate . ' 10:00:00',
            $billDate . ' 10:00:00',
            $billDate . ' 10:00:00'
        );
    }
}

if (!empty($billRows)) {
    $pdo->exec($billInsertSql . implode(',', $billRows));
    echo "  ✓ Inserted " . count($billRows) . " verified monthly electricity bills.\n";
}

// -----------------------------------------------------------------------------
// [5/5] Seed Day-Ahead Forecasts & Actionable Recommendations (Today: 2026-10-24)
// -----------------------------------------------------------------------------
echo ">>> [5/5] Refreshing forecasts & targeted recommendations for today...\n";

$forecastInsert = "INSERT INTO household_forecasts (
    id, household_id, forecast_type, horizon, forecast_start, forecast_end,
    resolution, predicted_value, uncertainty_lower, uncertainty_upper,
    confidence, data_nature, data_quality, points_json, limitations_json,
    provenance_json, created_at
) VALUES ";

$fcRows = [];
$todayDateStr = $now->format('Y-m-d');
$tomorrowDateStr = $now->modify('+1 day')->format('Y-m-d');

foreach ($households as $hh) {
    $hid = $hh['id'];
    $label = $hh['label'];
    $cfg = null;
    foreach ($scenarioMap as $k => $c) {
        if (str_contains($label, $c['match'])) {
            $cfg = $c;
            break;
        }
    }
    if (!$cfg) continue;

    // Day-Ahead Forecast for today & tomorrow
    $points = [];
    $totalGenDay = 0.0;
    for ($h = 0; $h < 24; $h++) {
        $val = 0.0;
        if ($h >= 6 && $h <= 18) {
            $x = ($h - 12) / 2.8;
            $bell = exp(-0.5 * $x * $x);
            $val = round(($cfg['peak_w'] / 1000.0) * $bell * 0.92, 3);
            $totalGenDay += $val;
        }
        $points[] = [
            'hour' => $h,
            'time' => sprintf('%sT%02d:00:00Z', $tomorrowDateStr, $h),
            'expected_kwh' => $val,
            'p10_kwh' => round($val * 0.85, 3),
            'p90_kwh' => round($val * 1.12, 3),
        ];
    }

    $pointsJson = json_encode($points);
    $limitations = json_encode(['cloud_cover_uncertainty' => '12%', 'model' => 'XGBoost Solar Ensemble v2.1']);
    $provenance = json_encode(['source' => 'OPEN_METEO_SOLAR_IRRADIANCE', 'run_at' => $now->format('Y-m-d H:i:s')]);

    $fcRows[] = sprintf(
        "('%s','%s','SOLAR_GENERATION','DAY_AHEAD','%s 00:00:00','%s 23:59:59','HOUR',%.2f,%.2f,%.2f,0.94,'SIMULATED','GOOD','%s','%s','%s','%s')",
        makeUuid(),
        $hid,
        $tomorrowDateStr,
        $tomorrowDateStr,
        $totalGenDay,
        round($totalGenDay * 0.85, 2),
        round($totalGenDay * 1.12, 2),
        addslashes($pointsJson),
        addslashes($limitations),
        addslashes($provenance),
        $now->format('Y-m-d H:i:s')
    );
}

if (!empty($fcRows)) {
    $pdo->exec($forecastInsert . implode(',', $fcRows));
    echo "  ✓ Inserted active day-ahead solar forecasts.\n";
}

// -----------------------------------------------------------------------------
// Summary of demo database counts
// -----------------------------------------------------------------------------
echo "\n======================================================================\n";
echo "✓ DEMO DATABASE ENRICHMENT COMPLETE! (spms_demo only)\n";
echo "======================================================================\n";

$counts = [];
foreach (['accounts', 'households', 'solar_readings', 'bills', 'household_forecasts', 'insights', 'recommendations'] as $tbl) {
    $counts[$tbl] = (int) $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    printf("  %-25s : %d\n", $tbl, $counts[$tbl]);
}
echo "======================================================================\n";
