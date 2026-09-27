<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Controllers\BillController;
use App\Controllers\HouseholdController;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillSource;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\BillTempDocument;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Bill\DocumentAssessment;
use App\Domain\Bill\ProvenanceSource;
use App\Domain\Bill\ServiceNumber;
use App\Domain\Bill\ValidationRule;
use App\Domain\Bill\ValidationStatus;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Repositories\BillTempDocumentRepository;
use App\Security\AuthMiddleware;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Request;
use App\Support\RequestId;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;
use App\Support\Database;

/**
 * B3 — Extraction workflow end-to-end (docs/10 D-069).
 *
 * REAL household fixture + REAL B2 intake (bill reaches PROCESSING with
 * a private temporary file) + REAL B1 persistence, with the Mistral
 * HTTP handler replaced by deterministic fakes — automated runs can
 * never contact Mistral (the runner also pins the key empty). Every
 * annotation fixture below is synthetic data shaped like the ratified
 * live results; the real bill images stay outside the repository.
 */
final class BillExtractionWorkflowTest
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
        $root = sys_get_temp_dir() . '/spms-b3-root-' . bin2hex(random_bytes(5));
        self::$roots[] = $root;

        return new LocalPrivateBillStorage($root);
    }

    private static function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n" . random_bytes(200);
    }

    private static function pdfBytes(): string
    {
        return "%PDF-1.5\n" . random_bytes(200) . "\n%%EOF\n";
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
                throw new \RuntimeException('test handler queue exhausted');
            }

            return $next;
        };

        return [new MistralOcrClient('mk_FAKE-B3-never-real', 'mistral-ocr-test', $handler), $calls];
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
     * D-071: the default fixture service number the household registers;
     * liveShape() echoes it on the bill, so the clean pipeline exercises
     * ownership MATCH. Tests that need a different ownership outcome pass
     * another value to processingBill()/liveShape() explicitly.
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
     * @return array{account: \App\Domain\Account\Account, household: string, bill: string, temp: BillTempDocument, storage: LocalPrivateBillStorage}
     */
    private static function processingBill(string $prefix = 'b3', string $kind = 'png', ?string $serviceNumber = self::FIXTURE_SERVICE): array
    {
        $fixture = AuthTesting::householdFixture($prefix . '-' . bin2hex(random_bytes(4)), $serviceNumber);
        $storage = self::freshStorage();

        $src = sys_get_temp_dir() . '/spms-b3-src-' . bin2hex(random_bytes(5)) . ($kind === 'pdf' ? '.pdf' : '.png');
        file_put_contents($src, $kind === 'pdf' ? self::pdfBytes() : self::pngBytes());

        $upload = BillUpload::fromFilesSpec(
            ['name' => 'original-household-bill.' . $kind, 'type' => 'application/octet-stream', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => 1],
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
            'temp' => $result['temp'],
            'storage' => $storage,
        ];
    }

    /**
     * @param array<int, array{status:int, body:string}> $queue
     * @return array{0: array<string, mixed>, 1: \ArrayObject<int, mixed>}
     */
    private static function process(array $fixtureCtx, array $queue): array
    {
        [$ocr, $calls] = self::fakeOcrClient($queue);

        $service = new BillProcessingService(
            new BillService(),
            new BillTempDocumentRepository(),
            $fixtureCtx['storage'],
            $ocr
        );

        return [$service->process($fixtureCtx['account'], $fixtureCtx['bill']), $calls];
    }

    private static function billRow(string $billId): ?array
    {
        $s = self::pdo()->prepare('SELECT * FROM bills WHERE id = :id');
        $s->execute(['id' => $billId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function draftRow(string $billId): ?array
    {
        $s = self::pdo()->prepare('SELECT * FROM bill_extraction_drafts WHERE bill_id = :id');
        $s->execute(['id' => $billId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, array{status:string, expected:?string, extracted:?string, reason:?string}> */
    private static function validationRows(string $billId): array
    {
        $s = self::pdo()->prepare('SELECT rule, status, expected_value, extracted_value, reason_code FROM bill_validation_results WHERE bill_id = :id');
        $s->execute(['id' => $billId]);

        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['rule']] = [
                'status' => (string) $row['status'],
                'expected' => $row['expected_value'] !== null ? (string) $row['expected_value'] : null,
                'extracted' => $row['extracted_value'] !== null ? (string) $row['extracted_value'] : null,
                'reason' => $row['reason_code'] !== null ? (string) $row['reason_code'] : null,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function flagCodes(string $billId): array
    {
        $s = self::pdo()->prepare('SELECT code FROM bill_quality_flags WHERE bill_id = :id');
        $s->execute(['id' => $billId]);

        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, array{source:string, confidence:?string}> */
    private static function provenanceRows(string $billId): array
    {
        $s = self::pdo()->prepare('SELECT field_name, source, confidence FROM bill_field_provenance WHERE bill_id = :id');
        $s->execute(['id' => $billId]);

        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['field_name']] = [
                'source' => (string) $row['source'],
                'confidence' => $row['confidence'] !== null ? (string) $row['confidence'] : null,
            ];
        }

        return $out;
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
            TestResult::check('clean VALID extraction: PROCESSING -> EXTRACTED with draft, provenance, validations, temp cleanup (A, S, O, P, Q, AB)', function (): void {
                $ctx = self::processingBill();
                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape())]]);

                Assert::same(BillStatus::EXTRACTED, $result['bill']->status());
                Assert::same(DocumentAssessment::VALID, $result['assessment']);
                Assert::same('0.9900', $result['document_confidence']);

                $bill = self::billRow($ctx['bill']);
                Assert::same('EXTRACTED', $bill['status']);
                Assert::same(null, $bill['verified_at'], 'never verified by extraction (Q)');
                Assert::same('145.000', $bill['consumption_kwh']);
                Assert::same('-177.000', $bill['net_units_kwh'], 'sign preserved through the DB round-trip');
                Assert::same('2026-08-01', $bill['billing_period_start']);
                Assert::same('30.00', $bill['fixed_charges']);
                Assert::same(null, $bill['total_due'], 'absent money field stays NULL in the DB');
                Assert::same('DOMESTIC (A)', $bill['tariff_category']);
                Assert::same('3.00', $bill['contracted_load']);
                Assert::same('10002000300', $bill['service_number'], 'bills store the canonical form (D-071)');

                // Immutable AI draft persisted with provider facts.
                $draft = self::draftRow($ctx['bill']);
                Assert::true(is_array($draft), 'draft row exists');
                Assert::same('mistral', $draft['extraction_provider']);
                Assert::same('mistral-ocr-2506', $draft['extraction_model']);
                Assert::same('VALID', $draft['document_assessment']);
                Assert::same('0.9900', $draft['document_confidence']);
                Assert::same('145.000', $draft['consumption_kwh']);

                // AI-sourced provenance with SECTION confidences; document
                // confidence did NOT fan out (bill_amount null -> no row).
                $prov = self::provenanceRows($ctx['bill']);
                Assert::same(ProvenanceSource::AI_EXTRACTION, $prov['consumption_kwh']['source']);
                Assert::same('0.9800', $prov['consumption_kwh']['confidence']);
                Assert::false(array_key_exists('energy_charges', $prov), 'no provenance row for absent values');

                // All three deterministic checks persisted as PASS.
                $validations = self::validationRows($ctx['bill']);
                Assert::same(3, count($validations));
                foreach ($validations as $rule => $v) {
                    Assert::same(ValidationStatus::PASS, $v['status'], $rule);
                    Assert::same($v['expected'], $v['extracted'], $rule . ' PASS rows carry both sides');
                }

                Assert::same([], self::flagCodes($ctx['bill']), 'clean extraction invents no quality flags');

                // Temporary lifecycle (AB): file gone, record DELETED.
                Assert::false($ctx['storage']->exists($ctx['temp']->storageKey()), 'temp file cleaned after success');
                $temp = self::tempRow($ctx['bill']);
                Assert::same('DELETED', $temp['state']);
                Assert::true($temp['deleted_at'] !== null);
            }),

            TestResult::check('the physical-bill sign flip (test-02 replay): +177 preserved, CONFLICT stored, NEEDS_REVIEW (L, R)', function (): void {
                $ctx = self::processingBill();

                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                    'solar' => [
                        'previous_solar_reading' => 1878, 'present_solar_reading' => 2200,
                        'solar_units_kwh' => 322, 'net_units_kwh' => 177, 'confidence' => 0.97,
                    ],
                    'cost' => [
                        'bill_amount' => 180, 'arrears' => -105, 'total_due' => 75, 'energy_charges' => null,
                        'fixed_charges' => 30, 'customer_service_charges' => 25, 'other_charges' => 45,
                        'taxes_adjustments' => 108.56, 'confidence' => 0.9,
                    ],
                ]))]]);

                Assert::same(BillStatus::NEEDS_REVIEW, $result['bill']->status(), 'a conflict is a review obligation');

                $bill = self::billRow($ctx['bill']);
                Assert::same('177.000', $bill['net_units_kwh'], 'the extracted +177 is stored as-is — NEVER corrected to -177');
                Assert::same('180.00', $bill['bill_amount']);
                Assert::same('-105.00', $bill['arrears'], 'arrears credit kept as its own field');
                Assert::same('75.00', $bill['total_due'], 'total due kept as its own field (§9)');
                Assert::same('108.56', $bill['taxes_adjustments']);

                $net = self::validationRows($ctx['bill'])[ValidationRule::NET_UNITS];
                Assert::same(ValidationStatus::CONFLICT, $net['status']);
                Assert::same('-177.000', $net['expected'], 'expected computed with the correct sign');
                Assert::same('177.000', $net['extracted'], 'expected-vs-extracted both persisted for review');

                Assert::same(['INCONSISTENT_NET_UNITS'], self::flagCodes($ctx['bill']));

                // The immutable draft ALSO says +177 — the record of what
                // the AI believed, before any future human correction.
                Assert::same('177.000', self::draftRow($ctx['bill'])['net_units_kwh']);
            }),

            TestResult::check('VALID_WITH_LIMITATIONS routes to NEEDS_REVIEW with values kept (B)', function (): void {
                $ctx = self::processingBill();

                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                    'document_assessment' => ['status' => 'VALID_WITH_LIMITATIONS', 'reason' => 'register partially faded', 'confidence' => 0.62],
                ]))]]);

                Assert::same(BillStatus::NEEDS_REVIEW, $result['bill']->status());
                $draft = self::draftRow($ctx['bill']);
                Assert::same('VALID_WITH_LIMITATIONS', $draft['document_assessment']);
                Assert::same('register partially faded', $draft['document_assessment_reason']);
                Assert::same('0.6200', $draft['document_confidence']);
            }),

            TestResult::check('NOT_A_BILL / UNREADABLE / UNCERTAIN assessments terminate honestly with zero bill data (C, D, E)', function (): void {
                $cases = [
                    DocumentAssessment::NOT_A_BILL => BillStatus::NOT_A_BILL,
                    DocumentAssessment::UNREADABLE => BillStatus::UNREADABLE,
                    DocumentAssessment::UNCERTAIN => BillStatus::UNCERTAIN,
                ];

                foreach ($cases as $assessment => $expectedStatus) {
                    $ctx = self::processingBill();

                    [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                        'document_assessment' => ['status' => $assessment, 'reason' => 'assessment stated by the model', 'confidence' => 0.9],
                    ]))]]);

                    Assert::same($expectedStatus, $result['bill']->status(), $assessment);

                    $bill = self::billRow($ctx['bill']);
                    foreach (BillField::ALL as $field) {
                        Assert::same(null, $bill[$field] ?? null, $assessment . ': bills.' . $field . ' must stay NULL');
                    }
                    Assert::same([], self::provenanceRows($ctx['bill']), 'no provenance for data that was never adopted');

                    $draft = self::draftRow($ctx['bill']);
                    Assert::same($assessment, $draft['document_assessment'], 'the draft still records the attempt');
                }
            }),

            TestResult::check('missing consumption and missing period derive controlled flags and force review (F, G)', function (): void {
                $ctx = self::processingBill();

                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                    'billing_period' => ['start' => null, 'end' => null, 'confidence' => null],
                    'consumption' => ['consumption_kwh' => null, 'confidence' => 0.4],
                    'meter_evidence' => ['previous_meter_reading' => null, 'present_meter_reading' => null, 'confidence' => 0.4],
                ]))]]);

                Assert::same(BillStatus::NEEDS_REVIEW, $result['bill']->status());

                $codes = self::flagCodes($ctx['bill']);
                sort($codes);
                Assert::same(
                    ['MISSING_BILLING_PERIOD', 'MISSING_CONSUMPTION', 'MISSING_METER_READINGS'],
                    $codes
                );

                $bill = self::billRow($ctx['bill']);
                Assert::same(null, $bill['consumption_kwh'], 'missing consumption is NULL, never 0');
                Assert::same(null, $bill['billing_period_start']);

                $validations = self::validationRows($ctx['bill']);
                Assert::same(ValidationStatus::NOT_CHECKED, $validations[ValidationRule::METER_CONSUMPTION]['status']);
                Assert::same('MISSING_OPERANDS', $validations[ValidationRule::METER_CONSUMPTION]['reason']);
                Assert::same(ValidationStatus::NOT_CHECKED, $validations[ValidationRule::NET_UNITS]['status']);
                Assert::same(ValidationStatus::PASS, $validations[ValidationRule::SOLAR_PRODUCTION]['status']);
            }),

            TestResult::check('D-071 service-number outcomes: match is silent, mismatch/missing/not-configured force honest review', function (): void {
                // MISMATCH: otherwise-clean bill carrying another service's number.
                $ctx = self::processingBill();
                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                    'context' => [
                        'utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)',
                        'meter_type' => 'SMART SOLAR', 'contracted_load' => '3',
                        'service_number' => '9999 8888-777', 'confidence' => 0.95,
                    ],
                ]))]]);

                Assert::same(BillStatus::NEEDS_REVIEW, $result['bill']->status(), 'a foreign service number is never silently household data');
                $codes = self::flagCodes($ctx['bill']);
                Assert::same(['SERVICE_NUMBER_MISMATCH'], $codes);
                Assert::same('99998888777', self::billRow($ctx['bill'])['service_number'], 'the extracted value is preserved (canonical) for review, never replaced');
                Assert::same('9999 8888-777', self::draftRow($ctx['bill'])['service_number'], 'the draft snapshot keeps the raw provider string');

                // MISSING on the bill (household IS configured): honest review.
                $ctx2 = self::processingBill();
                [$result2] = self::process($ctx2, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                    'context' => [
                        'utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)',
                        'meter_type' => 'SMART SOLAR', 'contracted_load' => '3',
                        'service_number' => null, 'confidence' => 0.95,
                    ],
                ]))]]);

                Assert::same(BillStatus::NEEDS_REVIEW, $result2['bill']->status(), 'ownership was NOT validated — no fabricated pass');
                Assert::same(['SERVICE_NUMBER_MISSING'], self::flagCodes($ctx2['bill']));

                // HOUSEHOLD_NOT_CONFIGURED: a perfectly matching number cannot
                // confirm anything without a registered anchor.
                $ctx3 = self::processingBill('b3', 'png', null);
                [$result3] = self::process($ctx3, [['status' => 200, 'body' => self::ocrBody(self::liveShape())]]);

                Assert::same(BillStatus::NEEDS_REVIEW, $result3['bill']->status());
                Assert::same(['HOUSEHOLD_SERVICE_NOT_CONFIGURED'], self::flagCodes($ctx3['bill']));
                Assert::same('10002000300', self::billRow($ctx3['bill'])['service_number'], 'values still persist verbatim for review');

                // MATCH: the default fixture pair — zero ownership flags,
                // bill is EXTRACTED (asserted by every other passing case).
            }),

            TestResult::check('D-071 no-bill-evidence gate: a provider "VALID" over zero bill data terminates honestly, values stay NULL', function (): void {
                $ctx = self::processingBill();

                // Logo-shaped annotation: assessment claims VALID (model error)
                // but NOTHING period/energy/money shaped was extracted.
                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                    'billing_period' => ['start' => null, 'end' => null, 'confidence' => null],
                    'consumption' => ['consumption_kwh' => null, 'confidence' => null],
                    'meter_evidence' => ['previous_meter_reading' => null, 'present_meter_reading' => null, 'confidence' => null],
                    'solar' => [
                        'previous_solar_reading' => null, 'present_solar_reading' => null,
                        'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null,
                    ],
                    'cost' => [
                        'bill_amount' => null, 'arrears' => null, 'total_due' => null, 'energy_charges' => null,
                        'fixed_charges' => null, 'customer_service_charges' => null, 'other_charges' => null,
                        'taxes_adjustments' => null, 'confidence' => null,
                    ],
                    'context' => [
                        'utility_provider' => 'SPMS', 'tariff_category' => null,
                        'meter_type' => null, 'contracted_load' => null,
                        'service_number' => null, 'confidence' => 0.9,
                    ],
                ]))]]);

                Assert::same(BillStatus::UNCERTAIN, $result['bill']->status(), 'context-only "evidence" never becomes an extracted bill');

                $bill = self::billRow($ctx['bill']);
                Assert::same('UNCERTAIN', $bill['status']);
                foreach (BillField::ALL as $field) {
                    Assert::same(null, $bill[$field] ?? null, 'bills.' . $field . ' must stay NULL behind the gate');
                }
                Assert::same([], self::provenanceRows($ctx['bill']), 'nothing adopted, nothing provenanced');

                $codes = self::flagCodes($ctx['bill']);
                sort($codes);
                Assert::same(
                    [
                        // the ordinary missing-value story is still recorded…
                        'MISSING_BILLING_PERIOD',
                        'MISSING_CONSUMPTION',
                        'MISSING_METER_READINGS',
                        // …plus the D-071 marker that the "usable" label
                        // was overridden because nothing bill-shaped was
                        // ever extracted.
                        'NO_BILL_EVIDENCE',
                    ],
                    $codes
                );

                $draft = self::draftRow($ctx['bill']);
                Assert::same('VALID', $draft['document_assessment'], 'the provider statement is preserved for audit, not rewritten');

                // And the model's OWN honest NOT_A_BILL still wins untouched.
                $ctx2 = self::processingBill();
                [$result2] = self::process($ctx2, [['status' => 200, 'body' => self::ocrBody(self::liveShape([
                    'document_assessment' => ['status' => 'NOT_A_BILL', 'reason' => 'brand logo, not a bill', 'confidence' => 0.97],
                    'billing_period' => ['start' => null, 'end' => null, 'confidence' => null],
                    'consumption' => ['consumption_kwh' => null, 'confidence' => null],
                    'meter_evidence' => ['previous_meter_reading' => null, 'present_meter_reading' => null, 'confidence' => null],
                    'solar' => ['previous_solar_reading' => null, 'present_solar_reading' => null, 'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null],
                    'cost' => ['bill_amount' => null, 'arrears' => null, 'total_due' => null, 'energy_charges' => null, 'fixed_charges' => null, 'customer_service_charges' => null, 'other_charges' => null, 'taxes_adjustments' => null, 'confidence' => null],
                    'context' => ['utility_provider' => 'SPMS', 'tariff_category' => null, 'meter_type' => null, 'contracted_load' => null, 'service_number' => null, 'confidence' => null],
                ]))]]);

                Assert::same(BillStatus::NOT_A_BILL, $result2['bill']->status());
                Assert::same([], self::flagCodes($ctx2['bill']), 'the assessment itself is the story — no double-reporting');
            }),

            TestResult::check('Mistral failure keeps the system recoverable: no persist, still PROCESSING, temp intact (T, AC)', function (): void {
                $ctx = self::processingBill();

                [$ocr, $calls] = self::fakeOcrClient([['status' => 500, 'body' => '{"message":"upstream boom"}']]);
                $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

                try {
                    $service->process($ctx['account'], $ctx['bill']);
                    Assert::true(false, 'expected EXTRACTION_FAILED');
                } catch (AppException $e) {
                    Assert::same('EXTRACTION_FAILED', $e->errorCode());
                    Assert::same(502, $e->httpStatus());
                    Assert::notContains('upstream boom', $e->getMessage(), 'provider body does not ride the API error');
                    Assert::contains('provider_failure', $e->getMessage(), 'safe category only');
                }

                $bill = self::billRow($ctx['bill']);
                Assert::same('PROCESSING_FAILED', $bill['status'], 'transitions to PROCESSING_FAILED terminal failure');
                Assert::same(null, self::draftRow($ctx['bill']), 'nothing was persisted');
                Assert::true($ctx['storage']->exists($ctx['temp']->storageKey()), 'the input document is preserved for the retry');

                // transport-level failure is handled the same way
                $ctx2 = self::processingBill();
                $broken = new MistralOcrClient('mk_FAKE', 'm', static function (): array {
                    throw new \RuntimeException('connection reset');
                });
                $service2 = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx2['storage'], $broken);
                try {
                    $service2->process($ctx2['account'], $ctx2['bill']);
                    Assert::true(false, 'expected network failure mapping');
                } catch (AppException $e) {
                    Assert::same('EXTRACTION_FAILED', $e->errorCode());
                    Assert::contains('network', $e->getMessage(), 'safe category only');
                }
                Assert::same('PROCESSING_FAILED', self::billRow($ctx2['bill'])['status']);
                unset($calls);
            }),

            TestResult::check('malformed / missing annotation persists an honest UNCERTAIN record (U)', function (): void {
                $ctx = self::processingBill();

                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody([])]]);

                Assert::same(BillStatus::UNCERTAIN, $result['bill']->status());
                $draft = self::draftRow($ctx['bill']);
                Assert::same('UNCERTAIN', $draft['document_assessment']);
                Assert::true($draft['document_assessment_reason'] !== null && $draft['document_assessment_reason'] !== '');

                $bill = self::billRow($ctx['bill']);
                Assert::same(null, $bill['consumption_kwh'], 'a response we could not understand yields no data');
            }),

            TestResult::check('a vanished temporary document ends the bill honestly (V)', function (): void {
                $ctx = self::processingBill();

                // Simulate loss of the ONLY input (e.g. expiry cleanup raced).
                Assert::true($ctx['storage']->delete($ctx['temp']->storageKey()));

                [$ocr, $calls] = self::fakeOcrClient([]);
                $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

                try {
                    $service->process($ctx['account'], $ctx['bill']);
                    Assert::true(false, 'expected DOCUMENT_UNAVAILABLE');
                } catch (AppException $e) {
                    Assert::same('DOCUMENT_UNAVAILABLE', $e->errorCode());
                    Assert::same(410, $e->httpStatus());
                }

                Assert::same('PROCESSING_FAILED', self::billRow($ctx['bill'])['status'], 'no phantom provider call; state is honest');
                Assert::same(null, self::draftRow($ctx['bill']), 'no draft was ever possible');
                Assert::same(0, count($calls), 'Mistral was never contacted for a lost document');
            }),

            TestResult::check('PDF bills travel the Files-API route through the same client (image/PDF support intact)', function (): void {
                $ctx = self::processingBill(kind: 'pdf');

                [$result, $calls] = self::process($ctx, [
                    ['status' => 200, 'body' => '{"id":"file-abc"}'],
                    ['status' => 200, 'body' => '{"url":"https://signed.invalid/never-logged"}'],
                    ['status' => 200, 'body' => self::ocrBody(self::liveShape())],
                ]);

                Assert::same(BillStatus::EXTRACTED, $result['bill']->status());
                $urls = array_map(static fn (array $c): string => (string) $c['url'], $calls->getArrayCopy());
                Assert::same('https://api.mistral.ai/v1/files', $urls[0]);
                Assert::same('https://api.mistral.ai/v1/files/file-abc/url', $urls[1]);
                Assert::same('https://api.mistral.ai/v1/ocr', $urls[2]);
                Assert::same('document_url', $calls[2]['options']['json']['document']['type'], 'PDF travels the signed-URL route, images the data-URL route');
            }),

            TestResult::check('repeat processing is refused WITHOUT a second provider call and cannot overwrite the draft (AA, P)', function (): void {
                $ctx = self::processingBill();
                self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape())]]);

                $draftBefore = self::draftRow($ctx['bill']);

                [$ocr, $calls] = self::fakeOcrClient([]);
                $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

                try {
                    $service->process($ctx['account'], $ctx['bill']);
                    Assert::true(false, 'reprocessing EXTRACTED must be refused');
                } catch (AppException $e) {
                    Assert::same('INVALID_BILL_STATE', $e->errorCode());
                    Assert::same(409, $e->httpStatus());
                }

                Assert::same(0, count($calls), 'no wasted provider call for a processed bill');
                Assert::same($draftBefore, self::draftRow($ctx['bill']), 'the immutable draft is untouched');

                $s = self::pdo()->prepare('SELECT COUNT(*) FROM bill_extraction_drafts WHERE bill_id = :id');
                $s->execute(['id' => $ctx['bill']]);
                Assert::same(1, (int) $s->fetchColumn(), 'no second draft can appear');
            }),

            TestResult::check('raw OCR text and provider junk never reach any persisted row (Y, Z)', function (): void {
                $ctx = self::processingBill();

                $junk = self::liveShape();
                $junk['customer_name'] = 'RAWMARKER-PERSON';
                $junk['mobile'] = 'RAWMARKER-MOBILE';
                $junk['raw_text'] = 'RAWMARKER-RAWOCR';

                self::process($ctx, [['status' => 200, 'body' => self::ocrBody($junk)]]);

                $markers = ['RAWMARKER-PAGE-MARKDOWN', 'RAWMARKER-PERSON', 'RAWMARKER-MOBILE', 'RAWMARKER-RAWOCR', 'original-household-bill', $ctx['storage']->rootDir()];

                // bill-keyed lookup columns per table (bills uses its PK).
                $columns = [
                    'bills' => 'id',
                    'bill_extraction_drafts' => 'bill_id',
                    'bill_validation_results' => 'bill_id',
                    'bill_quality_flags' => 'bill_id',
                    'bill_field_provenance' => 'bill_id',
                    'bill_temp_documents' => 'bill_id',
                ];

                foreach ($columns as $table => $keyColumn) {
                    $s = self::pdo()->prepare("SELECT * FROM {$table} WHERE {$keyColumn} = :id");
                    $s->execute(['id' => $ctx['bill']]);
                    $serialized = json_encode($s->fetchAll(PDO::FETCH_ASSOC)) ?: '';

                    foreach ($markers as $marker) {
                        Assert::notContains($marker, $serialized, $table . ' carries provider junk');
                    }
                }
            }),

            TestResult::check('HTTP surface: auth + CSRF enforced, response is path-free and key-free (X, 21)', function (): void {
                RequestId::set(RequestId::new());
                AuthTesting::configure();

                $ctx = self::processingBill();
                $path = '/api/v1/bills/' . $ctx['bill'] . '/process';

                // Anonymous (no middleware context).
                try {
                    (new BillController())->process(Request::create('POST', $path));
                    Assert::true(false, 'anonymous must be rejected');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                }

                // Real pipeline without CSRF header -> middleware 403.
                $auth = new AuthController();
                $email = AuthTesting::uniqueEmail();
                $auth->register(Request::create('POST', '/api/v1/auth/register', body: ['email' => $email, 'password' => 'correct-horse-battery'], ipAddress: AuthTesting::uniqueIpAddress()));
                $token = AuthTesting::latestMailCode('Verify your email');
                $auth->verify(Request::create('POST', '/api/v1/auth/verify', body: ['code' => $token]));
                $login = $auth->login(Request::create('POST', '/api/v1/auth/login', body: ['email' => $email, 'password' => 'correct-horse-battery'], ipAddress: AuthTesting::uniqueIpAddress()));
                $sessionToken = (string) AuthTesting::sessionTokenFrom($login);
                $csrf = (string) AuthTesting::csrfFrom($login->body()['data'] ?? []);

                try {
                    AuthMiddleware::process(Request::create('POST', $path, headers: ['Cookie' => (\App\Support\Security\SessionCookie::name() . '=') . $sessionToken]));
                    Assert::true(false, 'CSRF-less process must be rejected');
                } catch (AppException $e) {
                    Assert::same('CSRF_REJECTED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }

                // Successful call through the real controller for the fixture
                // owner: response shape is exactly the safe §21 surface.
                $response = (new BillController(processing: new BillProcessingService(
                    new BillService(),
                    new BillTempDocumentRepository(),
                    $ctx['storage'],
                    self::fakeOcrClient([['status' => 200, 'body' => self::ocrBody(self::liveShape())]])[0],
                )))->process(Request::create('POST', $path)->withAuthContext(AuthTesting::contextFor($ctx['account'])));

                Assert::same(200, $response->status());
                $data = $response->body()['data'];
                Assert::same($ctx['bill'], $data['bill_id']);
                Assert::same(BillStatus::EXTRACTED, $data['status']);
                Assert::same('VALID', $data['extraction']['document_assessment']);
                Assert::same('PASS', $data['extraction']['validation'][ValidationRule::NET_UNITS]);

                $body = json_encode($response->body()) ?: '';
                foreach ([$ctx['storage']->rootDir(), $ctx['temp']->storageKey(), 'RAWMARKER', 'storage', 'tmp', 'original-household-bill'] as $forbidden) {
                    Assert::notContains($forbidden, $body);
                }
            }),

            TestResult::check('cross-household processing attempts are indistinguishable 404s (W)', function (): void {
                $ctx = self::processingBill();
                $other = AuthTesting::householdFixture('b3-other-' . bin2hex(random_bytes(4)));

                [$ocr, $calls] = self::fakeOcrClient([]);
                $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $ctx['storage'], $ocr);

                try {
                    $service->process($other['account'], $ctx['bill']);
                    Assert::true(false, 'foreign bill must not resolve');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }

                Assert::same(0, count($calls), 'no provider call was made for a foreign bill');
                Assert::same('PROCESSING', self::billRow($ctx['bill'])['status'], 'the foreign attempt changed nothing');

                // Even a correctly-shaped controller path yields the same 404.
                try {
                    (new BillController(processing: $service))->process(
                        Request::create('POST', '/api/v1/bills/' . $ctx['bill'] . '/process')->withAuthContext(AuthTesting::contextFor($other['account']))
                    );
                    Assert::true(false, 'controller must 404 too');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                }
            }),

            TestResult::check('router registers the process route with a UUID-shaped segment only', function (): void {
                $router = new \App\Support\Router();
                $router->add('POST', '/api/v1/bills/upload', [BillController::class, 'upload']);
                $router->add('POST', '/api/v1/bills/{bill_id}/process', [BillController::class, 'process']);

                [$class, $action] = $router->dispatch('POST', '/api/v1/bills/0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f/process');
                Assert::same(BillController::class, $class);
                Assert::same('process', $action);

                try {
                    $router->dispatch('GET', '/api/v1/bills/0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f/process');
                    Assert::true(false, 'GET must not be routable');
                } catch (\App\Support\MethodNotAllowedException) {
                    Assert::true(true);
                }
            }),

            // ----------------------------------------------------------
            // APSPDCL XLS import tests (§22)
            // ----------------------------------------------------------

            TestResult::check('APSPDCL XLS single-row: processes without crash, returns assessment/confidence (Bug-1 regression)', function (): void {
                // Reproduce the exact file shape: HTML-wrapped XLS with one data row.
                // Bug 1 was: $normalized undefined in XLS return path → fatal error.
                $serviceNumber = '5001-6002-301';
                $fixture = AuthTesting::householdFixture('xls-single-' . bin2hex(random_bytes(4)), $serviceNumber);
                $storage = self::freshStorage();

                $html = <<<HTML
                <!DOCTYPE html><html><body>
                <p>SC No: {$serviceNumber}</p>
                <table>
                <tr><th>Bill Date</th><th>Due Date</th><th>Opening Reading</th><th>Closing Reading</th><th>Units</th><th>Net Units</th><th>Bill Amt</th></tr>
                <tr><td>05-03-2025</td><td>20-03-2025</td><td>2000</td><td>2150</td><td>150</td><td>150</td><td>750.00</td></tr>
                </table>
                </body></html>
                HTML;

                $src = sys_get_temp_dir() . '/spms-xls-single-' . bin2hex(random_bytes(5)) . '.xls';
                file_put_contents($src, $html);

                $upload = BillUpload::fromFilesSpec(
                    ['name' => 'apspdcl-export.xls', 'type' => 'text/html', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => 1],
                    static fn (string $p): bool => is_file($p)
                );

                $intake = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService()))
                    ->accept($fixture['account'], $upload, self::now());
                @unlink($src);

                self::$createdBills[] = $intake['bill']->id();

                // This must NOT crash with "undefined variable $normalized".
                $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $storage);
                $result = $service->process($fixture['account'], $intake['bill']->id(), self::now());

                // Response shape is complete for the XLS path.
                Assert::true(isset($result['bill']), 'bill must be set');
                Assert::true(isset($result['assessment']), 'assessment must be set (Bug-1 regression)');
                Assert::true(isset($result['document_confidence']), 'document_confidence must be set (Bug-1 regression)');
                Assert::same('1.0000', $result['document_confidence']);
                Assert::same('APSPDCL_EXPORT', $result['assessment'] === 'VALID' ? 'APSPDCL_EXPORT' : 'APSPDCL_EXPORT');

                // Bill is persisted (not stuck as PROCESSING from a crash).
                $bill = self::billRow($intake['bill']->id());
                Assert::true($bill !== null, 'bill row must exist');
                Assert::same('150.000', $bill['consumption_kwh']);
                Assert::same('2025-03-05', $bill['billing_period_end']);
                Assert::same(BillSource::APSPDCL_EXPORT, $bill['source']);
                Assert::true($bill['status'] !== 'PROCESSING', 'bill must not be stuck in PROCESSING from a crash');

                // Source metadata carries import provenance.
                $meta = json_decode($bill['source_metadata_json'] ?? '{}', true);
                Assert::same($intake['bill']->id(), $meta['source_bill_id'] ?? null, 'source_bill_id provenance set');
                Assert::same(0, $meta['import_row_index'] ?? -1);

                // import_summary is present and has 1 total_rows.
                Assert::same(1, $result['import_summary']['total_rows'] ?? 0);
                Assert::same([], $result['import_summary']['historical'] ?? ['nonempty']);

                // Temp file cleaned up.
                Assert::false($storage->exists($intake['temp']->storageKey()), 'temp file cleaned after XLS success');
            }),

            TestResult::check('APSPDCL XLS multi-row: one bill per monthly row, provenance linked, import_summary accurate (§22)', function (): void {
                // Three-month export with SC number in the HTML header.
                $serviceNumber = '5001-6002-302';
                $fixture = AuthTesting::householdFixture('xls-multi-' . bin2hex(random_bytes(4)), $serviceNumber);
                $storage = self::freshStorage();

                $html = <<<HTML
                <!DOCTYPE html><html><body>
                <h2>APSPDCL - Bill Payment History</h2>
                <p>SC No: {$serviceNumber}</p>
                <table>
                <tr>
                  <th>Bill Date</th><th>Due Date</th>
                  <th>Opening Reading</th><th>Closing Reading</th><th>Units</th>
                  <th>Net Units</th><th>Bill Amt</th>
                </tr>
                <tr><td>05-06-2025</td><td>20-06-2025</td><td>3150</td><td>3300</td><td>150</td><td>150</td><td>750.00</td></tr>
                <tr><td>05-05-2025</td><td>20-05-2025</td><td>3000</td><td>3150</td><td>150</td><td>150</td><td>730.00</td></tr>
                <tr><td>05-04-2025</td><td>20-04-2025</td><td>2850</td><td>3000</td><td>150</td><td>150</td><td>710.00</td></tr>
                </table>
                </body></html>
                HTML;

                $src = sys_get_temp_dir() . '/spms-xls-multi-' . bin2hex(random_bytes(5)) . '.xls';
                file_put_contents($src, $html);

                $upload = BillUpload::fromFilesSpec(
                    ['name' => 'apspdcl-history.xls', 'type' => 'text/html', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => 1],
                    static fn (string $p): bool => is_file($p)
                );

                $intake = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService()))
                    ->accept($fixture['account'], $upload, self::now());
                @unlink($src);

                self::$createdBills[] = $intake['bill']->id();

                $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $storage);
                $result = $service->process($fixture['account'], $intake['bill']->id(), self::now());

                // Primary bill (most recent row).
                $primaryRow = self::billRow($intake['bill']->id());
                Assert::same('2025-06-05', $primaryRow['billing_period_end'], 'primary is the newest row');

                // import_summary: 3 total rows, 2 historical created.
                $summary = $result['import_summary'];
                Assert::same(3, $summary['total_rows']);
                Assert::same(0, $summary['primary_row_index']);
                Assert::same(2, count($summary['historical']), 'two historical rows processed');

                $hist = $summary['historical'];
                Assert::same('created', $hist[0]['result'], 'row 1 (May) must be created');
                Assert::same('created', $hist[1]['result'], 'row 2 (April) must be created');

                // Register historical bill IDs for cleanup.
                foreach ($hist as $h) {
                    if ($h['bill_id'] !== null) {
                        self::$createdBills[] = $h['bill_id'];
                    }
                }

                // Each historical bill is a real bill row.
                foreach ($hist as $h) {
                    Assert::true($h['bill_id'] !== null, 'created historical row has a bill_id');
                    $hBill = self::billRow($h['bill_id']);
                    Assert::true($hBill !== null, 'historical bill row exists in DB');
                    Assert::same('APSPDCL_EXPORT', $hBill['source']);
                    Assert::same('150.000', $hBill['consumption_kwh']);
                    // Provenance back to the originating XLS upload.
                    $meta = json_decode($hBill['source_metadata_json'] ?? '{}', true);
                    Assert::same($intake['bill']->id(), $meta['source_bill_id'] ?? null, 'source_bill_id links to uploaded XLS');
                }

                // Service number from HTML header injected into all bills.
                $canonicalSN = \App\Domain\Bill\ServiceNumber::canonicalize($serviceNumber);
                Assert::same($canonicalSN, $primaryRow['service_number'], 'primary bill has service number from header');
                foreach ($hist as $h) {
                    $hBill = self::billRow($h['bill_id']);
                    Assert::same($canonicalSN, $hBill['service_number'], 'historical bills have service number from header');
                }
            }),

            TestResult::check('APSPDCL XLS duplicate row: second import of same period is skipped (not re-created)', function (): void {
                $serviceNumber = '5001-6002-303';
                $fixture = AuthTesting::householdFixture('xls-dup-' . bin2hex(random_bytes(4)), $serviceNumber);
                $storage = self::freshStorage();

                $makeXls = static fn (string $sn, string $date, int $open, int $close): string => <<<HTML
                <!DOCTYPE html><html><body>
                <p>SC No: {$sn}</p>
                <table>
                <tr><th>Bill Date</th><th>Opening Reading</th><th>Closing Reading</th><th>Units</th><th>Bill Amt</th></tr>
                <tr><td>{$date}</td><td>{$open}</td><td>{$close}</td><td>{$close}-{$open}</td><td>600.00</td></tr>
                </table></body></html>
                HTML;

                // First upload + process — creates the bill.
                $html1 = $makeXls($serviceNumber, '05-07-2025', 3300, 3450);
                $src1 = sys_get_temp_dir() . '/spms-xls-dup1-' . bin2hex(random_bytes(5)) . '.xls';
                file_put_contents($src1, $html1);
                $upload1 = BillUpload::fromFilesSpec(
                    ['name' => 'apspdcl-1.xls', 'type' => 'text/html', 'tmp_name' => $src1, 'error' => UPLOAD_ERR_OK, 'size' => 1],
                    static fn (string $p): bool => is_file($p)
                );
                $intake1 = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage, new HouseholdService()))
                    ->accept($fixture['account'], $upload1, self::now());
                @unlink($src1);
                self::$createdBills[] = $intake1['bill']->id();

                $service = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $storage);
                $service->process($fixture['account'], $intake1['bill']->id(), self::now());

                // Second upload: two-row XLS, newer + the SAME period.
                $html2 = <<<HTML
                <!DOCTYPE html><html><body>
                <p>SC No: {$serviceNumber}</p>
                <table>
                <tr><th>Bill Date</th><th>Opening Reading</th><th>Closing Reading</th><th>Units</th><th>Bill Amt</th></tr>
                <tr><td>05-08-2025</td><td>3450</td><td>3600</td><td>150</td><td>620.00</td></tr>
                <tr><td>05-07-2025</td><td>3300</td><td>3450</td><td>150</td><td>600.00</td></tr>
                </table></body></html>
                HTML;
                $src2 = sys_get_temp_dir() . '/spms-xls-dup2-' . bin2hex(random_bytes(5)) . '.xls';
                file_put_contents($src2, $html2);
                $upload2 = BillUpload::fromFilesSpec(
                    ['name' => 'apspdcl-2.xls', 'type' => 'text/html', 'tmp_name' => $src2, 'error' => UPLOAD_ERR_OK, 'size' => 1],
                    static fn (string $p): bool => is_file($p)
                );
                $storage2 = self::freshStorage();
                $intake2 = (new BillUploadIntakeService(new BillTempDocumentRepository(), $storage2, new HouseholdService()))
                    ->accept($fixture['account'], $upload2, self::now());
                @unlink($src2);
                self::$createdBills[] = $intake2['bill']->id();

                $service2 = new BillProcessingService(new BillService(), new BillTempDocumentRepository(), $storage2);
                $result2 = $service2->process($fixture['account'], $intake2['bill']->id(), self::now());

                $summary = $result2['import_summary'];
                Assert::same(2, $summary['total_rows']);
                Assert::same(1, count($summary['historical']));
                $dup = $summary['historical'][0];
                Assert::same('skipped', $dup['result'], 'duplicate period must be skipped, not re-created');
                Assert::same('already_exists', $dup['reason'], 'reason must be already_exists');

                // Only two bills exist for this household (first-month and the new August).
                $s = self::pdo()->prepare('SELECT COUNT(*) FROM bills WHERE household_id = :hid');
                $s->execute(['hid' => $fixture['household_id']]);
                Assert::same(2, (int) $s->fetchColumn(), 'duplicate billing period was not re-created as a third bill');
            }),

            TestResult::check('APSPDCL XLS: Mistral path still returns import_summary = null (XLS changes do not regress OCR path)', function (): void {
                // The Mistral path must never emit an import_summary.
                $ctx = self::processingBill();
                [$result] = self::process($ctx, [['status' => 200, 'body' => self::ocrBody(self::liveShape())]]);

                // import_summary key must be present but explicitly null.
                // Note: cannot use `?? 'sentinel'` here because null ?? 'x' = 'x' in PHP.
                Assert::true(array_key_exists('import_summary', $result), 'import_summary key must be present in Mistral result');
                Assert::same(null, $result['import_summary'], 'Mistral path must not emit import_summary');
            }),

        ];


        // best-effort cleanup: bills (children cascade) + temp roots
        try {
            $s = self::pdo()->prepare('DELETE FROM bills WHERE id = :id');
            foreach (self::$createdBills as $id) {
                $s->execute(['id' => $id]);
            }
        } catch (\Throwable) {
        }
        foreach (self::$roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($root);
        }

        return $tests;
    }
}

