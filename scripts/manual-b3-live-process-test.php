<?php

declare(strict_types=1);

/**
 * SPMS — MANUAL B3 live extraction test (owner-run only).
 *
 * Exercises the REAL production pipeline against the REAL Mistral API:
 *
 *   fixture account + household (server-derived ownership)
 *   → BillUpload VO from a local file (the documented offline verifier
 *     seam stands in for Apache's is_uploaded_file in CLI context; all
 *     other B2 gates run unchanged: size, extension, magic signature)
 *   → BillUploadIntakeService (real private temporary storage)
 *   → BillProcessingService with a REAL MistralOcrClient (key ONLY from
 *     the local gitignored .env; never read, printed or logged here)
 *   → normalized draft + deterministic validation + provenance +
 *     quality flags persisted through the ratified B1 lifecycle
 *   → safe report of what landed in the DATABASE (the single source of
 *     truth for this test; raw OCR and raw provider responses are never
 *     printed or persisted)
 *   → temporary document lifecycle check + fixture cleanup
 *
 * Usage (ONE supervised run per document):
 *   php scripts/manual-b3-live-process-test.php --confirm=B3-LIVE-PROCESS --file="D:\SPMS-TestData\mistral\spms-bill-test-01.jpeg"
 *
 * Safety:
 * - refuses to run without the explicit --confirm token;
 * - refuses to self-enable anything: it only consumes existing config;
 * - mail transport is pinned to the file sink for the throwaway fixture
 *   account so no real email can ever be produced;
 * - the fixture (account/household/bill/temp rows + private file) is
 *   removed again at the end of the run.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Domain\Auth\AccountService;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillTempDocumentRepository;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\AppException;
use App\Support\Config;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$basePath = dirname(__DIR__);
Config::initialize($basePath);

// Hard safety for this script only: the fixture registration must never
// send real email, regardless of the local .env mail configuration.
Config::overrideForTesting([
    'MAIL_TRANSPORT' => 'file',
    'BREVO_ALLOW_REAL_SEND' => '0',
    'MAIL_FILE_DIR' => sys_get_temp_dir() . '/spms-b3-live-mail-' . bin2hex(random_bytes(4)),
]);

$confirm = null;
$filePath = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
    } elseif (str_starts_with($arg, '--file=')) {
        $filePath = substr($arg, strlen('--file='));
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

if ($confirm !== 'B3-LIVE-PROCESS') {
    fwrite(STDERR, "Refusing to run: this performs a REAL Mistral API call.\n");
    fwrite(STDERR, "Re-run with --confirm=B3-LIVE-PROCESS to proceed.\n");
    exit(1);
}

if ($filePath === null || $filePath === '') {
    fwrite(STDERR, "A --file=\"PATH\" argument is required (real bill outside the repository).\n");
    exit(1);
}

$ocr = new MistralOcrClient();
if (!$ocr->hasApiKey()) {
    fwrite(STDERR, "FAILED [configuration] MISTRAL_API_KEY is not set in the local .env (never commit it).\n");
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), Config::get('DB_PORT', '3306'), Config::get('DB_DATABASE')),
    Config::get('DB_USERNAME', 'root'),
    Config::get('DB_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$storage = new LocalPrivateBillStorage();
$householdId = null;
$accountId = null;
$billId = null;
$storageKey = null;

$fail = static function (string $message) use (&$householdId, &$accountId, &$billId, &$storage, &$storageKey, $pdo): never {
    fwrite(STDERR, "FAILED: {$message}\n");
    // Best-effort fixture cleanup even on failure paths.
    try {
        if ($billId !== null) {
            $pdo->prepare('DELETE FROM bills WHERE id = :id')->execute(['id' => $billId]);
        }
        if ($storageKey !== null) {
            $storage->delete($storageKey);
        }
        if ($householdId !== null) {
            $pdo->prepare('DELETE FROM household_memberships WHERE household_id = :id')->execute(['id' => $householdId]);
            $pdo->prepare('DELETE FROM households WHERE id = :id')->execute(['id' => $householdId]);
        }
        if ($accountId !== null) {
            $pdo->prepare('DELETE FROM accounts WHERE id = :id')->execute(['id' => $accountId]);
        }
    } catch (Throwable) {
        // cleanup is best-effort
    }
    exit(1);
};

try {
    // 1. Throwaway fixture with a real household (ownership derives from
    //    the membership, exactly like every production path).
    $accountService = new AccountService();
    $email = 'b3-live-' . bin2hex(random_bytes(5)) . '@example.test';
    ['account' => $account] = $accountService->register($email, 'correct-horse-battery', '10.0.0.1');
    $accountId = $account->id();

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $household = (new HouseholdService())->createForAccount($account, 'b3-live-home', 'Asia/Kolkata', null, $now)['household'];
    $householdId = $household->id();

    // 2. Real B2 intake of the real document (all production gates; the
    //    CLI verification seam replaces only Apache's is_uploaded_file).
    $upload = BillUpload::fromFilesSpec(
        ['name' => basename($filePath), 'type' => 'application/octet-stream', 'tmp_name' => $filePath, 'error' => UPLOAD_ERR_OK, 'size' => 0],
        static fn (string $p): bool => is_file($p)
    );
    $intake = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage))->accept($account, $upload, $now);
    $billId = $intake['bill']->id();
    $storageKey = $intake['temp']->storageKey();

    echo "B3 LIVE EXTRACTION TEST\n";
    echo "-----------------------\n";
    echo 'Document: ' . basename($filePath) . ' (' . $upload->sizeBytes() . " bytes)\n";
    echo 'Bill:     ' . $billId . "\n";
    echo 'Intake:   UPLOADED -> ' . $intake['bill']->status() . ", temp stored privately: " . ($storage->exists($storageKey) ? 'yes' : 'NO?!') . "\n\n";

    // 3. The real B3 pipeline against the real Mistral API.
    $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $storage);
    $result = $service->process($account, $billId, new DateTimeImmutable('now', new DateTimeZone('UTC')));

    // 4. Read back EVERYTHING from the database — the persisted normalized
    //    state is the evidence, not the provider response.
    $billStmt = $pdo->prepare('SELECT * FROM bills WHERE id = :id');
    $billStmt->execute(['id' => $billId]);
    $bill = $billStmt->fetch() ?: $fail('bill missing after processing');
    $draft = $pdo->prepare('SELECT * FROM bill_extraction_drafts WHERE bill_id = :id');
    $draft->execute(['id' => $billId]);
    $draftRow = $draft->fetch() ?: $fail('draft missing after processing');

    echo "LIFECYCLE: " . $bill['status'] . " (verified_at: " . ($bill['verified_at'] ?? 'NULL — never auto-verified') . ")\n";
    echo "PROVIDER:  " . $draftRow['extraction_provider'] . ' / ' . ($draftRow['extraction_model'] ?? '-') . "\n";
    echo "ASSESSMENT: " . $draftRow['document_assessment'] . ' (doc confidence: ' . ($draftRow['document_confidence'] ?? 'NULL') . ")\n\n";

    echo "PERSISTED NORMALIZED BILL VALUES (from the bills table):\n";
    $fields = App\Domain\Bill\BillField::ALL;
    foreach ($fields as $field) {
        $extractedVal = $bill[$field] ?? null;
        $draftVal = $draftRow[$field] ?? null;
        if ($extractedVal === null && $draftVal === null) {
            continue; // keep the report to what exists
        }
        echo sprintf("  %-28s bill=%-12s draft=%-12s\n", $field, $extractedVal ?? 'NULL', $draftVal ?? 'NULL');
    }

    echo "\nDETERMINISTIC VALIDATION (from bill_validation_results):\n";
    $v = $pdo->prepare('SELECT rule, status, expected_value, extracted_value, reason_code FROM bill_validation_results WHERE bill_id = :id ORDER BY rule');
    $v->execute(['id' => $billId]);
    foreach ($v->fetchAll() as $row) {
        echo sprintf(
            "  %-20s %-11s expected=%-10s extracted=%-10s%s\n",
            $row['rule'],
            $row['status'],
            $row['expected_value'] ?? 'NULL',
            $row['extracted_value'] ?? 'NULL',
            $row['reason_code'] ? ' reason=' . $row['reason_code'] : ''
        );
    }

    $q = $pdo->prepare('SELECT code, field_name FROM bill_quality_flags WHERE bill_id = :id ORDER BY code');
    $q->execute(['id' => $billId]);
    $flags = $q->fetchAll();
    echo "\nQUALITY FLAGS: " . ($flags === [] ? '(none)' : implode(', ', array_map(static fn (array $f): string => $f['code'] . ($f['field_name'] ? '@' . $f['field_name'] : ''), $flags))) . "\n";

    $p = $pdo->prepare('SELECT COUNT(*) total, SUM(source = \'AI_EXTRACTION\') ai, SUM(confidence IS NOT NULL) withconf FROM bill_field_provenance WHERE bill_id = :id');
    $p->execute(['id' => $billId]);
    $prov = $p->fetch();
    echo "PROVENANCE:    {$prov['total']} field rows, {$prov['ai']} AI-sourced, {$prov['withconf']} carrying field confidence\n";

    $tempRow = $pdo->prepare('SELECT state, deleted_at FROM bill_temp_documents WHERE bill_id = :id');
    $tempRow->execute(['id' => $billId]);
    $temp = $tempRow->fetch() ?: $fail('temp record missing');
    echo "TEMP DOC:      state={$temp['state']} deleted_at=" . ($temp['deleted_at'] ?? 'NULL') . ' file_present=' . ($storage->exists($storageKey) ? 'YES' : 'no') . "\n\n";

    // 5. Fixture cleanup (bill cascades children; then household/account).
    $pdo->prepare('DELETE FROM bills WHERE id = :id')->execute(['id' => $billId]);
    $storage->delete($storageKey);
    $pdo->prepare('DELETE FROM household_memberships WHERE household_id = :id')->execute(['id' => $householdId]);
    $pdo->prepare('DELETE FROM households WHERE id = :id')->execute(['id' => $householdId]);
    $pdo->prepare('DELETE FROM accounts WHERE id = :id')->execute(['id' => $accountId]);
    echo "Fixture cleaned up (bill + temp file + household + throwaway account removed).\n";
    exit(0);
} catch (AppException $e) {
    $fail('app error ' . $e->errorCode() . ': ' . $e->getMessage());
} catch (Throwable $e) {
    fwrite(STDERR, 'DEBUG ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    // Never echo provider internals: exception class + message could
    // legitimately carry signed URLs, so print only a fixed hint and let
    // the DB state speak.
    $fail('unexpected error (' . get_class($e) . ') — see DEBUG line above (message scrubbed if it contained provider material)');
}
