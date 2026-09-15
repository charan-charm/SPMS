<?php

declare(strict_types=1);

/**
 * D-071 §U — controlled REAL-bill end-to-end pipeline verification.
 *
 * Executes one uploaded document through the REAL stack against the
 * spms_real database:
 *
 *   real account -> real household -> (optional) registered service
 *   number -> actual upload (B2 intake) -> Mistral OCR (LIVE provider
 *   call — this script is the ONLY authorized path to make one) ->
 *   document assessment -> service-number extraction -> ownership
 *   validation -> meter/solar/net deterministic validation -> B4 review
 *   read model -> database inspection (§V).
 *
 * It NEVER verifies (B5 boundary) and NEVER prints provider payloads,
 * keys or storage paths. The bill's own service number is printed (the
 * owner's own document value) so the registered household number can be
 * set from actual extraction evidence, not invented.
 *
 * Usage:
 *   php scripts/live-real-bill-check.php --confirm=LIVE-REAL-CHECK \
 *       --upload="D:\SPMS-TestData\mistral\spms-bill-test-01.jpeg" \
 *       [--register-service=<number>] [--email=<owner>]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

putenv('SPMS_DATA_ENVIRONMENT=real');

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Domain\Auth\AccountService;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillTempDocument;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Bill\Ownership;
use App\Domain\Bill\ServiceNumber;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillTempDocumentRepository;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\Config;
use App\Support\Database;

Config::initialize(dirname(__DIR__));

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? true;
    }
}

if (($options['confirm'] ?? null) !== 'LIVE-REAL-CHECK') {
    fwrite(STDERR, "Refusing: pass exactly --confirm=LIVE-REAL-CHECK (this makes a REAL Mistral call).\n");
    exit(1);
}

if ((string) Config::get('SPMS_DATA_ENVIRONMENT') !== 'real'
    || (string) Config::get('DB_DATABASE') !== (string) Config::get('SPMS_DB_REAL_DATABASE')) {
    fwrite(STDERR, "Refusing: must resolve to the REAL data environment.\n");
    exit(1);
}

$path = $options['upload'] ?? null;
if (!is_string($path) || !is_file($path)) {
    fwrite(STDERR, "--upload=<existing file path> is required.\n");
    exit(1);
}

// Mail stays local for the (possible) first-time owner activation.
Config::overrideForTesting([
    'MAIL_TRANSPORT' => 'file',
    'BREVO_ALLOW_REAL_SEND' => '0',
    'MAIL_FILE_DIR' => sys_get_temp_dir() . '/spms-live-check-mail-' . bin2hex(random_bytes(4)),
]);
@mkdir(Config::get('MAIL_FILE_DIR'), 0777, true);

$ocr = new MistralOcrClient();
if (!$ocr->hasApiKey()) {
    fwrite(STDERR, "No MISTRAL_API_KEY configured — aborting before any persistence.\n");
    exit(2);
}

$pdo = Database::connection();
$currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "[live-check] database: {$currentDb}\n";

// ---------------------------------------------------------------- owner
$email = is_string($options['email'] ?? null) ? $options['email'] : 'owner@spms.local';
$accountRepo = new App\Repositories\AccountRepository();
$account = $accountRepo->findByEmail($email);

if ($account === null) {
    $password = bin2hex(random_bytes(12)) . 'Aa1!';
    $service = new AccountService();
    $service->register($email, $password, '127.0.0.1');

    $files = glob(Config::get('MAIL_FILE_DIR') . '/*.eml') ?: [];
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
        fwrite(STDERR, "verification token not found in local mail sink\n");
        exit(1);
    }
    $account = $service->verifyEmail($token);
    echo "[live-check] created owner account {$email} (password printed ONCE, local dev only):\n           {$password}\n";
} else {
    echo "[live-check] reusing owner account {$email}\n";
}

$households = new HouseholdService();
$ctx = $households->activeHouseholdFor($account);
if ($ctx === null) {
    $ctx = $households->createForAccount($account, 'Owner dev household', 'Asia/Kolkata', null, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    echo "[live-check] created household\n";
}
$household = $ctx['household'];

if (isset($options['register-service'])) {
    $value = (string) $options['register-service'];
    if ($value === '') {
        fwrite(STDERR, "--register-service must not be empty (use --clear-service to clear)\n");
        exit(1);
    }
    $canonical = ServiceNumber::assertCanonical($value);
    $households->updateProfile($account, ['service_number' => $canonical], new DateTimeImmutable('now', new DateTimeZone('UTC')));
    echo "[live-check] household service number registered (canonical): " . ServiceNumber::mask($canonical) . " [last4]\n";
} elseif (array_key_exists('clear-service', $options)) {
    $households->updateProfile($account, ['service_number' => ''], new DateTimeImmutable('now', new DateTimeZone('UTC')));
    echo "[live-check] household service number cleared\n";
}

$registered = $households->activeHouseholdFor($account)['household']->serviceNumber();
echo '[live-check] household registered service: ' . ($registered === null ? '(NOT CONFIGURED)' : ServiceNumber::mask($registered)) . "\n";

// ---------------------------------------------------------------- upload
try {
    $validated = MistralOcrClient::validateLocalDocument($path);
} catch (Throwable $e) {
    fwrite(STDERR, "[live-check] local document validation failed: " . $e->getMessage() . "\n");
    exit(1);
}
echo "[live-check] intake ok: {$validated['mime']}, {$validated['size']} bytes\n";

$upload = BillUpload::fromFilesSpec(
    ['name' => basename($path), 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => $validated['size']],
    static fn (string $p): bool => is_file($p)
);
$intake = (new BillUploadIntakeService(new BillTempDocumentRepository(), new LocalPrivateBillStorage(), $households))
    ->accept($account, $upload, new DateTimeImmutable('now', new DateTimeZone('UTC')));
$billId = $intake['bill']->id();
echo "[live-check] bill accepted, id {$billId}, status " . $intake['bill']->status() . "\n";

// ---------------------------------------------------------------- process
$started = microtime(true);
try {
    $result = (new BillProcessingService(
        new BillService(),
        new BillTempDocumentRepository(),
        new LocalPrivateBillStorage(),
        $ocr
    ))->process($account, $billId);
} catch (Throwable $e) {
    fwrite(STDERR, "[live-check] processing failed: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
printf("[live-check] processed in %.1fs\n", microtime(true) - $started);

$bill = $result['bill'];
echo '[live-check] lifecycle status: ' . $bill->status() . "\n";
echo '[live-check] document assessment (draft): ' . $result['assessment']
    . '  confidence: ' . ($result['document_confidence'] ?? 'n/a') . "\n";

echo "[live-check] validations:\n";
foreach ($result['validations'] as $v) {
    printf(
        "    %-18s %-11s expected=%-10s extracted=%s\n",
        $v->rule(),
        $v->status(),
        $v->expectedValue() ?? '-',
        $v->extractedValue() ?? '-'
    );
}
$flagCodes = array_map(static fn ($f): string => $f->code(), $result['quality_flags']);
sort($flagCodes);
echo '[live-check] quality flags: ' . ($flagCodes === [] ? '(none)' : implode(', ', $flagCodes)) . "\n";

$extractedSn = $bill->value(BillField::SERVICE_NUMBER);
echo '[live-check] bill service number (canonical): '
    . ($extractedSn === null ? '(none extracted — nothing to register)' : $extractedSn) . "\n";

// ---------------------------------------------------------------- review read model + §V inspection
$service = new BillService();
$persisted = $service->get($account, $billId);
$persistedFlags = $service->qualityFlagsFor($account, $billId);
$ownership = Ownership::derive($persisted->status(), $persisted->values(), $persistedFlags, $registered);
echo '[live-check] ownership projection: ' . json_encode($ownership) . "\n";

$q = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
echo "[live-check] §V inspection:\n";
echo '    bills row:                 ' . $q("SELECT COUNT(*) FROM bills WHERE id = '$billId'") . "\n";
echo '    immutable draft:           ' . $q("SELECT COUNT(*) FROM bill_extraction_drafts WHERE bill_id = '$billId'") . "\n";
echo '    provenance rows:           ' . $q("SELECT COUNT(*) FROM bill_field_provenance WHERE bill_id = '$billId'") . "\n";
echo '    validation rows:           ' . $q("SELECT COUNT(*) FROM bill_validation_results WHERE bill_id = '$billId'") . "\n";
echo '    quality flags:             ' . count($persistedFlags) . "\n";
$tempState = $pdo->prepare('SELECT state FROM bill_temp_documents WHERE bill_id = :id');
$tempState->execute(['id' => $billId]);
echo '    temp document state:       ' . ($tempState->fetchColumn() ?: '(no row)') . "\n";
echo '    VERIFIED?                  ' . ($persisted->status() === 'VERIFIED' ? 'YES(!)' : 'no (correct)') . "\n";

$rawScan = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (COLUMN_NAME LIKE '%raw%' OR COLUMN_NAME LIKE '%ocr_text%' OR COLUMN_NAME LIKE '%annotation%' OR COLUMN_NAME LIKE '%customer_name%' OR COLUMN_NAME LIKE '%aadhaar%' OR COLUMN_NAME LIKE '%mobile%')")->fetchColumn();
echo '    raw/PII columns anywhere:  ' . (int) $rawScan . " (must be 0)\n";

echo "[live-check] DONE — the bill stays in its honest review state; verification is B5.\n";
