<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\BillController;
use App\Domain\Account\Account;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Repositories\BillTempDocumentRepository;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\AppException;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class BillDuplicateAndRoutingRegressionTest
{
    private static array $roots = [];
    private static array $createdBills = [];

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    private static function freshStorage(): LocalPrivateBillStorage
    {
        $root = sys_get_temp_dir() . '/spms-dup-root-' . bin2hex(random_bytes(5));
        self::$roots[] = $root;

        return new LocalPrivateBillStorage($root);
    }

    private static function trackBill(string $id): string
    {
        self::$createdBills[] = $id;
        return $id;
    }

    private static function fakeOcrClient(array $queue): array
    {
        $calls = new \ArrayObject();
        $handler = static function (string $method, string $url, array $options) use (&$queue, $calls): array {
            $calls->append(['method' => $method, 'url' => $url, 'options' => $options]);
            $next = array_shift($queue);
            if ($next === null) {
                throw new \RuntimeException('test handler queue exhausted');
            }

            return $next;
        };

        return [new MistralOcrClient('mk_FAKE_REGRESSION', 'mistral-ocr-test', $handler), $calls];
    }

    private static function ocrBody(array $annotation): string
    {
        $payload = [
            'model' => 'mistral-ocr-test',
            'pages' => [[
                'index' => 0,
                'markdown' => 'PAGE-CONTENT',
                'images' => [],
                'tables' => [],
            ]],
            'usage_info' => ['input_tokens' => 100, 'output_tokens' => 50],
        ];

        if ($annotation !== []) {
            $payload['document_annotation'] = json_encode($annotation);
        }

        return (string) json_encode($payload);
    }

    private const FIXTURE_SERVICE = '1000-2000-300';

    private static function validBillAnnotation(string $start = '2026-08-01', string $end = '2026-09-01'): array
    {
        return [
            'document_assessment' => ['status' => 'VALID', 'reason' => 'clear bill', 'confidence' => 0.99],
            'billing_period' => ['start' => $start, 'end' => $end, 'confidence' => 0.95],
            'consumption' => ['consumption_kwh' => 145, 'confidence' => 0.98],
            'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460, 'confidence' => 0.99],
            'solar' => [
                'previous_solar_reading' => 1878, 'present_solar_reading' => 2200,
                'solar_units_kwh' => 322, 'net_units_kwh' => -177, 'confidence' => 0.97,
            ],
            'cost' => [
                'bill_amount' => null, 'arrears' => null, 'total_due' => null, 'energy_charges' => null,
                'fixed_charges' => 30, 'customer_service_charges' => 25, 'other_charges' => null,
                'taxes_adjustments' => null, 'confidence' => 0.9,
            ],
            'context' => [
                'utility_provider' => 'APSPDCL', 'tariff_category' => 'DOMESTIC (A)',
                'meter_type' => 'SMART SOLAR', 'contracted_load' => '3',
                'service_number' => self::FIXTURE_SERVICE, 'confidence' => 0.95,
            ],
        ];
    }

    public static function run(): array
    {
        $tests = [];

        // 1. Same exact file uploaded twice:
        //    - second upload does not invoke OCR
        //    - duplicate response (409 DUPLICATE_BILL_FILE) is returned
        //    - no second bill record created
        $tests[] = TestResult::check('1. same exact file uploaded twice returns 409 DUPLICATE_BILL_FILE and never invokes OCR', function (): void {
            $fixture = AuthTesting::householdFixture('dup-1-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $storage = self::freshStorage();
            $tempRepo = new BillTempDocumentRepository();
            $households = new HouseholdService();
            $billRepo = new BillRepository();

            // Fixed content for exact duplicate check
            $fixedBytes = "\x89PNG\r\n\x1a\n" . "SAME_EXACT_BILL_CONTENT_TEST_BYTES_" . bin2hex(random_bytes(16));
            $src1 = sys_get_temp_dir() . '/spms-dup-src1-' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($src1, $fixedBytes);

            $upload1 = BillUpload::fromFilesSpec(
                ['name' => 'september_2026.png', 'type' => 'image/png', 'tmp_name' => $src1, 'error' => UPLOAD_ERR_OK, 'size' => strlen($fixedBytes)],
                static fn (string $p): bool => is_file($p)
            );

            $intake = new BillUploadIntakeService($tempRepo, $storage, $households, $billRepo);
            $result1 = $intake->accept($fixture['account'], $upload1, self::now());
            @unlink($src1);
            self::trackBill($result1['bill']->id());

            // Process and verify bill 1
            [$ocr1, $calls1] = self::fakeOcrClient([
                ['status' => 200, 'body' => self::ocrBody(self::validBillAnnotation('2026-08-01', '2026-09-01'))],
            ]);
            $procService1 = new BillProcessingService(new BillService(), $tempRepo, $storage, $ocr1);
            $procResult1 = $procService1->process($fixture['account'], $result1['bill']->id(), self::now());
            Assert::same(BillStatus::EXTRACTED, $procResult1['bill']->status());

            // Mark bill 1 VERIFIED
            $verifiedBill = (new BillService())->verify($fixture['account'], $result1['bill']->id(), self::now());
            Assert::same(BillStatus::VERIFIED, $verifiedBill->status());

            // Now upload the SAME EXACT FILE again
            $src2 = sys_get_temp_dir() . '/spms-dup-src2-' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($src2, $fixedBytes);

            $upload2 = BillUpload::fromFilesSpec(
                ['name' => 'september_2026_copy.png', 'type' => 'image/png', 'tmp_name' => $src2, 'error' => UPLOAD_ERR_OK, 'size' => strlen($fixedBytes)],
                static fn (string $p): bool => is_file($p)
            );

            // Intake must reject BEFORE OCR or temporary file storage
            try {
                $intake->accept($fixture['account'], $upload2, self::now());
                Assert::true(false, 'expected DUPLICATE_BILL_FILE exception');
            } catch (AppException $e) {
                Assert::same('DUPLICATE_BILL_FILE', $e->errorCode());
                Assert::same(409, $e->httpStatus());
                Assert::same('FILE', $e->context()['duplicate_type'] ?? null);
                Assert::same($result1['bill']->id(), $e->context()['existing_bill_id'] ?? null);
                Assert::contains('already recorded', $e->getMessage());
            } finally {
                @unlink($src2);
            }

            // Verify only 1 bill exists for this household
            $householdBills = $billRepo->listForHousehold($fixture['household_id']);
            Assert::same(1, count($householdBills));
            Assert::same($result1['bill']->id(), $householdBills[0]->id());
        });

        // 2. Same billing period but different file:
        //    - extraction occurs
        //    - duplicate-period validation returns 409 DUPLICATE_BILL_PERIOD
        $tests[] = TestResult::check('2. same billing period with different file returns 409 DUPLICATE_BILL_PERIOD after extraction', function (): void {
            $fixture = AuthTesting::householdFixture('dup-2-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $storage = self::freshStorage();
            $tempRepo = new BillTempDocumentRepository();
            $households = new HouseholdService();
            $billRepo = new BillRepository();

            // File 1
            $bytes1 = "\x89PNG\r\n\x1a\n" . "FIRST_BILL_DIFFERENT_FILE_" . bin2hex(random_bytes(16));
            $src1 = sys_get_temp_dir() . '/spms-diff-src1-' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($src1, $bytes1);

            $upload1 = BillUpload::fromFilesSpec(
                ['name' => 'bill1.png', 'type' => 'image/png', 'tmp_name' => $src1, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes1)],
                static fn (string $p): bool => is_file($p)
            );

            $intake = new BillUploadIntakeService($tempRepo, $storage, $households, $billRepo);
            $result1 = $intake->accept($fixture['account'], $upload1, self::now());
            @unlink($src1);
            self::trackBill($result1['bill']->id());

            [$ocr1] = self::fakeOcrClient([
                ['status' => 200, 'body' => self::ocrBody(self::validBillAnnotation('2026-08-01', '2026-09-01'))],
            ]);
            $procService1 = new BillProcessingService(new BillService(), $tempRepo, $storage, $ocr1);
            $procResult1 = $procService1->process($fixture['account'], $result1['bill']->id(), self::now());
            Assert::same(BillStatus::EXTRACTED, $procResult1['bill']->status());

            // File 2: DIFFERENT bytes, so Level 1 passes, but SAME billing period
            $bytes2 = "\x89PNG\r\n\x1a\n" . "SECOND_BILL_DIFFERENT_FILE_" . bin2hex(random_bytes(16));
            $src2 = sys_get_temp_dir() . '/spms-diff-src2-' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($src2, $bytes2);

            $upload2 = BillUpload::fromFilesSpec(
                ['name' => 'bill2.png', 'type' => 'image/png', 'tmp_name' => $src2, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes2)],
                static fn (string $p): bool => is_file($p)
            );

            $result2 = $intake->accept($fixture['account'], $upload2, self::now());
            @unlink($src2);
            self::trackBill($result2['bill']->id());

            // Processing File 2 extracts same period 2026-08-01 to 2026-09-01
            [$ocr2] = self::fakeOcrClient([
                ['status' => 200, 'body' => self::ocrBody(self::validBillAnnotation('2026-08-01', '2026-09-01'))],
            ]);
            $procService2 = new BillProcessingService(new BillService(), $tempRepo, $storage, $ocr2);

            try {
                $procService2->process($fixture['account'], $result2['bill']->id(), self::now());
                Assert::true(false, 'expected DUPLICATE_BILL_PERIOD exception');
            } catch (AppException $e) {
                Assert::same('DUPLICATE_BILL_PERIOD', $e->errorCode());
                Assert::same(409, $e->httpStatus());
                Assert::same('PERIOD', $e->context()['duplicate_type'] ?? null);
                Assert::same($result1['bill']->id(), $e->context()['existing_bill_id'] ?? null);
            }
        });

        // 3. APSPDCL .xls:
        //    - deterministic local parser
        //    - zero Mistral OCR calls
        $tests[] = TestResult::check('3. APSPDCL .xls uses deterministic parser with zero Mistral OCR calls', function (): void {
            $fixture = AuthTesting::householdFixture('xls-reg-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $storage = self::freshStorage();
            $tempRepo = new BillTempDocumentRepository();
            $households = new HouseholdService();
            $billRepo = new BillRepository();

            $serviceNum = self::FIXTURE_SERVICE;
            $xlsContent = <<<HTML
            <!DOCTYPE html><html><body>
            <h2>APSPDCL - Bill Payment History</h2>
            <p>SC No: {$serviceNum}</p>
            <table>
            <tr>
              <th>Bill Date</th><th>Due Date</th>
              <th>Opening Reading</th><th>Closing Reading</th><th>Units</th>
              <th>Net Units</th><th>Bill Amt</th>
            </tr>
            <tr><td>05-06-2025</td><td>20-06-2025</td><td>3150</td><td>3300</td><td>150</td><td>150</td><td>750.00</td></tr>
            <tr><td>05-05-2025</td><td>20-05-2025</td><td>3000</td><td>3150</td><td>150</td><td>150</td><td>730.00</td></tr>
            </table>
            </body></html>
            HTML;

            $src = sys_get_temp_dir() . '/spms-test-export-' . bin2hex(random_bytes(4)) . '.xls';
            file_put_contents($src, $xlsContent);

            $upload = BillUpload::fromFilesSpec(
                ['name' => 'export.xls', 'type' => 'application/vnd.ms-excel', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => strlen($xlsContent)],
                static fn (string $p): bool => is_file($p)
            );

            $intake = new BillUploadIntakeService($tempRepo, $storage, $households, $billRepo);
            $result = $intake->accept($fixture['account'], $upload, self::now());
            @unlink($src);
            self::trackBill($result['bill']->id());

            // Provide a fake OCR client with NO queued responses. If called, it would throw.
            [$ocr, $calls] = self::fakeOcrClient([]);
            $procService = new BillProcessingService(new BillService(), $tempRepo, $storage, $ocr);

            $procResult = $procService->process($fixture['account'], $result['bill']->id(), self::now());

            // Mistral OCR must NEVER have been called
            Assert::same(0, $calls->count(), 'APSPDCL .xls must never invoke Mistral OCR');
            Assert::same(BillStatus::EXTRACTED, $procResult['bill']->status());
            Assert::same('150.000', $procResult['bill']->value('consumption_kwh'));
            Assert::true($procResult['import_summary'] !== null);
        });

        // 4. Genuine PDF/image OCR rate limit:
        //    - still returns EXTRACTION_RATE_LIMITED
        //    - no automatic retries
        $tests[] = TestResult::check('4. genuine OCR rate limit returns EXTRACTION_RATE_LIMITED without auto-retries', function (): void {
            $fixture = AuthTesting::householdFixture('rl-reg-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $storage = self::freshStorage();
            $tempRepo = new BillTempDocumentRepository();
            $households = new HouseholdService();
            $billRepo = new BillRepository();

            $pngBytes = "\x89PNG\r\n\x1a\n" . "GENUINE_RATE_LIMIT_BILL_" . bin2hex(random_bytes(16));
            $src = sys_get_temp_dir() . '/spms-rl-src-' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($src, $pngBytes);

            $upload = BillUpload::fromFilesSpec(
                ['name' => 'bill.png', 'type' => 'image/png', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => strlen($pngBytes)],
                static fn (string $p): bool => is_file($p)
            );

            $intake = new BillUploadIntakeService($tempRepo, $storage, $households, $billRepo);
            $result = $intake->accept($fixture['account'], $upload, self::now());
            @unlink($src);
            self::trackBill($result['bill']->id());

            // Exactly ONE 429 response in queue. If an automatic retry were performed, queue would exhaust.
            [$ocr, $calls] = self::fakeOcrClient([
                ['status' => 429, 'body' => '{"object":"error","message":"Rate limit exceeded","type":"rate_limited","code":"1300"}'],
            ]);
            $procService = new BillProcessingService(new BillService(), $tempRepo, $storage, $ocr);

            try {
                $procService->process($fixture['account'], $result['bill']->id(), self::now());
                Assert::true(false, 'expected EXTRACTION_FAILED');
            } catch (AppException $e) {
                Assert::same('EXTRACTION_FAILED', $e->errorCode());
                Assert::same(502, $e->httpStatus());
                Assert::same('rate_limit', $e->context()['category'] ?? null);
                Assert::same('EXTRACTION_RATE_LIMITED', $e->context()['reason'] ?? null);
            }

            // Exactly 1 call was made — proving NO automatic retries
            Assert::same(1, $calls->count(), 'rate-limited OCR must not enter automatic retry loops');

            // Bill is marked PROCESSING_FAILED
            $updatedBill = $billRepo->find($result['bill']->id(), $fixture['household_id']);
            Assert::same(BillStatus::PROCESSING_FAILED, $updatedBill->status());
        });

        // 5. Household isolation:
        //    - same file hash in another household must not expose or trigger the first household's duplicate state.
        $tests[] = TestResult::check('5. household isolation: same file hash in different household is NOT rejected', function (): void {
            $fixtureA = AuthTesting::householdFixture('iso-a-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $fixtureB = AuthTesting::householdFixture('iso-b-' . bin2hex(random_bytes(4)), '4444-5555-666');
            $storage = self::freshStorage();
            $tempRepo = new BillTempDocumentRepository();
            $households = new HouseholdService();
            $billRepo = new BillRepository();

            $sharedBytes = "\x89PNG\r\n\x1a\n" . "CROSS_HOUSEHOLD_SHARED_IMAGE_BYTES_" . bin2hex(random_bytes(16));

            // Household A uploads the file
            $srcA = sys_get_temp_dir() . '/spms-iso-srcA-' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($srcA, $sharedBytes);
            $uploadA = BillUpload::fromFilesSpec(
                ['name' => 'billA.png', 'type' => 'image/png', 'tmp_name' => $srcA, 'error' => UPLOAD_ERR_OK, 'size' => strlen($sharedBytes)],
                static fn (string $p): bool => is_file($p)
            );

            $intake = new BillUploadIntakeService($tempRepo, $storage, $households, $billRepo);
            $resultA = $intake->accept($fixtureA['account'], $uploadA, self::now());
            @unlink($srcA);
            self::trackBill($resultA['bill']->id());

            // Process and verify bill for Household A
            [$ocrA] = self::fakeOcrClient([
                ['status' => 200, 'body' => self::ocrBody(self::validBillAnnotation('2026-08-01', '2026-09-01'))],
            ]);
            $procA = new BillProcessingService(new BillService(), $tempRepo, $storage, $ocrA);
            $procA->process($fixtureA['account'], $resultA['bill']->id(), self::now());
            (new BillService())->verify($fixtureA['account'], $resultA['bill']->id(), self::now());

            // Household B uploads the SAME EXACT FILE
            $srcB = sys_get_temp_dir() . '/spms-iso-srcB-' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($srcB, $sharedBytes);
            $uploadB = BillUpload::fromFilesSpec(
                ['name' => 'billB.png', 'type' => 'image/png', 'tmp_name' => $srcB, 'error' => UPLOAD_ERR_OK, 'size' => strlen($sharedBytes)],
                static fn (string $p): bool => is_file($p)
            );

            // Must NOT throw DUPLICATE_BILL_FILE because Household B does not own Household A's bill!
            $resultB = $intake->accept($fixtureB['account'], $uploadB, self::now());
            @unlink($srcB);
            self::trackBill($resultB['bill']->id());

            Assert::true($resultB['bill'] instanceof Bill);
            Assert::same(BillStatus::PROCESSING, $resultB['bill']->status());
            Assert::true($resultA['bill']->id() !== $resultB['bill']->id());
        });

        // Cleanup
        $tests[] = TestResult::check('cleanup regression resources', function (): void {
            foreach (self::$createdBills as $id) {
                try {
                    self::pdo()->exec("DELETE FROM bills WHERE id = " . self::pdo()->quote($id));
                } catch (\Throwable) {
                }
            }
            foreach (self::$roots as $root) {
                if (is_dir($root)) {
                    $files = glob($root . '/*');
                    foreach ($files as $f) {
                        @unlink($f);
                    }
                    @rmdir($root);
                }
            }
            Assert::true(true);
        });

        return $tests;
    }
}
