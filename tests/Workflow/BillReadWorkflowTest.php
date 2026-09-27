<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Controllers\BillController;
use App\Controllers\HouseholdController;
use App\Domain\Account\Account;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use App\Domain\Household\HouseholdService;
use App\Repositories\AccountRepository;
use App\Repositories\BillTempDocumentRepository;
use App\Security\AuthMiddleware;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\AppException;
use App\Support\Database;
use App\Support\MethodNotAllowedException;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * B4 — Bill read surface workflow (GET /api/v1/bills, GET /api/v1/bills/{id}).
 *
 * REAL intake + REAL fake-Mistral extraction (the ratified B3 pattern —
 * automated runs can never contact the provider) feeding the REAL
 * household-scoped controller reads. Asserts exactly what the bills
 * workspace is allowed to know: the whitelisted review model verbatim
 * (including the persisted +177 that a CONFLICT reports but nothing
 * corrects), honest nulls for everything absent, indistinguishable 404
 * for foreign ids, 401 for anonymous callers, and a response body that
 * never leaks a path, storage key, filename or provider payload marker.
 */
final class BillReadWorkflowTest
{
    /** @var list<string> */
    private static array $roots = [];

    /** @var list<string> */
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
        $root = sys_get_temp_dir() . '/spms-b4-root-' . bin2hex(random_bytes(5));
        self::$roots[] = $root;

        return new LocalPrivateBillStorage($root);
    }

    private static function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n" . random_bytes(200);
    }

    /**
     * @param array<int, array{status:int, body:string}> $queue
     * @return array{0: MistralOcrClient, 1: \ArrayObject<int, array{method:string,url:string,options:array<string,mixed>}>}
     */
    private static function fakeOcrClient(array $queue): array
    {
        $calls = new \ArrayObject();
        $handler = static function (string $method, string $url, array $options) use (&$queue, $calls): array {
            $calls->append(['method' => $method, 'url' => $url, 'options' => $options]);
            $next = array_shift($queue);
            if ($next === null) {
                throw new RuntimeException('test handler queue exhausted');
            }

            return $next;
        };

        return [new MistralOcrClient('mk_FAKE-B4-never-real', 'mistral-ocr-test', $handler), $calls];
    }

    /**
     * @param array<string, mixed> $annotation
     */
    private static function ocrBody(array $annotation, string $model = 'mistral-ocr-2506'): string
    {
        $payload = [
            'model' => $model,
            'pages' => [[
                'index' => 0,
                'markdown' => 'RAWMARKER-PAGE-MARKDOWN [tbl-0](tbl-0)',
                'images' => [],
                'tables' => [[
                    'id' => 'tbl-0',
                    'content' => "KWH | 1460 1315\nSOLARKWH | 2200 1878\nBILLED UNITS | 145.0 172.0",
                    'format' => 'markdown',
                ]],
            ]],
            'usage_info' => ['input_tokens' => 1200, 'output_tokens' => 340],
        ];

        if ($annotation !== []) {
            $payload['document_annotation'] = json_encode($annotation);
        }

        return (string) json_encode($payload);
    }

    /**
     * D-071: the fixture household registers this service number and the
     * default annotation echoes it, so the read-surface fixtures model an
     * ownership-MATCH bill (EXTRACTED), while explicit override tests can
     * drive the mismatch/missing outcomes.
     */
    private const FIXTURE_SERVICE = '1000-2000-300';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function liveShape(array $overrides = []): array
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
                'utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)',
                'meter_type' => 'SMART SOLAR', 'contracted_load' => '3',
                'service_number' => self::FIXTURE_SERVICE, 'confidence' => 0.95,
            ],
        ];

        foreach ($overrides as $section => $value) {
            $annotation[$section] = $value;
        }

        return $annotation;
    }

    /**
     * One intake through the REAL B2 pipeline. Pass $fixture to place
     * several bills inside one household; omit it for a fresh fixture.
     *
     * @param array{account: Account, household_id: string}|null $fixture
     * @return array{account: Account, household: string, bill: string, storage: LocalPrivateBillStorage}
     */
    private static function processingBill(string $prefix = 'b4', ?array $fixture = null): array
    {
        $fixture ??= AuthTesting::householdFixture($prefix . '-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
        $storage = self::freshStorage();

        $src = sys_get_temp_dir() . '/spms-b4-src-' . bin2hex(random_bytes(5)) . '.png';
        file_put_contents($src, self::pngBytes());

        $upload = BillUpload::fromFilesSpec(
            ['name' => 'original-household-bill.png', 'type' => 'application/octet-stream', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => 1],
            static fn (string $p): bool => is_file($p)
        );

        $result = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService()))
            ->accept($fixture['account'], $upload, self::now());
        @unlink($src);

        self::$createdBills[] = $result['bill']->id();

        return [
            'account' => $fixture['account'],
            'household' => $fixture['household_id'],
            'bill' => $result['bill']->id(),
            'storage' => $storage,
        ];
    }

    /**
     * PROCESSING bill driven through the fake-Mistral pipeline.
     *
     * @param array<string, mixed> $annotationOverrides
     * @return array{account: Account, bill: string, status: string, storage: LocalPrivateBillStorage}
     */
    private static function extractedBill(array $annotationOverrides): array
    {
        $ctx = self::processingBill();
        [$ocr] = self::fakeOcrClient([['status' => 200, 'body' => self::ocrBody(self::liveShape($annotationOverrides))]]);

        $service = new BillProcessingService(
            new BillService(),
            new BillTempDocumentRepository(),
            $ctx['storage'],
            $ocr
        );

        $result = $service->process($ctx['account'], $ctx['bill']);

        return [
            'account' => $ctx['account'],
            'bill' => $result['bill']->id(),
            'status' => $result['bill']->status(),
            'storage' => $ctx['storage'],
        ];
    }

    private static function controller(): BillController
    {
        return new BillController();
    }

    /**
     * @return array<string, mixed> the decoded data envelope of a show() call
     */
    private static function show(Account $account, string $billId): array
    {
        $response = self::controller()->show(
            Request::create('GET', '/api/v1/bills/' . $billId)
                ->withAuthContext(AuthTesting::contextFor($account))
        );

        Assert::same(200, $response->status());

        return $response->body()['data'];
    }

    public static function run(): array
    {
        $tests = [];

        $tests[] = TestResult::check('anonymous reads are rejected on both endpoints', function (): void {
            try {
                self::controller()->index(Request::create('GET', '/api/v1/bills'));
                Assert::true(false, 'anonymous list must be rejected');
            } catch (AppException $e) {
                Assert::same('UNAUTHENTICATED', $e->errorCode());
                Assert::same(401, $e->httpStatus());
            }

            try {
                self::controller()->show(Request::create('GET', '/api/v1/bills/' . self::uuidShape()));
                Assert::true(false, 'anonymous detail must be rejected');
            } catch (AppException $e) {
                Assert::same('UNAUTHENTICATED', $e->errorCode());
                Assert::same(401, $e->httpStatus());
            }
        });

        $tests[] = TestResult::check('the list is household-scoped, newest-first and summary-shaped', function (): void {
            $fixture = AuthTesting::householdFixture('b4-list-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $older = self::processingBill('b4-old', $fixture);
            $newer = self::processingBill('b4-new', $fixture);
            $foreign = self::processingBill('b4-far');

            // created_at is second-precision; pin the older row in the
            // past so the ordering assertion is deterministic.
            self::pdo()
                ->prepare("UPDATE bills SET created_at = '2020-01-01 00:00:00' WHERE id = :id")
                ->execute(['id' => $older['bill']]);

            $response = self::controller()->index(
                Request::create('GET', '/api/v1/bills')->withAuthContext(AuthTesting::contextFor($fixture['account']))
            );

            Assert::same(200, $response->status());
            $rows = $response->body()['data']['bills'];
            $ids = array_map(static fn (array $r): string => $r['bill_id'], $rows);

            Assert::same([$newer['bill'], $older['bill']], $ids, 'own bills only, newest first');
            Assert::true(!in_array($foreign['bill'], $ids, true), 'a foreign household bill never appears');

            $row = $rows[0];
            Assert::same(
                [
                    'bill_id', 'status', 'source', 'document_type', 'service_number', 'source_statement_serial', 'solar_applicability',
                    'billing_period_start', 'billing_period_end',
                    'bill_date', 'due_date',
                    'consumption_kwh', 'solar_units_kwh', 'net_units_kwh',
                    'bill_amount', 'total_due', 'verified_at', 'updated_at',
                ],
                array_keys($row),
                'the list row carries exactly the review/history summary fields'
            );
            Assert::same(BillStatus::PROCESSING, $row['status']);
            Assert::true($row['verified_at'] === null, 'an unverified bill has no verification stamp');
            Assert::true($row['consumption_kwh'] === null, 'unextracted values are honest nulls, never zero');

            $body = json_encode($response->body()) ?: '';
            foreach (['household', 'storage_key', $older['storage']->rootDir()] as $forbidden) {
                Assert::notContains($forbidden, $body);
            }
        });

        $tests[] = TestResult::check('detail exposes the full whitelisted review model verbatim', function (): void {
            $ctx = self::extractedBill([]);
            $bill = self::show($ctx['account'], $ctx['bill'])['bill'];

            Assert::same($ctx['bill'], $bill['bill_id']);
            Assert::same(BillStatus::EXTRACTED, $bill['status']);
            Assert::true($bill['verified_at'] === null, 'EXTRACTED is not VERIFIED — no stamp exists');
            Assert::same(BillField::ALL, array_keys($bill['values']), 'exactly the whitelisted fields');
            Assert::same('145.000', $bill['values']['consumption_kwh']);
            Assert::same('-177.000', $bill['values']['net_units_kwh']);
            Assert::true($bill['values']['bill_amount'] === null, 'un-extracted costs stay null (no zero-fill)');
            Assert::same('30.00', $bill['values']['fixed_charges']);
            Assert::same('DOMESTIC (A)', $bill['values']['tariff_category']);
            Assert::same('10002000300', $bill['values']['service_number'], 'canonical service number on the review values');
            $ownership = $bill['ownership'];
            Assert::same('MATCH', $ownership['status'], 'D-071: matching evidence is the only thing that reads as MATCH');
            Assert::same('10002000300', $ownership['bill_service_number']);
            Assert::same(str_repeat('•', 7) . '0300', $ownership['household_service_number_masked'], 'registered number is only ever shown masked here');
            // D-071 §N: the full REGISTERED identifier never rides this
            // payload — masked or absent only. The bill's OWN extracted
            // number is the owner's document value and stays visible.
            Assert::true(!str_contains(json_encode($ownership), 'household_service_number":'), 'no unmasked household_service_number key exists');
            Assert::same('•••••••0300', $ownership['household_service_number_masked']);

            Assert::same('VALID', $bill['extraction']['assessment']);
            Assert::same('0.9900', $bill['extraction']['document_confidence']);

            $rules = array_map(static fn (array $v): string => $v['rule'], $bill['validations']);
            $expectedRules = ValidationRule::ALL;
            sort($expectedRules);
            sort($rules);
            Assert::same($expectedRules, $rules);
            foreach ($bill['validations'] as $v) {
                Assert::same(ValidationStatus::PASS, $v['status']);
            }

            Assert::same([], $bill['quality_flags'], 'a clean extraction carries no flags');

            $provenance = $bill['provenance'];
            Assert::same('AI_EXTRACTION', $provenance['consumption_kwh']['source']);
            Assert::same('0.9800', $provenance['consumption_kwh']['confidence']);
            Assert::true(!array_key_exists('bill_amount', $provenance), 'no provenance row for data that does not exist');

            $body = json_encode(self::show($ctx['account'], $ctx['bill'])) ?: '';
            foreach ([$ctx['storage']->rootDir(), 'RAWMARKER', 'storage_key', 'original-household-bill', 'household_id', 'extraction_provider'] as $forbidden) {
                Assert::notContains($forbidden, $body);
            }
        });

        $tests[] = TestResult::check('a review bill reports the persisted +177 verbatim beside its CONFLICT', function (): void {
            $ctx = self::extractedBill([
                'solar' => [
                    'previous_solar_reading' => 1878, 'present_solar_reading' => 2200,
                    'solar_units_kwh' => 322, 'net_units_kwh' => 177, 'confidence' => 0.97,
                ],
                'cost' => [
                    'bill_amount' => 180, 'arrears' => -105, 'total_due' => 75, 'energy_charges' => null,
                    'fixed_charges' => 30, 'customer_service_charges' => 25, 'other_charges' => 45,
                    'taxes_adjustments' => 108.56, 'confidence' => 0.9,
                ],
            ]);

            $bill = self::show($ctx['account'], $ctx['bill'])['bill'];

            Assert::same(BillStatus::NEEDS_REVIEW, $bill['status']);
            Assert::same('177.000', $bill['values']['net_units_kwh'], 'the read surface reports what was persisted — never a corrected -177');

            $net = null;
            foreach ($bill['validations'] as $v) {
                if ($v['rule'] === ValidationRule::NET_UNITS) {
                    $net = $v;
                }
            }
            Assert::true($net !== null);
            Assert::same(ValidationStatus::CONFLICT, $net['status']);
            Assert::same('-177.000', $net['expected_value']);
            Assert::same('177.000', $net['extracted_value']);

            Assert::same(
                [['code' => 'INCONSISTENT_NET_UNITS', 'field_name' => 'net_units_kwh']],
                $bill['quality_flags']
            );

            // The -177 exists exactly once anywhere in the payload: as the
            // validation's expected value. The bill's own values object is
            // +177 — the read surface cannot and does not correct.
            $body = json_encode($bill) ?: '';
            Assert::notContains('-177', json_encode($bill['values']) ?: '');
            Assert::true(str_contains($body, '"expected_value":"-177.000"'), 'the expectation is visible inside the validation record');
        });

        $tests[] = TestResult::check('D-071 ownership states read back exactly as the backend recorded them', function (): void {
            // MISMATCH: another service's number on the bill.
            $mismatch = self::extractedBill([
                'context' => [
                    'utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)',
                    'meter_type' => 'SMART SOLAR', 'contracted_load' => '3',
                    'service_number' => '777 777', 'confidence' => 0.95,
                ],
            ]);
            $bill = self::show($mismatch['account'], $mismatch['bill'])['bill'];
            Assert::same('NEEDS_REVIEW', $bill['status']);
            Assert::same('MISMATCH', $bill['ownership']['status']);
            Assert::same('777777', $bill['ownership']['bill_service_number']);

            // NOT_ON_BILL: document carried nothing confirmable.
            $missing = self::extractedBill([
                'context' => [
                    'utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)',
                    'meter_type' => 'SMART SOLAR', 'contracted_load' => '3',
                    'service_number' => null, 'confidence' => 0.9,
                ],
            ]);
            $bill = self::show($missing['account'], $missing['bill'])['bill'];
            Assert::same('NOT_ON_BILL', $bill['ownership']['status']);
            Assert::true($bill['ownership']['bill_service_number'] === null);

            // HOUSEHOLD_NOT_CONFIGURED: a fresh household with no anchor.
            $bareFixture = AuthTesting::householdFixture('b4-nocfg-' . bin2hex(random_bytes(4)));
            $nocfg = self::processingBill('b4-nocfg', $bareFixture);
            [$ocr] = self::fakeOcrClient([['status' => 200, 'body' => self::ocrBody(self::liveShape())]]);
            (new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $nocfg['storage'], $ocr))
                ->process($nocfg['account'], $nocfg['bill']);
            $bill = self::show($nocfg['account'], $nocfg['bill'])['bill'];
            Assert::same('HOUSEHOLD_NOT_CONFIGURED', $bill['ownership']['status']);
        });

        $tests[] = TestResult::check('a PROCESSING bill reads honestly: no draft, no values, empty evidence', function (): void {
            $ctx = self::processingBill();

            $bill = self::show($ctx['account'], $ctx['bill'])['bill'];

            Assert::same(BillStatus::PROCESSING, $bill['status']);
            Assert::true($bill['extraction'] === null, 'no extraction has happened — no extraction facts are invented');
            Assert::true($bill['ownership'] === null, 'no ownership story before extraction — null, never a default');
            Assert::same([], $bill['validations']);
            Assert::same([], $bill['quality_flags']);
            Assert::same([], $bill['provenance']);
            foreach (BillField::ALL as $field) {
                Assert::true($bill['values'][$field] === null, 'every value is an honest null: ' . $field);
            }
        });

        $tests[] = TestResult::check('foreign and malformed ids are the same indistinguishable 404', function (): void {
            $ctx = self::processingBill();
            $other = AuthTesting::householdFixture('b4-other-' . bin2hex(random_bytes(4)));

            foreach ([
                Request::create('GET', '/api/v1/bills/' . $ctx['bill'])->withAuthContext(AuthTesting::contextFor($other['account'])),
                Request::create('GET', '/api/v1/bills/not-a-uuid')->withAuthContext(AuthTesting::contextFor($ctx['account'])),
                Request::create('GET', '/api/v1/bills/' . self::uuidShape())->withAuthContext(AuthTesting::contextFor($ctx['account'])),
                Request::create('GET', '/api/v1/bills/upload')->withAuthContext(AuthTesting::contextFor($ctx['account'])),
            ] as $request) {
                try {
                    self::controller()->show($request);
                    Assert::true(false, 'must 404: ' . $request->path());
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }
        });

        $tests[] = TestResult::check('GET reads pass the real auth pipeline without CSRF (non-mutating)', function (): void {
            RequestId::set(RequestId::new());
            AuthTesting::configure();

            $auth = new AuthController();
            $email = AuthTesting::uniqueEmail();
            $auth->register(Request::create('POST', '/api/v1/auth/register', body: [
                'email' => $email, 'password' => 'correct-horse-battery',
            ], ipAddress: AuthTesting::uniqueIpAddress()));
            $token = AuthTesting::latestMailCode('Verify your email');
            $auth->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token]));
            $login = $auth->login(Request::create('POST', '/api/v1/auth/login', body: [
                'email' => $email, 'password' => 'correct-horse-battery',
            ], ipAddress: AuthTesting::uniqueIpAddress()));
            $sessionToken = (string) AuthTesting::sessionTokenFrom($login);

            $account = (new AccountRepository())->findByEmail(strtolower($email));
            Assert::true($account instanceof Account);

            $household = new HouseholdController();
            $household->create(Request::create('POST', '/api/v1/household', body: [
                'timezone' => 'Asia/Kolkata',
                'label' => 'b4-read-home',
            ], ipAddress: AuthTesting::uniqueIpAddress())->withAuthContext(AuthTesting::contextFor($account)));

            $pipelined = AuthMiddleware::process(Request::create(
                'GET',
                '/api/v1/bills',
                headers: ['Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $sessionToken],
                ipAddress: AuthTesting::uniqueIpAddress()
            ));

            $response = self::controller()->index($pipelined);
            Assert::same(200, $response->status());
            Assert::same(['bills'], array_keys($response->body()['data']));

            $anonymous = AuthMiddleware::process(Request::create('GET', '/api/v1/bills'));
            try {
                self::controller()->index($anonymous);
                Assert::true(false, 'anonymous must still be rejected');
            } catch (AppException $e) {
                Assert::same('UNAUTHENTICATED', $e->errorCode());
            }
        });

        $tests[] = TestResult::check('the router registers both read routes without shadowing upload/process', function (): void {
            $router = new Router();
            $router->add('POST', '/api/v1/bills/upload', [BillController::class, 'upload']);
            $router->add('POST', '/api/v1/bills/{bill_id}/process', [BillController::class, 'process']);
            $router->add('GET', '/api/v1/bills', [BillController::class, 'index']);
            $router->add('GET', '/api/v1/bills/{bill_id}', [BillController::class, 'show']);

            Assert::same([BillController::class, 'index'], $router->dispatch('GET', '/api/v1/bills'));
            Assert::same([BillController::class, 'show'], $router->dispatch('GET', '/api/v1/bills/' . self::uuidShape()));
            Assert::same([BillController::class, 'upload'], $router->dispatch('POST', '/api/v1/bills/upload'));
            Assert::same([BillController::class, 'process'], $router->dispatch('POST', '/api/v1/bills/' . self::uuidShape() . '/process'));

            try {
                $router->dispatch('GET', '/api/v1/bills/upload');
                Assert::true(false, 'upload must stay POST-only');
            } catch (MethodNotAllowedException $e) {
                Assert::true(true);
            }
        });

        $tests[] = TestResult::check('bills are ordered canonically by billing_period_start DESC, billing_period_end DESC, id DESC', function (): void {
            $fixture = AuthTesting::householdFixture('b4-order-' . bin2hex(random_bytes(4)), self::FIXTURE_SERVICE);
            $b1 = self::processingBill('b4-o1', $fixture);
            $b2 = self::processingBill('b4-o2', $fixture);
            $b3 = self::processingBill('b4-o3', $fixture);

            // Set periods out-of-order relative to insertion and created_at:
            // b1 inserted first -> period 2026-06-01 to 2026-07-01
            // b2 inserted second -> period 2026-08-01 to 2026-09-01 (should be 1st)
            // b3 inserted third, latest created_at -> period 2026-07-01 to 2026-08-01 (should be 2nd)
            self::pdo()->prepare("UPDATE bills SET billing_period_start = '2026-06-01', billing_period_end = '2026-07-01', created_at = '2026-01-01 10:00:00' WHERE id = :id")->execute(['id' => $b1['bill']]);
            self::pdo()->prepare("UPDATE bills SET billing_period_start = '2026-08-01', billing_period_end = '2026-09-01', created_at = '2026-01-01 12:00:00' WHERE id = :id")->execute(['id' => $b2['bill']]);
            self::pdo()->prepare("UPDATE bills SET billing_period_start = '2026-07-01', billing_period_end = '2026-08-01', created_at = '2026-01-01 14:00:00' WHERE id = :id")->execute(['id' => $b3['bill']]);

            $response = self::controller()->index(
                Request::create('GET', '/api/v1/bills')->withAuthContext(AuthTesting::contextFor($fixture['account']))
            );

            Assert::same(200, $response->status());
            $rows = $response->body()['data']['bills'];
            $ids = array_map(static fn (array $r): string => $r['bill_id'], $rows);

            Assert::same([$b2['bill'], $b3['bill'], $b1['bill']], $ids, 'canonical period ordering regardless of created_at');
        });

        $tests[] = TestResult::check('cleanup removes every read-test bill and its rows', function (): void {
            $pdo = self::pdo();
            foreach (self::$createdBills as $id) {
                foreach ([
                    'DELETE FROM bill_quality_flags WHERE bill_id = :id',
                    'DELETE FROM bill_validation_results WHERE bill_id = :id',
                    'DELETE FROM bill_field_provenance WHERE bill_id = :id',
                    'DELETE FROM bill_extraction_drafts WHERE bill_id = :id',
                    'DELETE FROM bill_temp_documents WHERE bill_id = :id',
                    'DELETE FROM bills WHERE id = :id',
                ] as $sql) {
                    $pdo->prepare($sql)->execute(['id' => $id]);
                }
            }

            foreach (self::$roots as $root) {
                if (is_dir($root)) {
                    foreach (glob($root . '/bills/tmp/*') ?: [] as $file) {
                        @unlink($file);
                    }
                    @rmdir($root . '/bills/tmp');
                    @rmdir($root . '/bills');
                    @rmdir($root);
                }
            }

            Assert::true(true);
        });

        return $tests;
    }

    private static function uuidShape(): string
    {
        return '4f8a2b1c-0d3e-5a6b-7c8d-9e0f1a2b3c4d';
    }
}

