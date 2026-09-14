<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Controllers\BillController;
use App\Domain\Account\Account;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\BillTempCleanupService;
use App\Domain\Bill\BillTempDocument;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Repositories\BillTempDocumentRepository;
use App\Security\AuthMiddleware;
use App\Services\Files\BillStorageException;
use App\Services\Files\BillTemporaryStorageInterface;
use App\Services\Files\LocalPrivateBillStorage;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * B2 — Secure upload intake workflow (docs/10 D-068).
 *
 * REAL auth pipeline (register → verify → login → cookie + CSRF), REAL
 * database, REAL private-filesystem storage (inside OS temp) — synthetic
 * signature fixtures only. Asserts the whole intake contract: lifecycle,
 * server-derived ownership, fail-closed validation, compensation on
 * failure, expiry cleanup, and a response that never leaks a path, key,
 * name or content.
 */
final class BillUploadWorkflowTest
{
    /** @var list<string> temp roots created per check */
    private static array $roots = [];

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * @return array{token: string, csrf: string, account: Account}
     */
    private static function loginWithHousehold(bool $withHousehold = true): array
    {
        $auth = new AuthController();
        $email = AuthTesting::uniqueEmail();

        $auth->register(Request::create('POST', '/api/v1/auth/register', body: [
            'email' => $email,
            'password' => 'correct-horse-battery',
        ], ipAddress: AuthTesting::uniqueIpAddress()));

        $token = AuthTesting::latestMailToken('Verify your email');
        Assert::true($token !== null);
        $auth->verify(Request::create('POST', '/api/v1/auth/verify', body: ['token' => $token]));

        $login = $auth->login(Request::create('POST', '/api/v1/auth/login', body: [
            'email' => $email,
            'password' => 'correct-horse-battery',
        ], ipAddress: AuthTesting::uniqueIpAddress()));

        $sessionToken = AuthTesting::sessionTokenFrom($login);
        $csrf = AuthTesting::csrfFrom($login->body()['data'] ?? []);
        Assert::true($sessionToken !== null && $csrf !== null);

        if ($withHousehold) {
            $household = new \App\Controllers\HouseholdController();
            $household->create(self::pipelineRequest('POST', '/api/v1/household', $sessionToken, [
                'timezone' => 'Asia/Kolkata',
                'label' => 'b2-workflow-home',
            ], $csrf));
        }

        $account = (new \App\Repositories\AccountRepository())->findByEmail(strtolower($email));
        Assert::true($account instanceof Account);

        return ['token' => $sessionToken, 'csrf' => $csrf, 'account' => $account];
    }

    /**
     * A genuine pipeline request: middleware (session + CSRF) processes it.
     *
     * @param array<string, mixed> $files
     */
    private static function pipelineRequest(string $method, string $path, string $rawToken, array $body = [], string $csrf = '', array $files = []): Request
    {
        $headers = ['Cookie' => 'spms_session=' . $rawToken];
        if ($csrf !== '') {
            $headers[AuthMiddleware::CSRF_HEADER] = $csrf;
        }

        return AuthMiddleware::process(Request::create(
            $method,
            $path,
            body: $body,
            headers: $headers,
            ipAddress: AuthTesting::uniqueIpAddress(),
            files: $files
        ));
    }

    /** @return array{0: array<string, mixed>, 1: string} spec + tmp path */
    private static function fileSpec(string $contents, string $clientName): array
    {
        $path = sys_get_temp_dir() . '/spms-b2-src-' . bin2hex(random_bytes(5)) . '.bin';
        file_put_contents($path, $contents);

        return [[
            'name' => $clientName,
            'type' => 'image/png', // deliberately WRONG client claim
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => 7,           // deliberately WRONG client claim
        ], $path];
    }

    private static function uploadedVerifier(): callable
    {
        return static fn (string $path): bool => is_file($path);
    }

    private static function freshStorage(): LocalPrivateBillStorage
    {
        $root = sys_get_temp_dir() . '/spms-b2-root-' . bin2hex(random_bytes(5));
        self::$roots[] = $root;

        return new LocalPrivateBillStorage($root);
    }

    private static function controller(?BillTempDocumentRepository $repo = null, ?BillTemporaryStorageInterface $storage = null): BillController
    {
        return new BillController(
            new BillUploadIntakeService(
                $repo ?? new BillTempDocumentRepository(),
                $storage ?? self::freshStorage(),
                new HouseholdService()
            ),
            self::uploadedVerifier()
        );
    }

    private static function cleanup(BillTemporaryStorageInterface $storage): BillTempCleanupService
    {
        return new BillTempCleanupService(new BillTempDocumentRepository(), $storage);
    }

    private static function billRow(string $billId): ?array
    {
        $s = self::pdo()->prepare('SELECT * FROM bills WHERE id = :id');
        $s->execute(['id' => $billId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function tempRow(string $billId): ?array
    {
        $s = self::pdo()->prepare('SELECT * FROM bill_temp_documents WHERE bill_id = :id');
        $s->execute(['id' => $billId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function run(): array
    {
        $tests = [
            TestResult::check('migration 0014: temporary table is operational metadata ONLY (no name/path/content)', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_temp_documents'"
                );
                $statement->execute([]);
                $columns = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
                sort($columns);

                Assert::same(
                    ['bill_id', 'created_at', 'deleted_at', 'expires_at', 'id', 'mime_type', 'size_bytes', 'state', 'storage_key', 'updated_at'],
                    $columns,
                    'the B2 reference table may carry nothing else'
                );

                $statement = self::pdo()->prepare(
                    "SELECT REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_temp_documents'"
                );
                $statement->execute([]);
                $fk = $statement->fetch(PDO::FETCH_ASSOC);
                Assert::same('bills', $fk['REFERENCED_TABLE_NAME'] ?? null);
                Assert::same('CASCADE', $fk['DELETE_RULE'] ?? null, 'the reference dies with its bill aggregate');

                // B1 tables untouched: bills gained no column.
                $statement = self::pdo()->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bills'"
                );
                $statement->execute([]);
                Assert::same(27, (int) $statement->fetchColumn(), 'B2 added nothing to bills (§24: no schema creep)');
            }),

            TestResult::check('anonymous upload is rejected by the real pipeline; CSRF failure is rejected too', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                [$spec] = self::fileSpec("\xFF\xD8\xFF" . str_repeat('x', 40), 'bill.jpg');

                // No cookie at all -> middleware gives anonymous context -> 401.
                $anonymous = AuthMiddleware::process(Request::create('POST', '/api/v1/bills/upload', files: ['file' => $spec]));
                try {
                    self::controller()->upload($anonymous);
                    Assert::true(false, 'anonymous upload must be rejected');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }

                // Authenticated but CSRF-less -> the middleware rejects it 403.
                $session = self::loginWithHousehold();
                try {
                    AuthMiddleware::process(Request::create(
                        'POST',
                        '/api/v1/bills/upload',
                        headers: ['Cookie' => 'spms_session=' . $session['token']],
                        ipAddress: AuthTesting::uniqueIpAddress(),
                        files: ['file' => $spec]
                    ));
                    Assert::true(false, 'CSRF-less mutation must be rejected');
                } catch (AppException $e) {
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }
            }),

            TestResult::check('a valid JPEG upload becomes PROCESSING with a private temporary reference', function (): void {
                $session = self::loginWithHousehold();
                $storage = self::freshStorage();
                $jpeg = "\xFF\xD8\xFF\xDB" . random_bytes(500);

                [$spec, $srcPath] = self::fileSpec($jpeg, 'Ammas_bill_original.jpg');
                $request = self::pipelineRequest('POST', '/api/v1/bills/upload', $session['token'], files: ['file' => $spec], csrf: $session['csrf']);

                $response = self::controller(null, $storage)->upload($request);
                @unlink($srcPath);

                Assert::same(201, $response->status());
                $data = $response->body()['data'];

                Assert::same(36, strlen((string) $data['bill_id']));
                Assert::same(BillStatus::PROCESSING, $data['status'], 'intake lands exactly at PROCESSING (§8)');
                Assert::same('image/jpeg', $data['document']['type'], 'canonical type from the signature, not the lies in the multipart');
                Assert::same(strlen($jpeg), $data['document']['size_bytes'], 'real size from disk, not the client claim');

                // Database: bill (PROCESSING, never verified) + temp reference.
                $bill = self::billRow((string) $data['bill_id']);
                Assert::true(is_array($bill));
                Assert::same('PROCESSING', $bill['status']);
                Assert::same(null, $bill['verified_at'], 'no verification happens during intake (§8)');
                Assert::same(null, $bill['consumption_kwh'], 'no extraction data exists yet (§26)');

                $temp = self::tempRow((string) $bill['id']);
                Assert::true(is_array($temp));
                Assert::same('AVAILABLE', $temp['state']);
                Assert::same(strlen($jpeg), (int) $temp['size_bytes']);
                Assert::true(str_starts_with((string) $temp['expires_at'], '20'), 'expiry recorded');

                // File landed privately and byte-exactly under an opaque key.
                Assert::true($storage->exists((string) $temp['storage_key']));
                Assert::same($jpeg, $storage->read((string) $temp['storage_key']));
                Assert::same(36 + 4, strlen((string) $temp['storage_key']), 'key is UUID.jpg');
                Assert::false(str_contains((string) $temp['storage_key'], 'bill_original'), 'client name not in the key');

                // §14/§15 response privacy: nothing internal or identifying.
                $body = json_encode($response->body()) ?: '';
                foreach (['storage_key', 'tmp', 'spms-b2-root', 'Ammas_bill', $storage->rootDir(), 'private'] as $forbidden) {
                    Assert::notContains($forbidden, $body, 'response leaks: ' . $forbidden);
                }
            }),

            TestResult::check('PNG and PDF intake canonicalize type and extension (wrong claims ignored)', function (): void {
                $session = self::loginWithHousehold();
                $storage = self::freshStorage();

                foreach ([
                    ["\x89PNG\r\n\x1a\n" . random_bytes(60), 'receipt.PNG', 'image/png', '.png'],
                    ["%PDF-1.5\n%\xB5\xB5\xB5\xB5\n", 'GST statement.pdf', 'application/pdf', '.pdf'],
                ] as [$bytes, $name, $mime, $ext]) {
                    [$spec, $src] = self::fileSpec($bytes, $name);
                    $response = self::controller(null, $storage)->upload(
                        self::pipelineRequest('POST', '/api/v1/bills/upload', $session['token'], files: ['file' => $spec], csrf: $session['csrf'])
                    );
                    @unlink($src);

                    $data = $response->body()['data'];
                    Assert::same(201, $response->status());
                    Assert::same($mime, $data['document']['type']);

                    $temp = self::tempRow((string) $data['bill_id']);
                    Assert::true(str_ends_with((string) $temp['storage_key'], $ext), 'canonical extension ' . $ext);
                    Assert::same($bytes, $storage->read((string) $temp['storage_key']), 'bytes preserved untouched (§20)');
                }
            }),

            TestResult::check('rejects do not create bills or files: mismatch, wrong type, empty, missing part, text-field smuggling', function (): void {
                $session = self::loginWithHousehold();
                $storage = self::freshStorage();

                $cases = [
                    'extension-mismatch' => [["\xFF\xD8\xFF" . str_repeat('x', 30), 'actually-jpeg.png'], 'INVALID_FILE_SIGNATURE'],
                    'unsupported'        => [["MZ\x90\x00" . str_repeat('x', 30), 'payload.exe'], 'UNSUPPORTED_FILE_TYPE'],
                    'php-extension'      => [["%PDF-1.7\n" . str_repeat('x', 30), 'shell.php'], 'UNSUPPORTED_FILE_TYPE'],
                    'empty'              => [['', 'e.jpg'], 'EMPTY_FILE'],
                    'no-part'            => [null, 'INVALID_UPLOAD'],
                ];

                $billsBefore = (int) self::pdo()->query('SELECT COUNT(*) FROM bills')->fetchColumn();

                foreach ($cases as $label => [$args, $expectedCode]) {
                    if ($args === null) {
                        $files = [];
                    } else {
                        [$spec, ] = self::fileSpec($args[0], $args[1]);
                        $files = ['file' => $spec];
                    }

                    try {
                        self::controller(null, $storage)->upload(
                            self::pipelineRequest('POST', '/api/v1/bills/upload', $session['token'], files: $files, csrf: $session['csrf'])
                        );
                        Assert::true(false, $label . ' must be rejected');
                    } catch (AppException $e) {
                        Assert::same($expectedCode, $e->errorCode(), $label);
                        Assert::true($e->httpStatus() >= 400 && $e->httpStatus() < 500, $label . ' must be 4xx');
                    }
                }

                // Text fields are refused outright — household/status smuggle attempts never reach the domain.
                foreach (['household_id', 'status', 'account_id', 'role', 'verified'] as $field) {
                    [$spec, ] = self::fileSpec("\xFF\xD8\xFFabc", 'fine.jpg');
                    try {
                        self::controller(null, $storage)->upload(self::pipelineRequest(
                            'POST',
                            '/api/v1/bills/upload',
                            $session['token'],
                            [$field => 'anything'],
                            $session['csrf'],
                            ['file' => $spec]
                        ));
                        Assert::true(false, 'body field ' . $field . ' must be rejected');
                    } catch (AppException $e) {
                        Assert::same('VALIDATION_ERROR', $e->errorCode());
                        Assert::same($field, $e->context()['field'] ?? null);
                    }
                }

                Assert::same($billsBefore, (int) self::pdo()->query('SELECT COUNT(*) FROM bills')->fetchColumn(), 'every rejection left no bill');
            }),

            TestResult::check('an account with no household cannot upload (404, file untouched)', function (): void {
                $session = self::loginWithHousehold(withHousehold: false);
                $storage = self::freshStorage();
                [$spec, ] = self::fileSpec("\xFF\xD8\xFFabc", 'orphan.jpg');

                try {
                    self::controller(null, $storage)->upload(
                        self::pipelineRequest('POST', '/api/v1/bills/upload', $session['token'], files: ['file' => $spec], csrf: $session['csrf'])
                    );
                    Assert::true(false, 'expected 404 without household');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                }
            }),

            TestResult::check('storage failure never persists a bill; database failure compensates the stored file', function (): void {
                $session = self::loginWithHousehold();

                // -- storage blows up BEFORE any DB work -> no bill exists.
                $exploding = new class () implements BillTemporaryStorageInterface {
                    public function store(string $storageKey, string $contents): void
                    {
                        throw new BillStorageException('simulated outage');
                    }

                    public function exists(string $storageKey): bool
                    {
                        return false;
                    }

                    public function read(string $storageKey): ?string
                    {
                        return null;
                    }

                    public function delete(string $storageKey): bool
                    {
                        return true;
                    }
                };

                [$spec, ] = self::fileSpec("\xFF\xD8\xFFabc", 'a.jpg');
                try {
                    self::controller(null, $exploding)->upload(
                        self::pipelineRequest('POST', '/api/v1/bills/upload', $session['token'], files: ['file' => $spec], csrf: $session['csrf'])
                    );
                    Assert::true(false, 'storage failure must surface');
                } catch (AppException $e) {
                    Assert::same('STORAGE_FAILED', $e->errorCode());
                }

                // -- storage succeeds, DB commit fails -> file compensated, bill absent.
                $storage = self::freshStorage();

                $failing = new class (null) extends BillTempDocumentRepository {
                    public function persistUpload(Bill $uploaded, Bill $processing, BillTempDocument $doc): void
                    {
                        throw new RuntimeException('simulated commit failure');
                    }
                };

                $billsBefore = (int) self::pdo()->query('SELECT COUNT(*) FROM bills')->fetchColumn();

                [$spec2, ] = self::fileSpec("%PDF-1.7\nabc", 'b.pdf');
                try {
                    self::controller($failing, $storage)->upload(
                        self::pipelineRequest('POST', '/api/v1/bills/upload', $session['token'], files: ['file' => $spec2], csrf: $session['csrf'])
                    );
                    Assert::true(false, 'DB failure must surface');
                } catch (AppException $e) {
                    Assert::same('STORAGE_FAILED', $e->errorCode());
                }

                Assert::same($billsBefore, (int) self::pdo()->query('SELECT COUNT(*) FROM bills')->fetchColumn(), 'no orphan bill after DB failure');

                // The compensating delete left nothing uncontrolled behind.
                $files = glob($storage->rootDir() . '/*') ?: [];
                Assert::same([], $files, 'no orphan temporary file after DB failure (§12)');
            }),

            TestResult::check('temporary reference is only reachable through the owning household', function (): void {
                [$accountA, $householdA] = [null, null];
                $fixtureA = AuthTesting::householdFixture('b2-a-' . bin2hex(random_bytes(3)));
                $fixtureB = AuthTesting::householdFixture('b2-b-' . bin2hex(random_bytes(3)));

                $storage = self::freshStorage();
                $service = new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService());

                [$spec, ] = self::fileSpec("\xFF\xD8\xFF" . random_bytes(40), 'owned.jpg');
                $upload = \App\Domain\Bill\BillUpload::fromFilesSpec($spec, self::uploadedVerifier());
                $result = $service->accept($fixtureA['account'], $upload, self::now());

                $repo = new BillTempDocumentRepository();
                Assert::true(
                    $repo->findForBill($result['bill']->id(), $fixtureA['household_id']) instanceof BillTempDocument,
                    'owner resolves the reference'
                );
                Assert::same(null, $repo->findForBill($result['bill']->id(), $fixtureB['household_id']), 'a foreign household cannot resolve it at all');

                $bills = new BillRepository();
                Assert::same(null, $bills->find($result['bill']->id(), $fixtureB['household_id']));
            }),

            TestResult::check('cleanup purges only expired references, tolerates missing files, and is idempotent', function (): void {
                $fixture = AuthTesting::householdFixture('b2-c2-' . bin2hex(random_bytes(3)));
                $account = $fixture['account'];
                $householdId = $fixture['household_id'];

                $storage = self::freshStorage();
                $service = new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService());
                $repo = new BillTempDocumentRepository();

                // Two uploads: one will be expired, one stays live.
                $makeUpload = static function () use ($service, $account): array {
                    [$spec, ] = self::fileSpec("\xFF\xD8\xFF" . random_bytes(64), 't.jpg');
                    $upload = \App\Domain\Bill\BillUpload::fromFilesSpec($spec, self::uploadedVerifier());

                    return $service->accept($account, $upload, self::now());
                };

                $expired = $makeUpload();
                $live = $makeUpload();

                // Simulate expiry arriving (and a third doc whose file an
                // operator already removed by hand).
                self::pdo()->prepare('UPDATE bill_temp_documents SET expires_at = :when WHERE id = :id')
                    ->execute(['when' => '2020-01-01 00:00:00', 'id' => $expired['temp']->id()]);
                self::pdo()->prepare('UPDATE bill_temp_documents SET expires_at = :when WHERE id = :id')
                    ->execute(['when' => '2020-01-01 00:00:00', 'id' => $live['temp']->id()]);
                Assert::true($storage->delete($live['temp']->storageKey()), 'operator already removed this file');

                $cleanup = self::cleanup($storage);
                $run1 = $cleanup->purgeExpired(self::now());

                Assert::same(2, $run1['scanned']);
                Assert::same(2, $run1['cleaned'], 'missing file is tolerated, not an error');
                Assert::false($storage->exists($expired['temp']->storageKey()), 'expired file gone');

                $rowExpired = self::tempRow($expired['bill']->id());
                Assert::same('DELETED', $rowExpired['state']);
                Assert::true($rowExpired['deleted_at'] !== null);

                // Idempotent: nothing expired remains; second pass is a no-op.
                $run2 = $cleanup->purgeExpired(self::now());
                Assert::same(0, $run2['scanned']);
                Assert::same(0, $run2['cleaned']);

                // A freshly-uploaded, unexpired document is NOT touched.
                [$spec, ] = self::fileSpec("\xFF\xD8\xFF" . str_repeat('k', 20), 'fresh.jpg');
                $upload = \App\Domain\Bill\BillUpload::fromFilesSpec($spec, self::uploadedVerifier());
                $fresh = $service->accept($account, $upload, self::now());
                $cleanup->purgeExpired(self::now());
                Assert::true($storage->exists($fresh['temp']->storageKey()), 'live uploads survive cleanup');
                Assert::same('AVAILABLE', self::tempRow($fresh['bill']->id())['state']);
            }),

            TestResult::check('real Apache-visible surface is separate: nothing is served from storage/private', function (): void {
                $publicRoot = str_replace('\\', '/', dirname(__DIR__, 2) . '/public');

                // Production policy: the DEFAULT root is private + off-web-root...
                $default = str_replace('\\', '/', LocalPrivateBillStorage::defaultRoot(null));
                Assert::contains('/storage/private/bills/tmp', $default);
                Assert::false(str_starts_with($default, $publicRoot), 'private upload storage must live outside the web root');

                // ...and whatever the effective (possibly test-pinned) config
                // resolves to, it must ALSO be outside the web root.
                $effective = str_replace('\\', '/', LocalPrivateBillStorage::defaultRoot(Config::get('BILL_TMP_DIR')));
                Assert::false(str_starts_with($effective, $publicRoot), 'configured storage leaked into the web root: ' . $effective);
            }),
        ];

        // Best-effort temp-root cleanup (repo files were never created).
        foreach (self::$roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($root);
        }

        return $tests;
    }
}
