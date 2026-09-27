<?php

declare(strict_types=1);

/**
 * SPMS D1 — Controlled DEMO dataset seeder (spms_demo ONLY).
 *
 * Safety rails:
 *  - refuses to run unless the process data environment resolves to
 *    'demo' (the trusted server-side selection; this script itself is
 *    CLI-only and sets it explicitly);
 *  - refuses to run without --confirm=SEED-DEMO;
 *  - refuses to run against spms_real;
 *  - pins mail to the file sink — registration can never send real email;
 *  - all analytical inputs/results explicitly maintain data_nature = 'SIMULATED';
 *  - runs real domain pipelines (B2/B3 bills, F4 energy balance, F5 energy cost, F6 insights);
 *  - coordinates DemoBillScenarioSeeder (DEMO-01..08) and DemoScenarioSeeder (SCEN-01..05).
 *
 * Usage:
 *   php scripts/seed-demo.php --confirm=SEED-DEMO
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

putenv('SPMS_DATA_ENVIRONMENT=demo');

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';
require_once $rootPath . '/database/seeders/DemoBillScenarioSeeder.php';
require_once $rootPath . '/database/seeders/DemoScenarioSeeder.php';

use App\Support\Config;
use App\Support\Database;
use Database\Seeders\DemoBillScenarioSeeder;
use Database\Seeders\DemoScenarioSeeder;

Config::initialize($rootPath);

if ((string) Config::get('SPMS_DATA_ENVIRONMENT') !== 'demo') {
    fwrite(STDERR, "Refusing: this seeder only runs against the demo data environment.\n");
    exit(1);
}

$confirm = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
    }
}
if ($confirm !== 'SEED-DEMO') {
    fwrite(STDERR, "Refusing to seed. Pass exactly --confirm=SEED-DEMO\n");
    exit(1);
}

// Mail never leaves the machine; the DB stays the configured demo one.
Config::overrideForTesting([
    'MAIL_TRANSPORT' => 'file',
    'BREVO_ALLOW_REAL_SEND' => '0',
    'MAIL_FILE_DIR' => sys_get_temp_dir() . '/spms-demo-seed-mail-' . bin2hex(random_bytes(4)),
    'BILL_TMP_DIR' => sys_get_temp_dir() . '/spms-demo-seed-tmp-' . bin2hex(random_bytes(4)),
    'BILL_TEMP_RETENTION_HOURS' => '24',
    'MISTRAL_API_KEY' => '', // the injected fake handler is the only "provider"
]);

@mkdir(Config::get('MAIL_FILE_DIR'), 0777, true);

$databaseName = (string) Config::get('DB_DATABASE');
echo "[seed-demo] target database: {$databaseName}\n";

// Guard against targeting spms_real
if ($databaseName === 'spms_real' || str_contains($databaseName, 'real')) {
    fwrite(STDERR, "Refusing: target database is {$databaseName}. Demo seeding cannot touch real data.\n");
    exit(1);
}

$pdo = Database::connection();

// Verify active database directly via SQL
$activeDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "[seed-demo] verified active SQL database: {$activeDb}\n";
if ($activeDb === 'spms_real' || str_contains($activeDb, 'real')) {
    fwrite(STDERR, "SECURITY ALERT: SELECT DATABASE() returned {$activeDb}. Aborting immediately.\n");
    exit(1);
}

// Idempotency guard: a re-seed into a non-empty demo DB is refused so
// scenario ids stay predictable and no partial mixture accumulates.
$existing = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
if ($existing > 0) {
    fwrite(STDERR, "Refusing: the demo database already has {$existing} accounts. Reset it first:\n"
        . "  php database/migrate.php --environment=demo --fresh --confirm=DROP:{$databaseName}\n");
    exit(1);
}

$passwords = [];

echo "\n--- Seeding DEMO-01..08 Bill Scenarios (DemoBillScenarioSeeder) ---\n";
$billScenarios = DemoBillScenarioSeeder::seed($pdo, $passwords, static function (string $msg): void {
    echo $msg . "\n";
});

foreach ($billScenarios as $label => $r) {
    $flagList = $r['flags'] === [] ? '' : '  flags=' . implode(',', $r['flags']);
    echo sprintf("  %-52s status=%-14s%s\n", $label, $r['status'], $flagList);
}

echo "\n--- Seeding SCEN-01..05 Intelligence & Energy Scenarios (DemoScenarioSeeder) ---\n";
$energyScenarios = DemoScenarioSeeder::seed($pdo, $passwords, static function (string $msg): void {
    echo $msg . "\n";
});

foreach ($energyScenarios as $scenId => $r) {
    $types = implode(', ', $r['insight_types']);
    echo sprintf("  %-8s account=%-34s insights=%d [%s]\n", $scenId, $r['account'], $r['insights_count'], $types);
}

// ---------------------------------------------------------------------
// Evidence print + credentials
// ---------------------------------------------------------------------

$counts = [];
foreach (['accounts', 'households', 'bills', 'insights', 'bill_extraction_drafts', 'bill_field_provenance', 'bill_validation_results', 'bill_quality_flags', 'bill_temp_documents'] as $t) {
    $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
}
echo "\n[seed-demo] row counts: " . json_encode($counts) . "\n";

// A verified bill would be B5 territory; confirm none exists.
$verified = (int) $pdo->query("SELECT COUNT(*) FROM bills WHERE status = 'VERIFIED'")->fetchColumn();
echo "[seed-demo] VERIFIED bills in demo DB: {$verified} (must be 0 — verification is B5)\n";

echo "\nDemo accounts (printed once, local development only — not stored anywhere else):\n";
foreach ($passwords as $email => $password) {
    echo "  {$email}  /  {$password}\n";
}
echo "\nRun the demo backend with:  \$env:SPMS_DATA_ENVIRONMENT='demo'; php -S 127.0.0.1:8081 -t public public/index.php\n";
echo "and the React dev proxy at:  \$env:VITE_DEV_PROXY_TARGET='http://127.0.0.1:8081'; npm run dev\n";
