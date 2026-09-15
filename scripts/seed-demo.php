<?php

declare(strict_types=1);

/**
 * D-071 §S — controlled DEMO dataset seeder (spms_demo ONLY).
 *
 * Safety rails:
 *  - refuses to run unless the process data environment resolves to
 *    'demo' (the trusted server-side selection; this script itself is
 *    CLI-only and sets it explicitly);
 *  - refuses to run without --confirm=SEED-DEMO;
 *  - pins mail to the file sink — registration can never send real email;
 *  - the Mistral HTTP handler is a deterministic fake: the PROVIDER
 *    RESPONSE is fixture, but every layer that produces SPMS truth
 *    (B2 intake, temp-document lifecycle, BillAnnotationNormalizer,
 *    MistralBillValidator, ownership checks, evidence gating,
 *    persistence, lifecycle) is the real application pipeline.
 *    No business logic is bypassed and no final row is hand-inserted.
 *
 * Scenarios (docs/09 §10 + the D-071 task): DEMO-01..08.
 *
 * Usage:
 *   php scripts/seed-demo.php --confirm=SEED-DEMO
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

putenv('SPMS_DATA_ENVIRONMENT=demo');

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillTempDocument;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillTempDocumentRepository;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\Config;
use App\Support\Database;

$rootPath = dirname(__DIR__);
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

// Idempotency guard: a re-seed into a non-empty demo DB is refused so
// scenario ids stay predictable and no partial mixture accumulates.
$existing = (int) Database::connection()->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
if ($existing > 0) {
    fwrite(STDERR, "Refusing: the demo database already has {$existing} accounts. Reset it first:\n"
        . "  php database/migrate.php --environment=demo --fresh --confirm=DROP:{$databaseName}\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Fixture annotation payloads (provider-response-shaped; values are
// clearly illustrative demo data). Sections mirror billAnnotationSchema().
// ---------------------------------------------------------------------

$baseBill = static function (array $overrides = []): array {
    $annotation = [
        'document_assessment' => ['status' => 'VALID', 'reason' => 'clear demo bill', 'confidence' => 0.99],
        'billing_period' => ['start' => '2026-08-01', 'end' => '2026-09-01', 'confidence' => 0.95],
        'consumption' => ['consumption_kwh' => 145, 'confidence' => 0.98],
        'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460, 'confidence' => 0.99],
        'solar' => [
            'previous_solar_reading' => 1878, 'present_solar_reading' => 2200,
            'solar_units_kwh' => 322, 'net_units_kwh' => -177, 'confidence' => 0.97,
        ],
        'cost' => [
            'bill_amount' => 180, 'arrears' => -105, 'total_due' => 75, 'energy_charges' => null,
            'fixed_charges' => 30, 'customer_service_charges' => 25, 'other_charges' => 45,
            'taxes_adjustments' => 108.56, 'confidence' => 0.9,
        ],
        'context' => [
            'utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)',
            'meter_type' => 'SMART SOLAR', 'contracted_load' => '3',
            'service_number' => '1000-2000-300', 'confidence' => 0.95,
        ],
    ];
    foreach ($overrides as $section => $value) {
        $annotation[$section] = $value;
    }
    return $annotation;
};

$emptySections = [
    'billing_period' => ['start' => null, 'end' => null, 'confidence' => null],
    'consumption' => ['consumption_kwh' => null, 'confidence' => null],
    'meter_evidence' => ['previous_meter_reading' => null, 'present_meter_reading' => null, 'confidence' => null],
    'solar' => ['previous_solar_reading' => null, 'present_solar_reading' => null, 'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null],
    'cost' => ['bill_amount' => null, 'arrears' => null, 'total_due' => null, 'energy_charges' => null, 'fixed_charges' => null, 'customer_service_charges' => null, 'other_charges' => null, 'taxes_adjustments' => null, 'confidence' => null],
    'context' => ['utility_provider' => null, 'tariff_category' => null, 'meter_type' => null, 'contracted_load' => null, 'service_number' => null, 'confidence' => null],
];

$ocrBody = static function (array $annotation): string {
    return (string) json_encode([
        'model' => 'mistral-ocr-demo-fixture',
        'pages' => [[
            'index' => 0,
            'markdown' => 'DEMO SAMPLE PAGE — not a real bill',
            'images' => [],
            'tables' => [],
        ]],
        'document_annotation' => json_encode($annotation),
        'usage_info' => ['input_tokens' => 0, 'output_tokens' => 0],
    ]);
};

$fakeOcr = static fn (string $body): MistralOcrClient => new MistralOcrClient(
    'mk_FAKE-DEMO-NEVER-REAL',
    'mistral-ocr-demo-fixture',
    static fn (string $method, string $url, array $options): array => ['status' => 200, 'body' => $body]
);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

$passwords = [];

$makeAccount = static function (string $localPart, string $displayName) use (&$passwords): Account {
    $password = bin2hex(random_bytes(12)) . 'Aa1!';
    $email = $localPart . '@demo.spms.local';
    $service = new AccountService();
    $service->register($email, $password, '10.255.255.254');

    $dir = (string) Config::get('MAIL_FILE_DIR');
    $files = glob($dir . '/*.eml') ?: [];
    sort($files);
    $token = null;
    foreach (array_reverse($files) as $file) {
        $content = (string) file_get_contents($file);
        if (str_contains($content, 'Verify your email') && preg_match('/\b([0-9a-f]{64})\b/', $content, $m) === 1) {
            $token = $m[1];
            break;
        }
    }
    if ($token === null) {
        throw new RuntimeException("no verification token for {$email}");
    }
    $account = $service->verifyEmail($token);

    // Server-side DEMO_TEST mode assignment (trusted CLI seed only;
    // registration itself can never choose a mode — D-049 stays intact).
    Database::connection()
        ->prepare("UPDATE accounts SET mode = 'DEMO_TEST' WHERE id = :id")
        ->execute(['id' => $account->id()]);

    $passwords[$email] = $password;
    return $account;
};

$makeHousehold = static function (Account $account, string $label, ?string $serviceNumber): string {
    $hh = new HouseholdService();
    $id = $hh->createForAccount($account, $label, 'Asia/Kolkata', 'DEMO — synthetic', new DateTimeImmutable('now', new DateTimeZone('UTC')))['household']->id();
    if ($serviceNumber !== null) {
        $hh->updateProfile($account, ['service_number' => $serviceNumber], new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
    return $id;
};

$runBill = static function (Account $account, array $annotation) use ($fakeOcr, $ocrBody): array {
    $src = sys_get_temp_dir() . '/spms-demo-src-' . bin2hex(random_bytes(5)) . '.png';
    file_put_contents($src, "\x89PNG\r\n\x1a\n" . random_bytes(400));

    $upload = BillUpload::fromFilesSpec(
        ['name' => 'demo-bill.png', 'type' => 'application/octet-stream', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => 1],
        static fn (string $p): bool => is_file($p)
    );
    $result = (new BillUploadIntakeService(new BillTempDocumentRepository(), new LocalPrivateBillStorage(), new HouseholdService()))
        ->accept($account, $upload, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    @unlink($src);

    $processed = (new BillProcessingService(
        new BillService(),
        new BillTempDocumentRepository(),
        new LocalPrivateBillStorage(),
        $fakeOcr($ocrBody($annotation))
    ))->process($account, $result['bill']->id());

    return [
        'bill_id' => $result['bill']->id(),
        'status' => $processed['bill']->status(),
        'flags' => array_map(static fn ($f): string => $f->code(), $processed['quality_flags']),
    ];
};

// ---------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------

$scenarios = [];

$main = $makeAccount('demo.main', 'Demo Main');
$mainHh = $makeHousehold($main, 'Demo household — full scenarios', '1000-2000-300');

// DEMO-01: normal valid bill, service MATCH, meter/solar/net all PASS.
$scenarios['DEMO-01 normal valid (service match, all PASS)'] = $runBill($main, $baseBill());

// DEMO-02: net conflict — +177 extracted, expected -177 (test-02 shape).
$scenarios['DEMO-02 net conflict (+177 vs expected -177)'] = $runBill($main, $baseBill([
    'solar' => array_merge($baseBill()['solar'], ['net_units_kwh' => 177]),
]));

// DEMO-03: missing solar evidence — solar/net NOT_CHECKED.
$scenarios['DEMO-03 missing solar (NOT_CHECKED)'] = $runBill($main, $baseBill([
    'solar' => ['previous_solar_reading' => null, 'present_solar_reading' => null, 'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null],
    'context' => ['utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)', 'meter_type' => 'ANALOG', 'contracted_load' => '1', 'service_number' => '1000-2000-300', 'confidence' => 0.9],
]));

// DEMO-04: wrong document — logo-like annotation.
$scenarios['DEMO-04 wrong document (NOT_A_BILL)'] = $runBill($main, $baseBill(
    ['document_assessment' => ['status' => 'NOT_A_BILL', 'reason' => 'brand logo, not an electricity bill', 'confidence' => 0.98]] + $emptySections
));

// DEMO-05: service mismatch — the bill carries ANOTHER demo household's number.
$other = $makeAccount('demo.other', 'Demo Other');
$otherHh = $makeHousehold($other, 'Demo household — foreign service number owner', '555-666-777');
$scenarios['DEMO-05 service mismatch (foreign number)'] = $runBill($main, $baseBill([
    'context' => ['utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)', 'meter_type' => 'SMART SOLAR', 'contracted_load' => '3', 'service_number' => '555 666 777', 'confidence' => 0.95],
]));

// DEMO-06: unreadable document.
$scenarios['DEMO-06 unreadable document'] = $runBill($main, $baseBill(
    ['document_assessment' => ['status' => 'UNREADABLE', 'reason' => 'blurred photo', 'confidence' => 0.42]] + $emptySections
));

// DEMO-07: low-data household — one limited bill (history thin for forecasting).
$low = $makeAccount('demo.low', 'Demo Low Data');
$lowHh = $makeHousehold($low, 'Demo household — low data', '1000-2000-300');
$scenarios['DEMO-07 low-data household (limitations bill)'] = $runBill($low, $baseBill([
    'document_assessment' => ['status' => 'VALID_WITH_LIMITATIONS', 'reason' => 'partially faded register', 'confidence' => 0.6],
    'solar' => ['previous_solar_reading' => null, 'present_solar_reading' => null, 'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null],
    'cost' => ['bill_amount' => null, 'arrears' => null, 'total_due' => null, 'energy_charges' => null, 'fixed_charges' => null, 'customer_service_charges' => null, 'other_charges' => null, 'taxes_adjustments' => null, 'confidence' => null],
]));

// DEMO-08: solar/data-unavailable household — exists, owns nothing yet.
$noData = $makeAccount('demo.empty', 'Demo No Data');
$noDataHh = $makeHousehold($noData, 'Demo household — reserved (no bills)', '1000-2000-999');
$scenarios['DEMO-08 reserved household (zero bills)'] = ['bill_id' => '-', 'status' => 'N/A (placeholder)', 'flags' => []];

// ---------------------------------------------------------------------
// Evidence print + credentials
// ---------------------------------------------------------------------

$pdo = Database::connection();
foreach ($scenarios as $label => $r) {
    $flagList = $r['flags'] === [] ? '' : '  flags=' . implode(',', $r['flags']);
    echo sprintf("  %-52s status=%-14s%s\n", $label, $r['status'], $flagList);
}

$counts = [];
foreach (['accounts', 'households', 'bills', 'bill_extraction_drafts', 'bill_field_provenance', 'bill_validation_results', 'bill_quality_flags', 'bill_temp_documents'] as $t) {
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
