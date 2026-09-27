<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\BillController;
use App\Domain\Account\Account;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillExtractionDraft;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\BillTempDocument;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\Sequence\BillingPeriodSequenceValidator;
use App\Domain\Household\HouseholdService;
use App\Repositories\AccountRepository;
use App\Repositories\BillRepository;
use App\Repositories\BillTempDocumentRepository;
use App\Repositories\HouseholdRepository;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * BillProcessingFailureAndRetryRegressionTest
 *
 * Verifies all 12 regression items specified in Section 8:
 *  1. valid individual supported bill reaches extraction/review
 *  2. extraction provider failure reaches FAILED
 *  3. extraction timeout reaches FAILED
 *  4. malformed provider response reaches FAILED
 *  5. failed processing attempt does not appear in billing history
 *  6. failed processing attempt does not increase statement count
 *  7. failed processing attempt does not appear in navigator
 *  8. retry does not duplicate history
 *  9. successful retry creates exactly one historical bill
 * 10. genuine recognized duplicate billing period still follows existing deduplication rules
 * 11. existing APSPDCL XLS multi-row import remains unchanged
 * 12. billing gap detection ignores failed processing attempts
 */
final class BillProcessingFailureAndRetryRegressionTest
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
        $root = sys_get_temp_dir() . '/spms-retry-root-' . bin2hex(random_bytes(5));
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

    private static function validBillAnnotation(array $overrides = []): array
    {
        $annotation = [
            'document_assessment' => ['status' => 'VALID', 'reason' => 'clear bill', 'confidence' => 0.99],
            'billing_period' => ['start' => '2026-08-01', 'end' => '2026-09-01', 'confidence' => 0.95],
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

        foreach ($overrides as $k => $v) {
            $annotation[$k] = $v;
        }

        return $annotation;
    }

    private static function processingBillFixture(string $prefix = 'reg', string $kind = 'png'): array
    {
        $fixture = AuthTesting::householdFixture($prefix . '-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
        $storage = self::freshStorage();

        $src = sys_get_temp_dir() . '/spms-reg-src-' . bin2hex(random_bytes(5)) . ($kind === 'pdf' ? '.pdf' : '.png');
        file_put_contents($src, "\x89PNG\r\n\x1a\n" . random_bytes(200));

        $upload = BillUpload::fromFilesSpec(
            ['name' => 'bill.' . $kind, 'type' => 'application/octet-stream', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => 1],
            static fn (string $p): bool => is_file($p)
        );

        $result = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService()))
            ->accept($fixture['account'], $upload, self::now());
        @unlink($src);

        self::trackBill($result['bill']->id());

        return [
            'account' => $fixture['account'],
            'household_id' => $fixture['household_id'],
            'bill' => $result['bill']->id(),
            'temp' => $result['temp'],
            'storage' => $storage,
        ];
    }

    public static function run(): array
    {
        $tests = [];

        // 1. valid individual supported bill reaches extraction/review
        $tests[] = TestResult::check('1. valid individual supported bill reaches extraction/review', function (): void {
            $ctx = self::processingBillFixture('reg-1');
            [$ocr] = self::fakeOcrClient([
                ['status' => 200, 'body' => self::ocrBody(self::validBillAnnotation())],
            ]);
            $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

            $result = $service->process($ctx['account'], $ctx['bill'], self::now());
            Assert::same(BillStatus::EXTRACTED, $result['bill']->status());
            Assert::same('2026-08-01', $result['bill']->value(BillField::BILLING_PERIOD_START));
            Assert::same('2026-09-01', $result['bill']->value(BillField::BILLING_PERIOD_END));
            Assert::same('145.000', $result['bill']->value('consumption_kwh'));

            // Appears in repository list
            $repo = new BillRepository();
            $list = $repo->listForHousehold($ctx['household_id']);
            $ids = array_map(static fn (Bill $b): string => $b->id(), $list);
            Assert::true(in_array($ctx['bill'], $ids, true));
        });

        // 2. extraction provider failure reaches FAILED
        $tests[] = TestResult::check('2. extraction provider failure reaches FAILED with structured error code', function (): void {
            $ctx = self::processingBillFixture('reg-2');
            [$ocr] = self::fakeOcrClient([
                ['status' => 429, 'body' => '{"object":"error","message":"Rate limit exceeded","type":"rate_limited","code":"1300"}'],
            ]);
            $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

            try {
                $service->process($ctx['account'], $ctx['bill'], self::now());
                Assert::true(false, 'expected EXTRACTION_FAILED');
            } catch (AppException $e) {
                Assert::same('EXTRACTION_FAILED', $e->errorCode());
                Assert::same(502, $e->httpStatus());
                Assert::same('rate_limit', $e->context()['category'] ?? null);
                Assert::same('EXTRACTION_RATE_LIMITED', $e->context()['reason'] ?? null);
            }

            $repo = new BillRepository();
            $bill = $repo->find($ctx['bill'], $ctx['household_id']);
            Assert::true($bill !== null);
            Assert::same(BillStatus::PROCESSING_FAILED, $bill->status());
        });

        // 3. extraction timeout reaches FAILED
        $tests[] = TestResult::check('3. extraction timeout reaches FAILED with EXTRACTION_TIMEOUT', function (): void {
            $ctx = self::processingBillFixture('reg-3');
            $broken = new MistralOcrClient('mk_FAKE', 'm', static function (): array {
                throw new \RuntimeException('Operation timed out after 30000 milliseconds');
            });
            $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $broken);

            try {
                $service->process($ctx['account'], $ctx['bill'], self::now());
                Assert::true(false, 'expected EXTRACTION_FAILED');
            } catch (AppException $e) {
                Assert::same('EXTRACTION_FAILED', $e->errorCode());
                Assert::same('EXTRACTION_TIMEOUT', $e->context()['reason'] ?? null);
            }

            $repo = new BillRepository();
            $bill = $repo->find($ctx['bill'], $ctx['household_id']);
            Assert::same(BillStatus::PROCESSING_FAILED, $bill->status());
        });

        // 4. malformed provider response reaches FAILED
        $tests[] = TestResult::check('4. malformed provider response reaches FAILED', function (): void {
            $ctx = self::processingBillFixture('reg-4');
            [$ocr] = self::fakeOcrClient([
                ['status' => 200, 'body' => '{"pages":[],"document_annotation":"{not valid json"'],
            ]);
            $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

            try {
                $service->process($ctx['account'], $ctx['bill'], self::now());
                Assert::true(false, 'expected EXTRACTION_FAILED');
            } catch (AppException $e) {
                Assert::same('EXTRACTION_FAILED', $e->errorCode());
            }

            $repo = new BillRepository();
            $bill = $repo->find($ctx['bill'], $ctx['household_id']);
            Assert::same(BillStatus::PROCESSING_FAILED, $bill->status());
        });

        // 5. failed processing attempt does not appear in billing history
        $tests[] = TestResult::check('5. failed processing attempt does not appear in billing history (listForHousehold and GET /api/v1/bills)', function (): void {
            $ctx = self::processingBillFixture('reg-5');
            [$ocr] = self::fakeOcrClient([
                ['status' => 500, 'body' => '{"message":"provider error"}'],
            ]);
            $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

            try {
                $service->process($ctx['account'], $ctx['bill'], self::now());
            } catch (AppException) {
            }

            $repo = new BillRepository();
            $list = $repo->listForHousehold($ctx['household_id']);
            $ids = array_map(static fn (Bill $b): string => $b->id(), $list);
            Assert::false(in_array($ctx['bill'], $ids, true), 'failed processing attempt must NOT be in listForHousehold');

            // API read surface
            $controller = new BillController(
                new BillUploadIntakeService(new BillTempDocumentRepository(), $ctx['storage'], new HouseholdService()),
                $service,
                new BillService(),
                new BillTempDocumentRepository(),
                new HouseholdService()
            );

            $res = $controller->index(Request::create('GET', '/api/v1/bills')->withAuthContext(AuthTesting::contextFor($ctx['account'])));
            $apiBills = $res->body()['data']['bills'];
            $apiIds = array_map(static fn (array $b): string => $b['bill_id'], $apiBills);
            Assert::false(in_array($ctx['bill'], $apiIds, true), 'failed processing attempt must NOT be in GET /api/v1/bills');
        });

        // 6. failed processing attempt does not increase statement count
        $tests[] = TestResult::check('6. failed processing attempt does not increase statement count', function (): void {
            $fixture = AuthTesting::householdFixture('reg-6-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $repo = new BillRepository();
            $billService = new BillService();

            // Create a valid extracted bill first
            $b1 = $billService->create($fixture['account'], '2026-07-01', '2026-08-01', self::now());
            self::trackBill($b1->id());
            $b1 = $billService->markProcessing($fixture['account'], $b1->id(), self::now());
            $b1 = $billService->recordExtraction($fixture['account'], $b1->id(), BillExtractionDraft::create(
                $b1->id(), 'mistral', 'm', self::now(), DocumentAssessment::VALID, null, '0.99',
                [BillField::BILLING_PERIOD_START => '2026-07-01', BillField::BILLING_PERIOD_END => '2026-08-01', BillField::SERVICE_NUMBER => self::FIXTURE_SERVICE, 'consumption_kwh' => '100.000']
            ), [], [], self::now());

            $countBefore = count($repo->listForHousehold($fixture['household_id']));
            Assert::same(1, $countBefore);

            // Now create a bill that fails processing
            $bFail = $billService->create($fixture['account'], null, null, self::now());
            self::trackBill($bFail->id());
            $bFail = $billService->markProcessing($fixture['account'], $bFail->id(), self::now());
            $bFail = $billService->markProcessingFailed($fixture['account'], $bFail->id(), self::now());

            $countAfter = count($repo->listForHousehold($fixture['household_id']));
            Assert::same(1, $countAfter, 'statement count must remain exactly 1 despite failed upload attempt');
        });

        // 7. failed processing attempt does not appear in navigator
        $tests[] = TestResult::check('7. failed processing attempt does not appear in navigator', function (): void {
            $fixture = AuthTesting::householdFixture('reg-7-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $repo = new BillRepository();
            $billService = new BillService();

            // Bill with period
            $b1 = $billService->create($fixture['account'], '2026-07-01', '2026-08-01', self::now());
            self::trackBill($b1->id());
            $b1 = $billService->markProcessing($fixture['account'], $b1->id(), self::now());
            $b1 = $billService->recordExtraction($fixture['account'], $b1->id(), BillExtractionDraft::create(
                $b1->id(), 'mistral', 'm', self::now(), DocumentAssessment::VALID, null, '0.99',
                [BillField::BILLING_PERIOD_START => '2026-07-01', BillField::BILLING_PERIOD_END => '2026-08-01', BillField::SERVICE_NUMBER => self::FIXTURE_SERVICE]
            ), [], [], self::now());

            // Failed bill
            $bFail = $billService->create($fixture['account'], null, null, self::now());
            self::trackBill($bFail->id());
            $bFail = $billService->markProcessing($fixture['account'], $bFail->id(), self::now());
            $bFail = $billService->markProcessingFailed($fixture['account'], $bFail->id(), self::now());

            $list = $repo->listForHousehold($fixture['household_id']);
            foreach ($list as $b) {
                Assert::true($b->status() !== BillStatus::PROCESSING_FAILED);
                Assert::true($b->value(BillField::BILLING_PERIOD_START) !== null);
            }
        });

        // 8. retry does not duplicate history
        $tests[] = TestResult::check('8. retry does not duplicate history (idempotent)', function (): void {
            $ctx = self::processingBillFixture('reg-8');
            [$ocrFail] = self::fakeOcrClient([
                ['status' => 500, 'body' => '{"message":"transient error"}'],
            ]);
            $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocrFail);

            try {
                $service->process($ctx['account'], $ctx['bill'], self::now());
            } catch (AppException) {
            }

            $repo = new BillRepository();
            Assert::same(BillStatus::PROCESSING_FAILED, $repo->find($ctx['bill'], $ctx['household_id'])->status());

            // Retry 1: fails again
            [$ocrFail2] = self::fakeOcrClient([
                ['status' => 500, 'body' => '{"message":"still down"}'],
            ]);
            $service2 = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocrFail2);
            try {
                $service2->process($ctx['account'], $ctx['bill'], self::now());
            } catch (AppException) {
            }

            Assert::same(BillStatus::PROCESSING_FAILED, $repo->find($ctx['bill'], $ctx['household_id'])->status());
            $count = count($repo->listForHousehold($ctx['household_id']));
            Assert::same(0, $count, 'zero statements in history during multiple failed attempts');
        });

        // 9. successful retry creates exactly one historical bill
        $tests[] = TestResult::check('9. successful retry creates exactly one historical bill', function (): void {
            $ctx = self::processingBillFixture('reg-9');
            [$ocrFail] = self::fakeOcrClient([
                ['status' => 500, 'body' => '{"message":"transient error"}'],
            ]);
            $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocrFail);

            try {
                $service->process($ctx['account'], $ctx['bill'], self::now());
            } catch (AppException) {
            }

            $repo = new BillRepository();
            Assert::same(BillStatus::PROCESSING_FAILED, $repo->find($ctx['bill'], $ctx['household_id'])->status());
            Assert::same(0, count($repo->listForHousehold($ctx['household_id'])));

            // Retry succeeds!
            [$ocrSuccess] = self::fakeOcrClient([
                ['status' => 200, 'body' => self::ocrBody(self::validBillAnnotation())],
            ]);
            $serviceSuccess = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocrSuccess);

            $result = $serviceSuccess->process($ctx['account'], $ctx['bill'], self::now());
            Assert::same(BillStatus::EXTRACTED, $result['bill']->status());
            Assert::same($ctx['bill'], $result['bill']->id());

            // Now exactly one bill exists in history
            $list = $repo->listForHousehold($ctx['household_id']);
            Assert::same(1, count($list), 'exactly one historical bill created on successful retry');
            Assert::same($ctx['bill'], $list[0]->id());
        });

        // 10. genuine recognized duplicate billing period still follows existing deduplication rules
        $tests[] = TestResult::check('10. genuine recognized duplicate billing period follows deduplication rules', function (): void {
            $fixture = AuthTesting::householdFixture('reg-10-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $service = new BillService();
            $repo = new BillRepository();

            // Bill 1: unverified for Aug 2026
            $b1 = $service->create($fixture['account'], '2026-08-01', '2026-09-01', self::now());
            self::trackBill($b1->id());
            $b1 = $service->markProcessing($fixture['account'], $b1->id(), self::now());
            $b1 = $service->recordExtraction($fixture['account'], $b1->id(), BillExtractionDraft::create(
                $b1->id(), 'm', 'm', self::now(), DocumentAssessment::VALID, null, '0.99',
                [BillField::BILLING_PERIOD_START => '2026-08-01', BillField::BILLING_PERIOD_END => '2026-08-01', BillField::SERVICE_NUMBER => self::FIXTURE_SERVICE, 'consumption_kwh' => '120.000']
            ), [], [], self::now());

            // Bill 2: second unverified for same period
            $b2 = $service->create($fixture['account'], '2026-08-01', '2026-09-01', self::now());
            self::trackBill($b2->id());
            $b2 = $service->markProcessing($fixture['account'], $b2->id(), self::now());
            $b2 = $service->recordExtraction($fixture['account'], $b2->id(), BillExtractionDraft::create(
                $b2->id(), 'm', 'm', self::now(), DocumentAssessment::VALID, null, '0.99',
                [BillField::BILLING_PERIOD_START => '2026-08-01', BillField::BILLING_PERIOD_END => '2026-08-01', BillField::SERVICE_NUMBER => self::FIXTURE_SERVICE, 'consumption_kwh' => '120.000']
            ), [], [], self::now());

            $list = $repo->listForHousehold($fixture['household_id']);
            Assert::same(1, count($list), 'two unverified compatible duplicate period bills deduplicate to one statement');
        });

        // 11. existing APSPDCL XLS multi-row import remains unchanged
        $tests[] = TestResult::check('11. existing APSPDCL XLS multi-row import remains unchanged', function (): void {
            $fixture = AuthTesting::householdFixture('reg-11-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $storage = self::freshStorage();

            $serviceNum = self::FIXTURE_SERVICE;
            $src = sys_get_temp_dir() . '/spms-reg-xls-' . bin2hex(random_bytes(5)) . '.xls';
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
            file_put_contents($src, $xlsContent);

            $upload = BillUpload::fromFilesSpec(
                ['name' => 'export.xls', 'type' => 'text/html', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => strlen($xlsContent)],
                static fn (string $p): bool => is_file($p)
            );

            $result = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService()))
                ->accept($fixture['account'], $upload, self::now());
            @unlink($src);

            self::trackBill($result['bill']->id());

            [$ocr] = self::fakeOcrClient([]);
            $procService = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $storage, $ocr);

            $procResult = $procService->process($fixture['account'], $result['bill']->id(), self::now());
            Assert::true(($procResult['import_summary'] ?? null) !== null);
            Assert::same(2, $procResult['import_summary']['newly_imported']);

            $repo = new BillRepository();
            $list = $repo->listForHousehold($fixture['household_id']);
            Assert::same(2, count($list), 'APSPDCL XLS creates both monthly statements');
            foreach ($list as $b) {
                self::trackBill($b->id());
            }
        });

        // 12. billing gap detection ignores failed processing attempts
        $tests[] = TestResult::check('12. billing gap detection ignores failed processing attempts', function (): void {
            $fixture = AuthTesting::householdFixture('reg-12-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $service = new BillService();
            $repo = new BillRepository();

            // First bill: June 2026
            $b1 = $service->create($fixture['account'], '2026-06-01', '2026-07-01', self::now());
            self::trackBill($b1->id());
            $b1 = $service->markProcessing($fixture['account'], $b1->id(), self::now());
            $b1 = $service->recordExtraction($fixture['account'], $b1->id(), BillExtractionDraft::create(
                $b1->id(), 'm', 'm', self::now(), DocumentAssessment::VALID, null, '0.99',
                [BillField::BILLING_PERIOD_START => '2026-06-01', BillField::BILLING_PERIOD_END => '2026-07-01', BillField::SERVICE_NUMBER => self::FIXTURE_SERVICE]
            ), [], [], self::now());

            // A failed upload attempt exists in the system
            $bFail = $service->create($fixture['account'], null, null, self::now());
            self::trackBill($bFail->id());
            $bFail = $service->markProcessing($fixture['account'], $bFail->id(), self::now());
            $bFail = $service->markProcessingFailed($fixture['account'], $bFail->id(), self::now());

            // Next bill: July 2026 (contiguous with June!)
            $b2 = $service->create($fixture['account'], '2026-07-01', '2026-08-01', self::now());
            self::trackBill($b2->id());
            $b2 = $service->markProcessing($fixture['account'], $b2->id(), self::now());
            $b2 = $service->recordExtraction($fixture['account'], $b2->id(), BillExtractionDraft::create(
                $b2->id(), 'm', 'm', self::now(), DocumentAssessment::VALID, null, '0.99',
                [BillField::BILLING_PERIOD_START => '2026-07-01', BillField::BILLING_PERIOD_END => '2026-08-01', BillField::SERVICE_NUMBER => self::FIXTURE_SERVICE]
            ), [], [], self::now());

            // Sequence validation for b2 should be CONTIGUOUS, not affected by failed attempt!
            $seq = $service->sequenceValidationFor($fixture['account'], $b2->id());
            Assert::same(BillingPeriodSequenceValidator::CONTIGUOUS, $seq->status());
        });

        // Cleanup created test bills
        $pdo = self::pdo();
        foreach (self::$createdBills as $id) {
            $pdo->prepare('DELETE FROM bills WHERE id = ?')->execute([$id]);
        }
        self::$createdBills = [];

        // Cleanup temporary storage
        foreach (self::$roots as $root) {
            if (is_dir($root)) {
                $files = glob($root . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) unlink($file);
                }
                @rmdir($root);
            }
        }
        self::$roots = [];

        return $tests;
    }
}
