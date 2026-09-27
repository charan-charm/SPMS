<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\ServiceNumber;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Repositories\SolarReadingRepository;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\CsvResponse;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * F8 — Reporting & Data Export Controller (docs/02 §2 F8, docs/03 §4).
 *
 * Provides authenticated, household-scoped data export for:
 * 1. Verified billing history (APSPDCL bills)
 * 2. High-precision GoodWe solar generation telemetry
 *
 * Ownership & Security Invariants:
 * - Derives household ownership strictly from authenticated session.
 * - Rejects unauthenticated requests with 401 UNAUTHENTICATED.
 * - Rejects accounts without an active household with 404 HOUSEHOLD_NOT_FOUND.
 * - Excludes failed, unreadable, uncertain, and non-bill records from export.
 * - Never fabricates consumption or grid values for solar telemetry.
 */
final class ReportController
{
    private ?PDO $pdo;

    public function __construct(
        private readonly HouseholdService $households = new HouseholdService(),
        private readonly BillRepository $bills = new BillRepository(),
        private readonly SolarReadingRepository $solarReadings = new SolarReadingRepository(),
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo;
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
     */
    public function exportBills(Request $request): CsvResponse
    {
        $householdId = $this->requireHouseholdId($request);

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
            'solar_generation_kwh',
            'net_units',
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

        $nowDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $filename = "spms_bills_history_{$nowDate}.csv";

        return CsvResponse::create((string) $csv, $filename);
    }

    /**
     * GET /api/v1/reports/solar/export
     *
     * Exports GoodWe inverter solar telemetry in RFC 4180 CSV format.
     */
    public function exportSolar(Request $request): CsvResponse
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        $stmt = $pdo->prepare(<<<'SQL'
            SELECT * FROM solar_readings
            WHERE household_id = :household_id
            ORDER BY measurement_time ASC, id ASC
        SQL);
        $stmt->execute(['household_id' => $householdId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Generate CSV output stream
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, [
            'measurement_time',
            'power',
            'ac_power',
            'ac_voltage',
            'ac_current',
            'frequency',
            'power_factor',
            'pv_power',
            'pv_voltage',
            'pv_current',
            'temperature',
            'daily_generation_kwh',
            'monthly_generation_kwh',
            'total_generation_kwh',
            'inverter_status',
            'provider_timestamp',
            'data_nature',
            'data_status',
        ], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['measurement_time'] ?? '',
                $row['power_w'] ?? '',
                $row['power_w'] ?? '',
                $row['ac_voltage_v'] ?? '',
                $row['ac_current_a'] ?? '',
                $row['ac_frequency_hz'] ?? '',
                '', // power_factor: never fabricate
                $row['pv_power_w'] ?? '',
                '', // pv_voltage: never fabricate
                '', // pv_current: never fabricate
                $row['temperature_c'] ?? '',
                $row['daily_generation_kwh'] ?? '',
                '', // monthly_generation_kwh: never fabricate
                $row['total_generation_kwh'] ?? '',
                $row['inverter_status'] ?? '',
                $row['received_at'] ?? $row['measurement_time'] ?? '',
                $row['data_nature'] ?? 'ACTUAL',
                $row['data_status'] ?? 'FRESH',
            ], ',', '"', '\\');
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        $nowDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $filename = "spms_solar_telemetry_{$nowDate}.csv";

        return CsvResponse::create((string) $csv, $filename);
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
