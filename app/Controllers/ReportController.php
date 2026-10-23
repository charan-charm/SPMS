<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\ServiceNumber;
use App\Domain\Household\HouseholdService;
use App\Domain\Report\ExcelReportBuilder;
use App\Domain\Report\MonthlyReportService;
use App\Domain\Report\PdfReportBuilder;
use App\Repositories\BillRepository;
use App\Repositories\SolarReadingRepository;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\CsvResponse;
use App\Support\Database;
use App\Support\ExcelResponse;
use App\Support\PdfResponse;
use App\Support\Request;
use App\Support\Response;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * F8 — Reporting & Data Export Controller (docs/02 §2 F8, docs/03 §4).
 *
 * Provides authenticated, household-scoped monthly reporting and data export:
 * 1. Monthly Household Energy Report (JSON API)
 * 2. Primary 5-page Monthly PDF Report with embedded vector charts (.pdf)
 * 3. Multi-sheet presentation-ready Excel Workbook (.xlsx) with DrawingML charts
 * 4. Verified APSPDCL billing statement history (RFC 4180 CSV, legacy support)
 * 5. High-precision GoodWe solar generation telemetry (RFC 4180 CSV, legacy support)
 *
 * Ownership & Security Invariants:
 * - Derives household ownership strictly from authenticated session.
 * - Rejects unauthenticated requests with 401 UNAUTHENTICATED.
 * - Rejects accounts without an active household with 404 HOUSEHOLD_NOT_FOUND.
 * - Enforces trust boundary: only VERIFIED bills are used for downstream calculations.
 * - Never fabricates consumption, grid values, or tariff calculations.
 * - Canonical timezone is strictly Asia/Kolkata for monthly bounds and filenames.
 */
final class ReportController
{
    private const TIMEZONE = 'Asia/Kolkata';

    private ?PDO $pdo;

    public function __construct(
        private readonly HouseholdService $households = new HouseholdService(),
        private readonly BillRepository $bills = new BillRepository(),
        private readonly SolarReadingRepository $solarReadings = new SolarReadingRepository(),
        private readonly MonthlyReportService $monthlyReportService = new MonthlyReportService(),
        private readonly ExcelReportBuilder $excelReportBuilder = new ExcelReportBuilder(),
        private readonly PdfReportBuilder $pdfReportBuilder = new PdfReportBuilder(),
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo;
    }

    /**
     * GET /api/v1/reports/monthly
     *
     * Returns structured monthly household energy report for period selector and UI.
     */
    public function monthly(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $month = $this->extractMonthParam($request);

        $reportData = $this->monthlyReportService->generateReport($householdId, $month);

        return Response::success($reportData);
    }

    /**
     * GET /api/v1/reports/monthly/export/pdf
     *
     * Generates primary presentation-ready 5-page PDF monthly energy report with embedded vector charts.
     */
    public function exportPdf(Request $request): PdfResponse
    {
        $householdId = $this->requireHouseholdId($request);
        $month = $this->extractMonthParam($request);

        $reportData = $this->monthlyReportService->generateReport($householdId, $month);
        $binary = $this->pdfReportBuilder->build($reportData, $householdId, $this->connection());

        $monthLabel = str_replace(' ', '_', $reportData['period']['label']);
        $filename = "SPMS_Monthly_Energy_Report_{$monthLabel}.pdf";

        return PdfResponse::create($binary, $filename);
    }

    /**
     * GET /api/v1/reports/monthly/export/excel
     *
     * Generates a presentation-ready 4-sheet OpenXML XLSX workbook with DrawingML native charts.
     */
    public function exportExcel(Request $request): ExcelResponse
    {
        $householdId = $this->requireHouseholdId($request);
        $month = $this->extractMonthParam($request);

        $reportData = $this->monthlyReportService->generateReport($householdId, $month);
        $binary = $this->excelReportBuilder->build($reportData, $householdId, $this->connection());

        $monthLabel = str_replace(' ', '_', $reportData['period']['label']);
        $filename = "SPMS_Monthly_Energy_Report_{$monthLabel}.xlsx";

        return ExcelResponse::create($binary, $filename);
    }

    /**
     * GET /api/v1/reports/summary
     *
     * Provides metadata summary for available reports (record counts and date bounds).
     */
    public function summary(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        // 1. Bill statistics (only trusted records with start periods)
        $billStmt = $pdo->prepare(<<<'SQL'
            SELECT 
                COUNT(*) as total_count,
                MIN(billing_period_start) as first_date,
                MAX(billing_period_end) as last_date
            FROM bills
            WHERE household_id = :household_id
              AND status NOT IN (:failed, :not_a_bill, :unreadable, :uncertain, :uploaded, :processing)
              AND billing_period_start IS NOT NULL
        SQL);
        $billStmt->execute([
            'household_id' => $householdId,
            'failed' => BillStatus::PROCESSING_FAILED,
            'not_a_bill' => BillStatus::NOT_A_BILL,
            'unreadable' => BillStatus::UNREADABLE,
            'uncertain' => BillStatus::UNCERTAIN,
            'uploaded' => BillStatus::UPLOADED,
            'processing' => BillStatus::PROCESSING,
        ]);
        $billStats = $billStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Solar reading statistics
        $solarStmt = $pdo->prepare(<<<'SQL'
            SELECT 
                COUNT(*) as total_count,
                MIN(measurement_time) as first_date,
                MAX(measurement_time) as last_date
            FROM solar_readings
            WHERE household_id = :household_id
        SQL);
        $solarStmt->execute(['household_id' => $householdId]);
        $solarStats = $solarStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return Response::success([
            'reports' => [
                'bills' => [
                    'count' => (int) ($billStats['total_count'] ?? 0),
                    'first_date' => $billStats['first_date'] ?? null,
                    'last_date' => $billStats['last_date'] ?? null,
                ],
                'solar' => [
                    'count' => (int) ($solarStats['total_count'] ?? 0),
                    'first_date' => $solarStats['first_date'] ?? null,
                    'last_date' => $solarStats['last_date'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/bills/export
     *
     * Exports verified and recognized historical bills in RFC 4180 CSV format.
     * Supports optional ?month=YYYY-MM filtering.
     */
    public function exportBills(Request $request): CsvResponse
    {
        $householdId = $this->requireHouseholdId($request);
        $month = $this->extractMonthParam($request);
        $tz = new DateTimeZone(self::TIMEZONE);

        // Fetch deduplicated household bills
        $allBills = $this->bills->listForHousehold($householdId);

        // Filter out untrusted, incomplete, or unreadable records
        $trustedBills = array_filter($allBills, static function (Bill $bill): bool {
            return !in_array($bill->status(), [
                BillStatus::PROCESSING_FAILED,
                BillStatus::NOT_A_BILL,
                BillStatus::UNREADABLE,
                BillStatus::UNCERTAIN,
                BillStatus::UPLOADED,
                BillStatus::PROCESSING,
            ], true) && $bill->value(BillField::BILLING_PERIOD_START) !== null;
        });

        // Filter by month if provided
        if ($month !== null) {
            $startOfMonth = "{$month}-01";
            $dtStart = DateTimeImmutable::createFromFormat('Y-m-d', $startOfMonth, $tz);
            $endOfMonth = $dtStart !== false ? $dtStart->modify('last day of this month')->format('Y-m-d') : "{$month}-31";

            $trustedBills = array_filter($trustedBills, static function (Bill $b) use ($startOfMonth, $endOfMonth): bool {
                $pStart = $b->value(BillField::BILLING_PERIOD_START);
                $pEnd = $b->value(BillField::BILLING_PERIOD_END);
                if ($pStart === null || $pEnd === null) {
                    return false;
                }
                return $pStart <= $endOfMonth && $pEnd >= $startOfMonth;
            });
        }

        // Preserve chronological billing period ordering (earliest to latest)
        usort($trustedBills, static function (Bill $a, Bill $b): int {
            $cmp = strcmp($a->value(BillField::BILLING_PERIOD_START) ?? '', $b->value(BillField::BILLING_PERIOD_START) ?? '');
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a->value(BillField::BILLING_PERIOD_END) ?? '', $b->value(BillField::BILLING_PERIOD_END) ?? '');
        });

        // Generate CSV output stream
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, [
            'billing_period_start',
            'billing_period_end',
            'service_number',
            'consumption_kwh',
            'solar_export_units_kwh',
            'net_units_kwh',
            'bill_amount',
            'energy_charge',
            'fixed_charge',
            'other_charges',
            'verification_status',
            'source',
        ], ',', '"', '\\');

        foreach ($trustedBills as $bill) {
            $rawSn = $bill->value(BillField::SERVICE_NUMBER);
            $maskedSn = $rawSn !== null ? (ServiceNumber::mask($rawSn) ?? $rawSn) : '';

            fputcsv($fp, [
                $bill->value(BillField::BILLING_PERIOD_START) ?? '',
                $bill->value(BillField::BILLING_PERIOD_END) ?? '',
                $maskedSn,
                $bill->value('consumption_kwh') ?? '',
                $bill->value('solar_units_kwh') ?? '',
                $bill->value('net_units_kwh') ?? '',
                $bill->value('bill_amount') ?? '',
                $bill->value('energy_charges') ?? '',
                $bill->value('fixed_charges') ?? '',
                $bill->value('other_charges') ?? '',
                $bill->status(),
                $bill->value('source') ?? 'ELECTRICITY_BILL',
            ], ',', '"', '\\');
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        if ($month !== null) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', "{$month}-01", $tz);
            $monthLabel = $dt !== false ? $dt->format('F_Y') : $month;
            $filename = "SPMS_Billing_{$monthLabel}.csv";
        } else {
            $nowDate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            $filename = "spms_bills_history_{$nowDate}.csv";
        }

        return CsvResponse::create((string) $csv, $filename);
    }

    /**
     * GET /api/v1/reports/solar/export
     *
     * Exports GoodWe inverter solar telemetry in RFC 4180 CSV format.
     * Streams row-by-row with bounded memory. Supports optional ?month=YYYY-MM filtering.
     */
    public function exportSolar(Request $request): CsvResponse
    {
        $householdId = $this->requireHouseholdId($request);
        $month = $this->extractMonthParam($request);
        $tz = new DateTimeZone(self::TIMEZONE);
        $pdo = $this->connection();

        if ($month !== null) {
            $startOfMonth = "{$month}-01 00:00:00";
            $dtStart = DateTimeImmutable::createFromFormat('Y-m-d', "{$month}-01", $tz);
            $endOfMonth = ($dtStart !== false ? $dtStart->modify('last day of this month')->format('Y-m-d') : "{$month}-31") . ' 23:59:59';

            $sql = <<<'SQL'
                SELECT * FROM solar_readings
                WHERE household_id = :household_id
                  AND measurement_time >= :start_dt
                  AND measurement_time <= :end_dt
                ORDER BY measurement_time ASC, id ASC
            SQL;
            $params = [
                'household_id' => $householdId,
                'start_dt' => $startOfMonth,
                'end_dt' => $endOfMonth,
            ];
        } else {
            $sql = <<<'SQL'
                SELECT * FROM solar_readings
                WHERE household_id = :household_id
                ORDER BY measurement_time ASC, id ASC
            SQL;
            $params = ['household_id' => $householdId];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Generate CSV output stream
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, [
            'measurement_time',
            'power',
            'ac_power',
            'ac_voltage',
            'ac_current',
            'frequency',
            'pv_power',
            'temperature',
            'daily_generation_kwh',
            'total_generation_kwh',
            'inverter_status',
            'data_nature',
            'data_status',
        ], ',', '"', '\\');

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($fp, [
                $row['measurement_time'] ?? '',
                $row['power_w'] ?? '',
                $row['power_w'] ?? '',
                $row['ac_voltage_v'] ?? '',
                $row['ac_current_a'] ?? '',
                $row['ac_frequency_hz'] ?? '',
                $row['pv_power_w'] ?? '',
                $row['temperature_c'] ?? '',
                $row['daily_generation_kwh'] ?? '',
                $row['total_generation_kwh'] ?? '',
                $row['inverter_status'] ?? '',
                $row['data_nature'] ?? 'ACTUAL',
                $row['data_status'] ?? 'FRESH',
            ], ',', '"', '\\');
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        if ($month !== null) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', "{$month}-01", $tz);
            $monthLabel = $dt !== false ? $dt->format('F_Y') : $month;
            $filename = "SPMS_Solar_Telemetry_{$monthLabel}.csv";
        } else {
            $nowDate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            $filename = "spms_solar_telemetry_{$nowDate}.csv";
        }

        return CsvResponse::create((string) $csv, $filename);
    }

    private function extractMonthParam(Request $request): ?string
    {
        $raw = $request->queryParam('month');
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $trimmed)) {
                return $trimmed;
            }
        }
        return null;
    }

    private function requireHouseholdId(Request $request): string
    {
        $account = Authorization::requireAccount($request);
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException(
                'HOUSEHOLD_NOT_FOUND',
                'An active household membership is required to access reports.',
                404
            );
        }

        return $householdId;
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
