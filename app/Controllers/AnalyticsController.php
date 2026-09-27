<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Household\HouseholdService;
use App\Domain\Bill\BillStatus;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * SPMS F10 — Analytics Workspace Controller.
 *
 * Provides authenticated, household-scoped historical analytics based
 * strictly on existing verified/actual data:
 * - Solar generation from solar_readings (daily_generation_kwh, ACTUAL)
 * - Billed consumption from VERIFIED+EXTRACTED bills (consumption_kwh, VERIFIED)
 * - Monthly aggregated trend comparison
 *
 * Data integrity contract (never fabricated):
 * - Grid import/export is NEVER derived or synthesised.
 * - Solar values are only included for months with actual reading coverage.
 * - Bill consumption values are only VERIFIED or EXTRACTED records.
 * - Each data point carries an explicit data_nature field.
 * - Months with insufficient data return an UNAVAILABLE marker.
 *
 * Endpoints:
 *   GET /api/v1/analytics/monthly    Monthly trend aggregation
 *   GET /api/v1/analytics/summary    Data availability summary
 */
final class AnalyticsController
{
    public function __construct(
        private readonly HouseholdService $households = new HouseholdService(),
        private ?PDO $pdo = null
    ) {
    }

    /**
     * GET /api/v1/analytics/monthly
     *
     * Returns monthly aggregated solar generation + verified billed consumption
     * for the household's active data range, with strict data-nature labels.
     *
     * Optional query params:
     *   from_month=YYYY-MM  — first month inclusive (default: 12 months ago)
     *   to_month=YYYY-MM    — last month inclusive  (default: current month)
     */
    public function monthly(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        [$fromDate, $toDate] = $this->parseDateRange($request);

        // --- 1. Monthly solar generation from actual inverter readings ---
        // We aggregate per calendar month using MAX(daily_generation_kwh)
        // per station per day — the daily_generation_kwh resets each day so
        // the last reading of each day represents that day's total. We then
        // sum daily totals per month across all station/days.
        //
        // data_nature = ACTUAL for any month where we have readings.
        // reading_count lets the frontend decide how to signal sparse data.
        $solarStmt = $pdo->prepare(<<<'SQL'
            SELECT
                DATE_FORMAT(measurement_time, '%Y-%m') AS month,
                ROUND(SUM(daily_max), 3)              AS solar_kwh,
                COUNT(DISTINCT DATE(measurement_time)) AS reading_days,
                MIN(DATE(measurement_time))            AS first_reading_date,
                MAX(DATE(measurement_time))            AS last_reading_date
            FROM (
                SELECT
                    measurement_time,
                    MAX(daily_generation_kwh) AS daily_max
                FROM solar_readings
                WHERE household_id = :household_id
                  AND measurement_time >= :from_dt
                  AND measurement_time <  :to_dt
                GROUP BY
                    DATE(measurement_time),
                    station_id
            ) AS daily_per_station
            GROUP BY DATE_FORMAT(measurement_time, '%Y-%m')
            ORDER BY month ASC
        SQL);
        $solarStmt->execute([
            'household_id' => $householdId,
            'from_dt'      => $fromDate->format('Y-m-d H:i:s'),
            'to_dt'        => $toDate->format('Y-m-d H:i:s'),
        ]);
        $solarRows = $solarStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Index by month
        $solarByMonth = [];
        foreach ($solarRows as $row) {
            $solarByMonth[(string) $row['month']] = $row;
        }

        // --- 2. Monthly billed consumption from VERIFIED + EXTRACTED bills ---
        // Each bill covers a billing_period (not necessarily aligned to calendar
        // months). We use the billing_period_start month to bucket each bill.
        // Only VERIFIED and EXTRACTED (needs-review) statuses are included —
        // anything in PROCESSING/FAILED/NOT_A_BILL is excluded.
        //
        // data_nature = VERIFIED for VERIFIED status bills,
        //               EXTRACTED for NEEDS_REVIEW status bills.
        //
        // Note: if two bills overlap the same bucket (rare duplicate),
        // we sum them — this matches the bills page behaviour.
        $billStmt = $pdo->prepare(<<<'SQL'
            SELECT
                DATE_FORMAT(billing_period_start, '%Y-%m') AS month,
                SUM(CAST(consumption_kwh AS DECIMAL(10,3)))    AS consumption_kwh,
                SUM(CAST(solar_units_kwh AS DECIMAL(10,3)))    AS solar_units_kwh,
                SUM(CAST(bill_amount AS DECIMAL(10,2)))        AS bill_amount,
                COUNT(*)                                       AS bill_count,
                GROUP_CONCAT(DISTINCT status ORDER BY status)  AS statuses
            FROM bills
            WHERE household_id     = :household_id
              AND status           IN (:verified, :needs_review, :extracted)
              AND billing_period_start IS NOT NULL
              AND billing_period_start >= :from_date
              AND billing_period_start <  :to_date
            GROUP BY DATE_FORMAT(billing_period_start, '%Y-%m')
            ORDER BY month ASC
        SQL);
        $billStmt->execute([
            'household_id' => $householdId,
            'verified'     => BillStatus::VERIFIED,
            'needs_review' => BillStatus::NEEDS_REVIEW,
            'extracted'    => BillStatus::EXTRACTED,
            'from_date'    => $fromDate->format('Y-m-d'),
            'to_date'      => $toDate->format('Y-m-d'),
        ]);
        $billRows = $billStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Index by month
        $billByMonth = [];
        foreach ($billRows as $row) {
            $billByMonth[(string) $row['month']] = $row;
        }

        // --- 3. Merge into unified month series ---
        $allMonths = array_unique(array_merge(
            array_keys($solarByMonth),
            array_keys($billByMonth),
            $this->monthRange($fromDate, $toDate)
        ));
        sort($allMonths);

        $points = [];
        foreach ($allMonths as $month) {
            $solar = $solarByMonth[$month] ?? null;
            $bill  = $billByMonth[$month]  ?? null;

            // Solar data nature
            $solarKwh        = $solar !== null ? (float) $solar['solar_kwh']     : null;
            $solarDays       = $solar !== null ? (int)   $solar['reading_days']  : 0;
            $solarNature     = $solar !== null ? 'ACTUAL' : 'UNAVAILABLE';

            // Bill data nature — VERIFIED if all bills verified, otherwise EXTRACTED
            $billStatuses    = $bill !== null ? explode(',', (string) $bill['statuses']) : [];
            $consumptionKwh  = $bill !== null && $bill['consumption_kwh'] !== null
                ? (float) $bill['consumption_kwh'] : null;
            $solarUnitsKwh   = $bill !== null && $bill['solar_units_kwh'] !== null
                ? (float) $bill['solar_units_kwh'] : null;
            $billAmount      = $bill !== null && $bill['bill_amount'] !== null
                ? (float) $bill['bill_amount'] : null;
            $billCount       = $bill !== null ? (int) $bill['bill_count'] : 0;

            $billNature = 'UNAVAILABLE';
            if ($bill !== null && $consumptionKwh !== null) {
                $billNature = in_array(BillStatus::VERIFIED, $billStatuses, true)
                    ? 'VERIFIED'
                    : 'EXTRACTED';
            }

            // Comparison: only computed when BOTH solar and consumption are present
            // Net = solar_generated - consumed (positive = surplus, negative = deficit)
            $netKwh = null;
            $netNature = 'UNAVAILABLE';
            if ($solarKwh !== null && $consumptionKwh !== null) {
                $netKwh = round($solarKwh - $consumptionKwh, 3);
                // Net inherits the less-certain data nature
                $netNature = ($billNature === 'VERIFIED' && $solarNature === 'ACTUAL')
                    ? 'ACTUAL'
                    : ($billNature !== 'UNAVAILABLE' && $solarNature !== 'UNAVAILABLE'
                        ? 'ESTIMATED'
                        : 'UNAVAILABLE');
            }

            $points[] = [
                'month'            => $month,
                'solar_kwh'        => $solarKwh,
                'solar_nature'     => $solarNature,
                'solar_days'       => $solarDays,
                'consumption_kwh'  => $consumptionKwh,
                'bill_nature'      => $billNature,
                'bill_count'       => $billCount,
                'solar_units_kwh'  => $solarUnitsKwh,
                'bill_amount'      => $billAmount,
                'net_kwh'          => $netKwh,
                'net_nature'       => $netNature,
            ];
        }

        // --- 4. Summary statistics ---
        $solarMonthsWithData    = count(array_filter($points, fn ($p) => $p['solar_kwh'] !== null));
        $billMonthsWithData     = count(array_filter($points, fn ($p) => $p['consumption_kwh'] !== null));
        $overlapMonths          = count(array_filter($points, fn ($p) => $p['net_kwh'] !== null));
        $totalSolar             = array_sum(array_column(
            array_filter($points, fn ($p) => $p['solar_kwh'] !== null),
            'solar_kwh'
        ));
        $totalConsumption       = array_sum(array_column(
            array_filter($points, fn ($p) => $p['consumption_kwh'] !== null),
            'consumption_kwh'
        ));

        return Response::success([
            'range' => [
                'from_month' => $fromDate->format('Y-m'),
                'to_month'   => (clone $toDate)->modify('-1 second')->format('Y-m'),
            ],
            'points'  => $points,
            'summary' => [
                'total_months'           => count($points),
                'solar_months_with_data' => $solarMonthsWithData,
                'bill_months_with_data'  => $billMonthsWithData,
                'overlap_months'         => $overlapMonths,
                'total_solar_kwh'        => $solarMonthsWithData > 0
                    ? round($totalSolar, 3) : null,
                'total_consumption_kwh'  => $billMonthsWithData > 0
                    ? round($totalConsumption, 3) : null,
            ],
            'data_notes' => [
                'solar'       => 'ACTUAL — from GoodWe inverter telemetry (daily_generation_kwh per station per day)',
                'consumption' => 'VERIFIED/EXTRACTED — from APSPDCL bills only; UNAVAILABLE where no verified bill exists',
                'net'         => 'Derived from solar − consumption where both datasets overlap; grid import/export is not measured',
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/summary
     *
     * Quick data availability check (counts and date ranges) for the UI
     * to show an appropriate call-to-action when data is absent.
     */
    public function summary(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        $solarStmt = $pdo->prepare(<<<'SQL'
            SELECT
                COUNT(*)                                         AS reading_count,
                COUNT(DISTINCT DATE(measurement_time))          AS reading_days,
                MIN(DATE_FORMAT(measurement_time, '%Y-%m'))     AS first_month,
                MAX(DATE_FORMAT(measurement_time, '%Y-%m'))     AS last_month
            FROM solar_readings
            WHERE household_id = :household_id
        SQL);
        $solarStmt->execute(['household_id' => $householdId]);
        $solarStats = $solarStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $billStmt = $pdo->prepare(<<<'SQL'
            SELECT
                COUNT(*)                                                    AS bill_count,
                MIN(DATE_FORMAT(billing_period_start, '%Y-%m'))            AS first_month,
                MAX(DATE_FORMAT(billing_period_start, '%Y-%m'))            AS last_month
            FROM bills
            WHERE household_id = :household_id
              AND status       IN (:verified, :needs_review, :extracted)
              AND billing_period_start IS NOT NULL
        SQL);
        $billStmt->execute([
            'household_id' => $householdId,
            'verified'     => BillStatus::VERIFIED,
            'needs_review' => BillStatus::NEEDS_REVIEW,
            'extracted'    => BillStatus::EXTRACTED,
        ]);
        $billStats = $billStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return Response::success([
            'solar' => [
                'reading_count' => (int) ($solarStats['reading_count'] ?? 0),
                'reading_days'  => (int) ($solarStats['reading_days']  ?? 0),
                'first_month'   => $solarStats['first_month']  ?? null,
                'last_month'    => $solarStats['last_month']   ?? null,
            ],
            'bills' => [
                'bill_count'  => (int) ($billStats['bill_count']  ?? 0),
                'first_month' => $billStats['first_month'] ?? null,
                'last_month'  => $billStats['last_month']  ?? null,
            ],
            'has_solar_data' => (int) ($solarStats['reading_count'] ?? 0) > 0,
            'has_bill_data'  => (int) ($billStats['bill_count']     ?? 0) > 0,
        ]);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $tz = new DateTimeZone('UTC');

        $fromParam = $request->queryParam('from_month');
        $toParam   = $request->queryParam('to_month');

        // Default: last 12 calendar months (start of 12 months ago → start of next month)
        $now = new DateTimeImmutable('now', $tz);

        if ($fromParam !== null && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', (string) $fromParam) === 1) {
            $fromDate = new DateTimeImmutable((string) $fromParam . '-01 00:00:00', $tz);
        } else {
            $fromDate = new DateTimeImmutable(
                $now->format('Y-m') . '-01 00:00:00',
                $tz
            );
            $fromDate = $fromDate->modify('-11 months');
        }

        if ($toParam !== null && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', (string) $toParam) === 1) {
            // End is start of the FOLLOWING month (exclusive upper bound)
            $toDate = (new DateTimeImmutable((string) $toParam . '-01 00:00:00', $tz))
                ->modify('+1 month');
        } else {
            // Default: include the current month → upper bound = start of next month
            $toDate = (new DateTimeImmutable($now->format('Y-m') . '-01 00:00:00', $tz))
                ->modify('+1 month');
        }

        // Safety: clamp range to max 48 months to prevent runaway aggregations
        $maxMonths = 48;
        $diffMonths = (int) $fromDate->diff($toDate)->days / 30;
        if ($diffMonths > $maxMonths) {
            $fromDate = (clone $toDate)->modify("-{$maxMonths} months");
        }

        return [$fromDate, $toDate];
    }

    /**
     * Build a complete list of YYYY-MM strings in [from, to).
     *
     * @return list<string>
     */
    private function monthRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $months = [];
        $cursor = $from;

        while ($cursor < $to) {
            $months[] = $cursor->format('Y-m');
            $cursor   = $cursor->modify('+1 month');
        }

        return $months;
    }

    private function requireHouseholdId(Request $request): string
    {
        $account     = Authorization::requireAccount($request);
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException(
                'HOUSEHOLD_NOT_FOUND',
                'An active household membership is required to access analytics.',
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
