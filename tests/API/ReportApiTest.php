<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\ReportController;
use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Repositories\BillRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class ReportApiTest
{
    private static function pdo(): PDO
    {
        return Database::connection();
    }

    private static function controller(): ReportController
    {
        AuthTesting::configure();

        return new ReportController();
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25 12:00:00', new DateTimeZone('UTC'));
    }

    public static function run(): array
    {
        return [
            TestResult::check('GET /api/v1/reports/bills/export requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->exportBills(Request::create('GET', '/api/v1/reports/bills/export'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/reports/solar/export requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->exportSolar(Request::create('GET', '/api/v1/reports/solar/export'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('Account without household cannot access reports', function (): void {
                RequestId::set(RequestId::new());
                $orphanAccount = AuthTesting::activateAccount('orphan-rep-');

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/reports/bills/export'), $orphanAccount);

                try {
                    self::controller()->exportBills($req);
                    Assert::true(false, 'Expected 404 for account without active household');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }),

            TestResult::check('Empty dataset exports return CSV headers and zero rows cleanly', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();

                // Bill export empty
                $billReq = AuthTesting::authed(Request::create('GET', '/api/v1/reports/bills/export'), $fixture['account']);
                $billResp = self::controller()->exportBills($billReq);

                Assert::same(200, $billResp->status());
                Assert::contains('text/csv', $billResp->headers()['Content-Type']);
                Assert::contains('spms_bills_history_', $billResp->headers()['Content-Disposition']);
                $billContent = trim($billResp->content());
                Assert::contains('billing_period_start,billing_period_end,service_number', $billContent);
                // Only 1 line (the header)
                $billLines = explode("\n", $billContent);
                Assert::same(1, count($billLines));

                // Solar export empty
                $solarReq = AuthTesting::authed(Request::create('GET', '/api/v1/reports/solar/export'), $fixture['account']);
                $solarResp = self::controller()->exportSolar($solarReq);

                Assert::same(200, $solarResp->status());
                Assert::contains('text/csv', $solarResp->headers()['Content-Type']);
                Assert::contains('spms_solar_telemetry_', $solarResp->headers()['Content-Disposition']);
                $solarContent = trim($solarResp->content());
                Assert::contains('measurement_time,power,ac_power', $solarContent);
                $solarLines = explode("\n", $solarContent);
                Assert::same(1, count($solarLines));
            }),

            TestResult::check('GET /api/v1/reports/summary returns accurate counts and bounds', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('rep-sum-' . bin2hex(random_bytes(4)), 'USC-REP-001');
                $householdId = $fixture['household_id'];

                // Insert 1 verified bill
                $bill = Bill::create($householdId, '2024-01-01', '2024-01-31', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->verify(self::now())
                    ->withValues([
                        'consumption_kwh' => '350.000',
                        'bill_amount' => '1850.00',
                    ], self::now());
                (new BillRepository(self::pdo()))->insert($bill);

                // Insert 1 solar reading
                $snapshot = new NormalizedSolarSnapshot(
                    stationId: 'st-rep-1',
                    inverterSn: 'INV-REP-1',
                    measurementTime: new DateTimeImmutable('2026-09-25 10:00:00', new DateTimeZone('UTC')),
                    receivedAt: new DateTimeImmutable('2026-09-25 10:01:00', new DateTimeZone('UTC')),
                    powerW: 4200.50,
                    dailyGenerationKwh: 18.500,
                    totalGenerationKwh: 1250.000
                );
                (new SolarReadingRepository(self::pdo()))->insert(
                    'sr-' . bin2hex(random_bytes(16)),
                    $householdId,
                    $snapshot,
                    'batch-' . bin2hex(random_bytes(8))
                );

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/reports/summary'), $fixture['account']);
                $resp = self::controller()->summary($req);

                Assert::same(200, $resp->status());
                $data = $resp->body()['data']['reports'];
                Assert::same(1, $data['bills']['count']);
                Assert::same('2024-01-01', $data['bills']['first_date']);
                Assert::same('2024-01-31', $data['bills']['last_date']);
                Assert::same(1, $data['solar']['count']);
                Assert::same('2026-09-25 10:00:00', $data['solar']['first_date']);
            }),

            TestResult::check('GET /api/v1/reports/bills/export generates CSV, masks service number, and excludes untrusted records', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('rep-bill-' . bin2hex(random_bytes(4)), '1234567890');
                $householdId = $fixture['household_id'];
                $repo = new BillRepository(self::pdo());

                // 1. Valid verified bill (earlier period)
                $b1 = Bill::create($householdId, '2024-01-01', '2024-01-31', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->verify(self::now())
                    ->withValues([
                        BillField::SERVICE_NUMBER => '1234567890',
                        'consumption_kwh' => '320.000',
                        'solar_units_kwh' => '150.000',
                        'net_units_kwh' => '170.000',
                        'bill_amount' => '1200.00',
                        'energy_charges' => '1000.00',
                        'fixed_charges' => '150.00',
                        'other_charges' => '50.00',
                        'source' => 'APSPDCL_EXPORT',
                    ], self::now());
                $repo->insert($b1);

                // 2. Valid extracted bill (later period)
                $b2 = Bill::create($householdId, '2024-02-01', '2024-02-28', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->withValues([
                        BillField::SERVICE_NUMBER => '1234567890',
                        'consumption_kwh' => '280.000',
                        'bill_amount' => '950.00',
                    ], self::now());
                $repo->insert($b2);

                // 3. Failed bill (must be EXCLUDED)
                $bFailed = Bill::create($householdId, '2024-03-01', '2024-03-31', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::PROCESSING_FAILED, self::now());
                $repo->insert($bFailed);

                // 4. Untrusted unreadable bill (must be EXCLUDED)
                $bUnreadable = Bill::create($householdId, '2024-04-01', '2024-04-30', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::UNREADABLE, self::now());
                $repo->insert($bUnreadable);

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/reports/bills/export'), $fixture['account']);
                $resp = self::controller()->exportBills($req);

                Assert::same(200, $resp->status());
                $csv = $resp->content();
                $lines = explode("\n", trim($csv));

                // Header + exactly 2 valid bill lines
                Assert::same(3, count($lines));

                // Line 1: Header
                Assert::contains('billing_period_start,billing_period_end', $lines[0]);

                // Line 2: First period chronologically (2024-01-01)
                Assert::contains('2024-01-01,2024-01-31', $lines[1]);
                Assert::contains('320.000,150.000,170.000,1200.00', $lines[1]);
                Assert::contains('VERIFIED,APSPDCL_EXPORT', $lines[1]);

                // Service number is masked
                Assert::contains('••••••7890', $lines[1]);

                // Line 3: Second period (2024-02-01)
                Assert::contains('2024-02-01,2024-02-28', $lines[2]);
                Assert::contains('280.000', $lines[2]);

                // Failed and unreadable periods do NOT exist in the CSV
                Assert::false(str_contains($csv, '2024-03-01'));
                Assert::false(str_contains($csv, '2024-04-01'));
            }),

            TestResult::check('GET /api/v1/reports/bills/export enforces household isolation', function (): void {
                RequestId::set(RequestId::new());
                $fixtureA = AuthTesting::householdFixture('rep-iso-a-' . bin2hex(random_bytes(4)));
                $fixtureB = AuthTesting::householdFixture('rep-iso-b-' . bin2hex(random_bytes(4)));
                $repo = new BillRepository(self::pdo());

                // Insert bill for Household A
                $billA = Bill::create($fixtureA['household_id'], '2024-05-01', '2024-05-31', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->verify(self::now())
                    ->withValues([
                        'consumption_kwh' => '999.000',
                        'bill_amount' => '8888.00',
                    ], self::now());
                $repo->insert($billA);

                // Request from Household B
                $reqB = AuthTesting::authed(Request::create('GET', '/api/v1/reports/bills/export'), $fixtureB['account']);
                $respB = self::controller()->exportBills($reqB);

                // Household B must see 0 data rows (empty)
                $csvB = trim($respB->content());
                Assert::false(str_contains($csvB, '999.000'));
                Assert::false(str_contains($csvB, '8888.00'));
                Assert::same(1, count(explode("\n", $csvB)));
            }),

            TestResult::check('GET /api/v1/reports/solar/export generates chronological telemetry and enforces household isolation', function (): void {
                RequestId::set(RequestId::new());
                $fixtureA = AuthTesting::householdFixture('rep-sol-a-' . bin2hex(random_bytes(4)));
                $fixtureB = AuthTesting::householdFixture('rep-sol-b-' . bin2hex(random_bytes(4)));
                $solarRepo = new SolarReadingRepository(self::pdo());

                // 2 chronological readings for Household A
                $snap1 = new NormalizedSolarSnapshot(
                    stationId: 'st-iso-1',
                    inverterSn: 'GW-ISO-1',
                    measurementTime: new DateTimeImmutable('2026-09-25 08:00:00', new DateTimeZone('UTC')),
                    receivedAt: new DateTimeImmutable('2026-09-25 08:01:00', new DateTimeZone('UTC')),
                    powerW: 1500.00,
                    dailyGenerationKwh: 3.200,
                    totalGenerationKwh: 500.000,
                    inverterStatus: 'Normal',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $snap2 = new NormalizedSolarSnapshot(
                    stationId: 'st-iso-1',
                    inverterSn: 'GW-ISO-1',
                    measurementTime: new DateTimeImmutable('2026-09-25 09:00:00', new DateTimeZone('UTC')),
                    receivedAt: new DateTimeImmutable('2026-09-25 09:01:00', new DateTimeZone('UTC')),
                    powerW: 3200.00,
                    dailyGenerationKwh: 6.400,
                    totalGenerationKwh: 503.200,
                    inverterStatus: 'Normal',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );

                $solarRepo->insert('sr-' . bin2hex(random_bytes(16)), $fixtureA['household_id'], $snap1, 'batch-1');
                $solarRepo->insert('sr-' . bin2hex(random_bytes(16)), $fixtureA['household_id'], $snap2, 'batch-1');

                // Household A export
                $reqA = AuthTesting::authed(Request::create('GET', '/api/v1/reports/solar/export'), $fixtureA['account']);
                $respA = self::controller()->exportSolar($reqA);

                Assert::same(200, $respA->status());
                $csvA = $respA->content();
                $linesA = explode("\n", trim($csvA));
                Assert::same(3, count($linesA));

                // Chronological: 08:00:00 first, 09:00:00 second
                Assert::contains('2026-09-25 08:00:00', $linesA[1]);
                Assert::contains('1500.00,1500.00', $linesA[1]);
                Assert::contains('2026-09-25 09:00:00', $linesA[2]);
                Assert::contains('3200.00,3200.00', $linesA[2]);
                Assert::contains('ACTUAL,FRESH', $linesA[1]);

                // Household B export (must be isolated)
                $reqB = AuthTesting::authed(Request::create('GET', '/api/v1/reports/solar/export'), $fixtureB['account']);
                $respB = self::controller()->exportSolar($reqB);
                $csvB = trim($respB->content());
                Assert::false(str_contains($csvB, '1500.00'));
                Assert::false(str_contains($csvB, '3200.00'));
                Assert::same(1, count(explode("\n", $csvB)));
            }),

            TestResult::check('GET /api/v1/reports/monthly requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->monthly(Request::create('GET', '/api/v1/reports/monthly'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/reports/monthly rejects future month with 422', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();

                $req = AuthTesting::authed(
                    Request::create('GET', '/api/v1/reports/monthly?month=2099-12'),
                    $fixture['account']
                );

                try {
                    self::controller()->monthly($req);
                    Assert::true(false, 'Expected 422 for future month');
                } catch (AppException $e) {
                    Assert::same('FUTURE_MONTH_NOT_ALLOWED', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/reports/monthly returns structured KPIs and enforces trust boundaries', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('rep-m-' . bin2hex(random_bytes(4)), '1234567890');
                $householdId = $fixture['household_id'];
                $repo = new BillRepository(self::pdo());

                // 1 verified bill for September 2026
                $b1 = Bill::create($householdId, '2026-09-01', '2026-09-30', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->verify(self::now())
                    ->withValues([
                        'consumption_kwh' => '300.000',
                        'solar_units_kwh' => '50.000',
                        'bill_amount' => '1500.00',
                        'energy_charges' => '1200.00',
                        'fixed_charges' => '200.00',
                    ], self::now());
                $repo->insert($b1);

                // 1 needs_review bill for September 2026 (awaiting review)
                $b2 = Bill::create($householdId, '2026-09-15', '2026-09-30', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->transitionTo(BillStatus::NEEDS_REVIEW, self::now())
                    ->withValues([
                        'consumption_kwh' => '100.000',
                    ], self::now());
                $repo->insert($b2);

                // Insert solar readings
                $snap = new NormalizedSolarSnapshot(
                    stationId: 'st-m-1',
                    inverterSn: 'GW-M-1',
                    measurementTime: new DateTimeImmutable('2026-09-10 12:00:00', new DateTimeZone('UTC')),
                    receivedAt: new DateTimeImmutable('2026-09-10 12:01:00', new DateTimeZone('UTC')),
                    powerW: 2500.00,
                    dailyGenerationKwh: 14.500,
                    totalGenerationKwh: 600.000,
                    inverterStatus: 'Normal',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                (new SolarReadingRepository(self::pdo()))->insert(
                    'sr-' . bin2hex(random_bytes(16)),
                    $householdId,
                    $snap,
                    'batch-m'
                );

                $req = AuthTesting::authed(
                    Request::create('GET', '/api/v1/reports/monthly?month=2026-09'),
                    $fixture['account']
                );
                $resp = self::controller()->monthly($req);

                Assert::same(200, $resp->status());
                $data = $resp->body()['data'];

                // Period
                Assert::same('2026-09', $data['period']['month']);
                Assert::same('September 2026', $data['period']['label']);
                Assert::same('2026-09-01', $data['period']['start_date']);
                Assert::same('2026-09-30', $data['period']['end_date']);

                // KPIs & Trust Boundaries
                Assert::same('ACTUAL', $data['kpis']['solar_data_nature']);
                Assert::same(14.5, $data['kpis']['solar_generation_kwh']);
                Assert::same('ESTIMATED', $data['kpis']['demand_data_nature']);
                Assert::same('VERIFIED_BILL_STATEMENT', $data['kpis']['demand_source']);
                Assert::same(300.0, $data['kpis']['household_demand_kwh']);
                Assert::same('POTENTIAL', $data['kpis']['balance_data_nature']);
                Assert::same('DEFICIT', $data['kpis']['balance_status']);
                Assert::same(1500.0, $data['kpis']['energy_cost_inr']);

                // Bill counts: 1 verified, 1 awaiting review
                Assert::same(1, $data['kpis']['bills_summary']['verified_count']);
                Assert::same(1, $data['kpis']['bills_summary']['awaiting_review_count']);
                Assert::same(2, $data['kpis']['bills_summary']['total_count']);

                // Factual insight
                Assert::contains('Net Energy Deficit', $data['insight']['headline']);
                Assert::true(count($data['insight']['limitations']) > 0);

                // Daily trends has 30 days
                Assert::same(30, count($data['daily_trends']));
                Assert::same(14.5, $data['daily_trends'][9]['solar_generation_kwh']); // day 10
            }),

            TestResult::check('GET /api/v1/reports/monthly/export/excel returns valid multi-sheet XLSX', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('rep-xl-' . bin2hex(random_bytes(4)));

                $req = AuthTesting::authed(
                    Request::create('GET', '/api/v1/reports/monthly/export/excel?month=2026-09'),
                    $fixture['account']
                );
                $resp = self::controller()->exportExcel($req);

                Assert::same(200, $resp->status());
                Assert::same('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $resp->headers()['Content-Type']);
                Assert::contains('SPMS_Monthly_Energy_Report_September_2026.xlsx', $resp->headers()['Content-Disposition']);

                // Validate binary using ZipArchive
                $tmp = sys_get_temp_dir() . '/test_xl_' . bin2hex(random_bytes(4)) . '.xlsx';
                file_put_contents($tmp, $resp->content());

                $zip = new \ZipArchive();
                Assert::true($zip->open($tmp) === true, 'Failed to open generated XLSX archive');
                Assert::true($zip->locateName('xl/worksheets/sheet1.xml') !== false, 'Missing Summary sheet');
                Assert::true($zip->locateName('xl/worksheets/sheet2.xml') !== false, 'Missing Monthly Trends sheet');
                Assert::true($zip->locateName('xl/worksheets/sheet3.xml') !== false, 'Missing Billing sheet');
                Assert::true($zip->locateName('xl/worksheets/sheet4.xml') !== false, 'Missing Solar Data sheet');
                $zip->close();
                @unlink($tmp);
            }),

            TestResult::check('GET /api/v1/reports/monthly/export/pdf requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->exportPdf(Request::create('GET', '/api/v1/reports/monthly/export/pdf'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/reports/monthly/export/pdf rejects future month with 422', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('rep-pdf-fut-' . bin2hex(random_bytes(4)));

                $req = AuthTesting::authed(
                    Request::create('GET', '/api/v1/reports/monthly/export/pdf?month=2099-12'),
                    $fixture['account']
                );

                try {
                    self::controller()->exportPdf($req);
                    Assert::true(false, 'Expected 422 for future month');
                } catch (AppException $e) {
                    Assert::same('FUTURE_MONTH_NOT_ALLOWED', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/reports/monthly/export/pdf returns presentation-ready PDF report', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('rep-pdf-' . bin2hex(random_bytes(4)), '9876543210');
                $householdId = $fixture['household_id'];

                // Insert 1 verified bill with billing period differing from calendar month
                $b = Bill::create($householdId, '2026-09-01', '2026-10-01', self::now())
                    ->transitionTo(BillStatus::PROCESSING, self::now())
                    ->transitionTo(BillStatus::EXTRACTED, self::now())
                    ->verify(self::now())
                    ->withValues([
                        'consumption_kwh' => '348.000',
                        'solar_units_kwh' => '72.000',
                        'bill_amount' => '1850.00',
                    ], self::now());
                (new BillRepository(self::pdo()))->insert($b);

                // Insert solar reading
                $snap = new NormalizedSolarSnapshot(
                    stationId: 'st-pdf-1',
                    inverterSn: 'GW-PDF-1',
                    measurementTime: new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC')),
                    receivedAt: new DateTimeImmutable('2026-09-15 12:01:00', new DateTimeZone('UTC')),
                    powerW: 3200.00,
                    dailyGenerationKwh: 16.800,
                    totalGenerationKwh: 850.000,
                    inverterStatus: 'Normal',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                (new SolarReadingRepository(self::pdo()))->insert(
                    'sr-' . bin2hex(random_bytes(16)),
                    $householdId,
                    $snap,
                    'batch-pdf'
                );

                $req = AuthTesting::authed(
                    Request::create('GET', '/api/v1/reports/monthly/export/pdf?month=2026-09'),
                    $fixture['account']
                );
                $resp = self::controller()->exportPdf($req);

                Assert::same(200, $resp->status());
                Assert::same('application/pdf', $resp->headers()['Content-Type']);
                Assert::contains('SPMS_Monthly_Energy_Report_September_2026.pdf', $resp->headers()['Content-Disposition']);

                // Validate PDF magic bytes: %PDF-
                $content = $resp->content();
                Assert::true(str_starts_with($content, '%PDF-'), 'Output does not start with PDF magic header %PDF-');
                Assert::true(strlen($content) > 10000, 'PDF output is too small to be a real multi-page report');
            }),

            TestResult::check('GET /api/v1/reports/monthly identifies SIMULATED solar provenance when telemetry is simulated', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('rep-sim-' . bin2hex(random_bytes(4)));
                $householdId = $fixture['household_id'];

                // Insert solar reading with SIMULATED data nature
                $snap = new NormalizedSolarSnapshot(
                    stationId: 'st-sim-1',
                    inverterSn: 'GW-SIM-1',
                    measurementTime: new DateTimeImmutable('2026-08-15 12:00:00', new DateTimeZone('UTC')),
                    receivedAt: new DateTimeImmutable('2026-08-15 12:01:00', new DateTimeZone('UTC')),
                    powerW: 2400.00,
                    dailyGenerationKwh: 12.500,
                    totalGenerationKwh: 600.000,
                    inverterStatus: 'Normal',
                    dataNature: 'SIMULATED',
                    dataStatus: 'FRESH'
                );
                (new SolarReadingRepository(self::pdo()))->insert(
                    'sr-' . bin2hex(random_bytes(16)),
                    $householdId,
                    $snap,
                    'batch-sim'
                );

                $req = AuthTesting::authed(
                    Request::create('GET', '/api/v1/reports/monthly?month=2026-08'),
                    $fixture['account']
                );
                $resp = self::controller()->monthly($req);

                Assert::same(200, $resp->status());
                $data = $resp->body()['data'];

                // KPIs & Trust Boundaries must clearly identify SIMULATED
                Assert::same('SIMULATED', $data['kpis']['solar_data_nature']);
                Assert::same('Simulated DEMO', $data['kpis']['solar_provenance']);
                Assert::same(12.5, $data['kpis']['solar_generation_kwh']);

                // Insight disclosures must reflect simulation
                Assert::contains('Simulated', $data['insight']['solar_statement']);
                Assert::contains('SIMULATED solar', $data['insight']['provenance_line']);

                // PDF export also succeeds for simulated data
                $pdfReq = AuthTesting::authed(
                    Request::create('GET', '/api/v1/reports/monthly/export/pdf?month=2026-08'),
                    $fixture['account']
                );
                $pdfResp = self::controller()->exportPdf($pdfReq);
                Assert::same(200, $pdfResp->status());
                Assert::same('application/pdf', $pdfResp->headers()['Content-Type']);
                Assert::true(str_starts_with($pdfResp->content(), '%PDF-'));
            }),
        ];
    }
}
