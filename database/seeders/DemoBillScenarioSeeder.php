<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillTempDocument;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillTempDocumentRepository;
use App\Services\BillExtraction\BillExtractionGateway;
use App\Services\BillExtraction\MistralBillExtractionProvider;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\MistralOcrClient;
use App\Support\Config;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * DemoBillScenarioSeeder — preserves existing DEMO-01 through DEMO-08 bill scenarios.
 *
 * Runs strictly through the authentic application pipeline:
 * B2 intake -> temp document -> OCR client (fixture response) -> normalizer ->
 * validator -> persistence -> lifecycle.
 */
final class DemoBillScenarioSeeder
{
    /**
     * @param PDO $pdo
     * @param array<string, string> $passwords Output map of email => password
     * @param (callable(string): void)|null $logger
     * @return array<string, array{bill_id: string, status: string, flags: array<int, string>}>
     */
    public static function seed(PDO $pdo, array &$passwords, ?callable $logger = null): array
    {
        $log = $logger ?? static function (string $msg): void {
            // no-op by default
        };

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

        $fakeOcr = static fn (string $body): BillExtractionGateway => new BillExtractionGateway([
            new MistralBillExtractionProvider(new MistralOcrClient(
                'mk_FAKE-DEMO-NEVER-REAL',
                'mistral-ocr-demo-fixture',
                static fn (string $method, string $url, array $options): array => ['status' => 200, 'body' => $body]
            )),
        ]);

        $makeAccount = static function (string $localPart, string $displayName) use (&$passwords, $pdo): Account {
            $password = bin2hex(random_bytes(12)) . 'Aa1!';
            $email = $localPart . '@demo.spms.local';
            $service = new AccountService();
            $service->register($email, $password, '10.255.' . random_int(1, 250) . '.' . random_int(1, 250));

            $dir = (string) (Config::get('MAIL_FILE_DIR') ?? (dirname(__DIR__, 2) . '/storage/mail'));
            if (!is_dir($dir)) {
                $dir = dirname(__DIR__, 2) . '/storage/mail';
            }
            $files = glob($dir . '/*.eml') ?: [];
            sort($files);
            $token = null;
            foreach (array_reverse($files) as $file) {
                $content = (string) file_get_contents($file);
                if (str_contains($content, 'Verify your email') && str_contains($content, $email) && preg_match('/\b(\d{6})\b/', $content, $m) === 1) {
                    $token = $m[1];
                    break;
                }
            }
            if ($token === null) {
                foreach (array_reverse($files) as $file) {
                    $content = (string) file_get_contents($file);
                    if (str_contains($content, 'Verify your email') && preg_match('/\b(\d{6})\b/', $content, $m) === 1) {
                        $token = $m[1];
                        break;
                    }
                }
            }
            if ($token === null) {
                throw new RuntimeException("no verification token for {$email}");
            }
            $account = $service->verifyEmail($token);

            // Server-side DEMO_TEST mode assignment (trusted CLI seed only)
            $pdo->prepare("UPDATE accounts SET mode = 'DEMO_TEST' WHERE id = :id")
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

        $scenarios = [];

        $main = $makeAccount('demo.main', 'Demo Main');
        $makeHousehold($main, 'Demo household — full scenarios', '1000-2000-300');

        // DEMO-01: normal valid bill, service MATCH, meter/solar/net all PASS (Aug 2026).
        $scenarios['DEMO-01 normal valid (service match, all PASS)'] = $runBill($main, $baseBill());

        // DEMO-02: net conflict — +177 extracted, expected -177 (Jul 2026, test-02 shape).
        $scenarios['DEMO-02 net conflict (+177 vs expected -177)'] = $runBill($main, $baseBill([
            'billing_period' => ['start' => '2026-07-01', 'end' => '2026-08-01', 'confidence' => 0.95],
            'solar' => array_merge($baseBill()['solar'], ['net_units_kwh' => 177]),
        ]));

        // DEMO-03: missing solar evidence — solar/net NOT_CHECKED (Jun 2026).
        $scenarios['DEMO-03 missing solar (NOT_CHECKED)'] = $runBill($main, $baseBill([
            'billing_period' => ['start' => '2026-06-01', 'end' => '2026-07-01', 'confidence' => 0.95],
            'solar' => ['previous_solar_reading' => null, 'present_solar_reading' => null, 'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null],
            'context' => ['utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)', 'meter_type' => 'ANALOG', 'contracted_load' => '1', 'service_number' => '1000-2000-300', 'confidence' => 0.9],
        ]));

        // DEMO-04: wrong document — logo-like annotation.
        $scenarios['DEMO-04 wrong document (NOT_A_BILL)'] = $runBill($main, $baseBill(
            ['document_assessment' => ['status' => 'NOT_A_BILL', 'reason' => 'brand logo, not an electricity bill', 'confidence' => 0.98]] + $emptySections
        ));

        // DEMO-05: service mismatch — the bill carries ANOTHER demo household's number (May 2026).
        $other = $makeAccount('demo.other', 'Demo Other');
        $makeHousehold($other, 'Demo household — foreign service number owner', '555-666-777');
        $scenarios['DEMO-05 service mismatch (foreign number)'] = $runBill($main, $baseBill([
            'billing_period' => ['start' => '2026-05-01', 'end' => '2026-06-01', 'confidence' => 0.95],
            'context' => ['utility_provider' => 'DEMO GRID UTILITY LTD', 'tariff_category' => 'DOMESTIC (A)', 'meter_type' => 'SMART SOLAR', 'contracted_load' => '3', 'service_number' => '555 666 777', 'confidence' => 0.95],
        ]));

        // DEMO-06: unreadable document.
        $scenarios['DEMO-06 unreadable document'] = $runBill($main, $baseBill(
            ['document_assessment' => ['status' => 'UNREADABLE', 'reason' => 'blurred photo', 'confidence' => 0.42]] + $emptySections
        ));

        // DEMO-07: low-data household — one limited bill (history thin for forecasting).
        $low = $makeAccount('demo.low', 'Demo Low Data');
        $makeHousehold($low, 'Demo household — low data', '1000-2000-300');
        $scenarios['DEMO-07 low-data household (limitations bill)'] = $runBill($low, $baseBill([
            'document_assessment' => ['status' => 'VALID_WITH_LIMITATIONS', 'reason' => 'partially faded register', 'confidence' => 0.6],
            'solar' => ['previous_solar_reading' => null, 'present_solar_reading' => null, 'solar_units_kwh' => null, 'net_units_kwh' => null, 'confidence' => null],
            'cost' => ['bill_amount' => null, 'arrears' => null, 'total_due' => null, 'energy_charges' => null, 'fixed_charges' => null, 'customer_service_charges' => null, 'other_charges' => null, 'taxes_adjustments' => null, 'confidence' => null],
        ]));

        // DEMO-08: solar/data-unavailable household — exists, owns nothing yet.
        $noData = $makeAccount('demo.empty', 'Demo No Data');
        $makeHousehold($noData, 'Demo household — reserved (no bills)', '1000-2000-999');
        $scenarios['DEMO-08 reserved household (zero bills)'] = ['bill_id' => '-', 'status' => 'N/A (placeholder)', 'flags' => []];

        return $scenarios;
    }
}
