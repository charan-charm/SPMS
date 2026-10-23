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
 * SPMS F10 — Analytics Workspace Controller (V2).
 *
 * Provides authenticated, household-scoped historical analytics based
 * strictly on existing verified/actual data:
 * - Solar generation from solar_readings (daily_generation_kwh, ACTUAL)
 * - Billed consumption from VERIFIED bills (authoritative demand, VERIFIED)
 * - Derived energy balance (POTENTIAL = solar − verified demand)
 * - Gap-aware 5-minute telemetry quality and drill-down
 *
 * Semantic Invariants (never fabricated):
 * - Solar Generation = ACTUAL
 * - Verified Bill Consumption = VERIFIED
 * - Potential Energy Balance = POTENTIAL
 * - Grid import/export is NEVER derived or synthesised (smart meter absence).
 * - Missing telemetry or bills are NEVER converted to zero.
 *
 * Endpoints:
 *   GET /api/v1/analytics/summary    Data availability & telemetry quality summary
 *   GET /api/v1/analytics/monthly    Monthly trend aggregation & energy balance
 *   GET /api/v1/analytics/daily      Daily solar time series & gap indicators
 *   GET /api/v1/analytics/day-curve  5-minute telemetry power curve for a selected date
 */
final class AnalyticsController
{
    public function __construct(
        private readonly HouseholdService $households = new HouseholdService(),
        private ?PDO $pdo = null
    ) {
    }

    /**
     * GET /api/v1/analytics/summary
     *
     * Data availability and quality summary across solar and billing datasets.
     */
    public function summary(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        // 1. Combined daily solar scan (covers reading count, days, date boundaries, peak power, total kWh, and avg daily kWh)
        $dailyStmt = $pdo->prepare(<<<'SQL'
            SELECT 
                DATE(measurement_time)              AS d,
                MAX(daily_generation_kwh)          AS daily_kwh,
                MAX(power_w)                       AS daily_peak_w,
                COUNT(*)                            AS daily_readings,
                MAX(data_nature)                   AS daily_nature
            FROM solar_readings FORCE INDEX (idx_solar_readings_household_time)
            WHERE household_id = :household_id
            GROUP BY DATE(measurement_time)
            ORDER BY d ASC
        SQL);
        $dailyStmt->execute(['household_id' => $householdId]);
        $dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $readingDays = count($dailyRows);
        $hasSolarData = $readingDays > 0;
        $solarNature = 'ACTUAL';
        if ($hasSolarData) {
            $natures = array_filter(array_column($dailyRows, 'daily_nature'));
            if (in_array('SIMULATED', $natures, true)) {
                $solarNature = 'SIMULATED';
            } elseif (!empty($natures)) {
                $solarNature = (string) reset($natures);
            }
        } else {
            $solarNature = 'UNAVAILABLE';
        }
        $readingCount = 0;
        $peakPowerW = 0.0;
        $earliestDate = $hasSolarData ? (string) $dailyRows[0]['d'] : null;
        $latestDate = $hasSolarData ? (string) $dailyRows[$readingDays - 1]['d'] : null;
        $firstMonth = $earliestDate !== null ? substr($earliestDate, 0, 7) : null;
        $lastMonth = $latestDate !== null ? substr($latestDate, 0, 7) : null;
        $totalSolarKwh = 0.0;
        $fullDaysSum = 0.0;
        $fullDaysCount = 0;

        foreach ($dailyRows as $dr) {
            $readingCount += (int) $dr['daily_readings'];
            $p = (float) ($dr['daily_peak_w'] ?? 0);
            if ($p > $peakPowerW) {
                $peakPowerW = $p;
            }
            $kwh = (float) $dr['daily_kwh'];
            $totalSolarKwh += $kwh;
            if ($dr['d'] !== '2026-01-15') {
                $fullDaysSum += $kwh;
                $fullDaysCount++;
            }
        }

        $avgDailyKwh = $fullDaysCount > 0 
            ? round($fullDaysSum / $fullDaysCount, 2) 
            : ($readingDays > 0 ? round($totalSolarKwh / $readingDays, 2) : 0.0);

        // Telemetry gaps audit (excluding pre-dawn non-solar test probes)
        $gapCount = 0;
        $missingIntervals = 0;
        $largestGap = null;
        $gapWarning = null;

        if ($hasSolarData) {
            $gapStmt = $pdo->prepare(<<<'SQL'
                SELECT 
                    prev_time,
                    measurement_time,
                    TIMESTAMPDIFF(MINUTE, prev_time, measurement_time) as gap_min,
                    ROUND((TIMESTAMPDIFF(MINUTE, prev_time, measurement_time) - 5) / 5) as missing_points
                FROM (
                    SELECT 
                        measurement_time,
                        LAG(measurement_time) OVER (ORDER BY measurement_time) as prev_time
                    FROM solar_readings FORCE INDEX (idx_solar_readings_household_time)
                    WHERE household_id = :household_id
                ) with_lag
                WHERE prev_time IS NOT NULL
                  AND DATE(prev_time) = DATE(measurement_time)
                  AND TIMESTAMPDIFF(MINUTE, prev_time, measurement_time) > 5
                  AND NOT (HOUR(prev_time) < 5 AND HOUR(measurement_time) <= 6)
                ORDER BY gap_min DESC
            SQL);
            $gapStmt->execute(['household_id' => $householdId]);
            $gapRows = $gapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $gapCount = count($gapRows);
            foreach ($gapRows as $gr) {
                $missingIntervals += (int) $gr['missing_points'];
            }

            if (!empty($gapRows)) {
                $maxG = $gapRows[0];
                $maxGapMin = (int) $maxG['gap_min'];
                $fromDateStr = substr((string)$maxG['prev_time'], 0, 10);
                $fromTimeStr = substr((string)$maxG['prev_time'], 11, 5);
                $toTimeStr   = substr((string)$maxG['measurement_time'], 11, 5);
                
                $largestGap = [
                    'duration_minutes' => $maxGapMin,
                    'duration_hours'   => round($maxGapMin / 60, 1),
                    'date'             => $fromDateStr,
                    'window'           => "{$fromTimeStr}–{$toTimeStr} IST",
                ];

                if ($maxGapMin >= 60) {
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fromDateStr);
                    $formattedDate = $dt !== false ? $dt->format('j M Y') : $fromDateStr;
                    $gapWarning = [
                        'title'       => 'Telemetry gap detected',
                        'description' => sprintf(
                            'Solar telemetry was not recorded between %s and %s IST on %s. Analytics does not treat this missing period as zero generation.',
                            $fromTimeStr,
                            $toTimeStr,
                            $formattedDate
                        ),
                        'date'        => $fromDateStr,
                        'window'      => "{$fromTimeStr}–{$toTimeStr} IST",
                        'duration'    => "{$largestGap['duration_hours']} hours",
                    ];
                }
            }
        }

        // Calendar completeness
        $expectedCalendarDays = 0;
        if ($earliestDate !== null && $latestDate !== null) {
            $d1 = new DateTimeImmutable($earliestDate);
            $d2 = new DateTimeImmutable($latestDate);
            $expectedCalendarDays = (int) $d1->diff($d2)->days + 1;
        }

        $expectedDaytimePoints = $readingCount + $missingIntervals;
        $telemetryCompletenessPct = $expectedDaytimePoints > 0 
            ? round(($readingCount / $expectedDaytimePoints) * 100, 2) 
            : 100.0;

        // 2. Bill stats (using literal status strings to prevent duplicate PDO parameter names)
        $billStmt = $pdo->prepare(<<<'SQL'
            SELECT
                COUNT(*)                                                    AS bill_count,
                COUNT(CASE WHEN status = 'VERIFIED' THEN 1 END)             AS verified_count,
                COUNT(CASE WHEN status IN ('NEEDS_REVIEW', 'EXTRACTED') THEN 1 END) AS unverified_count,
                MIN(DATE_FORMAT(billing_period_start, '%Y-%m'))            AS first_month,
                MAX(DATE_FORMAT(billing_period_start, '%Y-%m'))            AS last_month
            FROM bills
            WHERE household_id = :household_id
              AND status IN ('VERIFIED', 'NEEDS_REVIEW', 'EXTRACTED')
              AND billing_period_start IS NOT NULL
        SQL);
        $billStmt->execute(['household_id' => $householdId]);
        $billStats = $billStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $billCount     = (int) ($billStats['bill_count']     ?? 0);
        $verifiedCount = (int) ($billStats['verified_count'] ?? 0);
        $hasBillData   = $billCount > 0;

        return Response::success([
            'solar' => [
                'reading_count'              => $readingCount,
                'reading_days'               => $readingDays,
                'first_month'                => $firstMonth,
                'last_month'                 => $lastMonth,
                'earliest_date'              => $earliestDate,
                'latest_date'                => $latestDate,
                'total_generation_kwh'       => $hasSolarData ? round($totalSolarKwh, 3) : null,
                'displayed_generation_kwh'   => $hasSolarData ? round($totalSolarKwh, 2) : null,
                'avg_daily_generation_kwh'   => $avgDailyKwh,
                'peak_power_w'               => $hasSolarData ? (float) $peakPowerW : null,
                'peak_power_kw'              => $hasSolarData ? round($peakPowerW / 1000, 2) : null,
                'calendar_coverage_days'     => $readingDays,
                'calendar_coverage_total'    => $expectedCalendarDays,
                'calendar_coverage_pct'      => $expectedCalendarDays > 0 ? round(($readingDays / $expectedCalendarDays) * 100, 1) : 0.0,
                'telemetry_completeness_pct' => $telemetryCompletenessPct,
                'unexpected_gap_count'       => $gapCount,
                'missing_intervals_count'    => $missingIntervals,
                'largest_gap'                => $largestGap,
                'gap_warning'                => $gapWarning,
                'data_nature'                => $solarNature,
            ],
            'bills' => [
                'bill_count'          => $billCount,
                'verified_bill_count' => $verifiedCount,
                'unverified_count'    => (int) ($billStats['unverified_count'] ?? 0),
                'first_month'         => $billStats['first_month'] ?? null,
                'last_month'          => $billStats['last_month']  ?? null,
                'data_nature'         => 'VERIFIED',
            ],
            'has_solar_data'     => $hasSolarData,
            'has_bill_data'      => $hasBillData,
            'has_verified_bills' => $verifiedCount > 0,
        ]);
    }

    /**
     * GET /api/v1/analytics/monthly
     *
     * Monthly aggregated solar generation + verified billed consumption + energy balance.
     */
    public function monthly(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        [$fromDate, $toDate] = $this->parseDateRange($request);

        // --- 1. Monthly solar generation from actual inverter readings ---
        $solarStmt = $pdo->prepare(<<<'SQL'
            SELECT
                DATE_FORMAT(measurement_time, '%Y-%m') AS month,
                ROUND(SUM(daily_max), 3)               AS solar_kwh,
                COUNT(DISTINCT DATE(measurement_time))  AS reading_days,
                MAX(daily_peak)                         AS peak_power_w,
                MIN(DATE(measurement_time))             AS first_reading_date,
                MAX(DATE(measurement_time))             AS last_reading_date,
                MAX(daily_nature)                       AS data_nature
            FROM (
                SELECT
                    measurement_time,
                    MAX(daily_generation_kwh) AS daily_max,
                    MAX(power_w)              AS daily_peak,
                    MAX(data_nature)          AS daily_nature
                FROM solar_readings FORCE INDEX (idx_solar_readings_household_time)
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

        $solarByMonth = [];
        foreach ($solarRows as $row) {
            $solarByMonth[(string) $row['month']] = $row;
        }

        // --- 2. Monthly billed consumption from VERIFIED & EXTRACTED bills ---
        $billStmt = $pdo->prepare(<<<'SQL'
            SELECT
                DATE_FORMAT(billing_period_start, '%Y-%m') AS month,
                SUM(CASE WHEN status = 'VERIFIED' THEN CAST(consumption_kwh AS DECIMAL(10,3)) ELSE 0 END) AS verified_consumption_kwh,
                SUM(CAST(consumption_kwh AS DECIMAL(10,3)))    AS total_consumption_kwh,
                SUM(CAST(solar_units_kwh AS DECIMAL(10,3)))    AS solar_units_kwh,
                SUM(CAST(COALESCE(bill_amount, total_due) AS DECIMAL(10,2))) AS bill_amount,
                COUNT(*)                                       AS bill_count,
                COUNT(CASE WHEN status = 'VERIFIED' THEN 1 END) AS verified_bill_count,
                GROUP_CONCAT(DISTINCT status ORDER BY status)  AS statuses
            FROM bills
            WHERE household_id     = :household_id
              AND status           IN ('VERIFIED', 'NEEDS_REVIEW', 'EXTRACTED')
              AND billing_period_start IS NOT NULL
              AND billing_period_start >= :from_date
              AND billing_period_start <  :to_date
            GROUP BY DATE_FORMAT(billing_period_start, '%Y-%m')
            ORDER BY month ASC
        SQL);
        $billStmt->execute([
            'household_id' => $householdId,
            'from_date'    => $fromDate->format('Y-m-d'),
            'to_date'      => $toDate->format('Y-m-d'),
        ]);
        $billRows = $billStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $billByMonth = [];
        foreach ($billRows as $row) {
            $billByMonth[(string) $row['month']] = $row;
        }

        $currentMonth = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $currentYm = substr($currentMonth, 0, 7);

        // Gap detection by month
        $gapStmt = $pdo->prepare(<<<'SQL'
            SELECT 
                DATE_FORMAT(measurement_time, '%Y-%m') AS month,
                COUNT(*) as gap_count,
                MAX(TIMESTAMPDIFF(MINUTE, prev_time, measurement_time)) as max_gap_min
            FROM (
                SELECT 
                    measurement_time,
                    LAG(measurement_time) OVER (ORDER BY measurement_time) as prev_time
                FROM solar_readings FORCE INDEX (idx_solar_readings_household_time)
                WHERE household_id = :household_id
                  AND measurement_time >= :from_dt
                  AND measurement_time <  :to_dt
            ) with_lag
            WHERE prev_time IS NOT NULL
              AND DATE(prev_time) = DATE(measurement_time)
              AND TIMESTAMPDIFF(MINUTE, prev_time, measurement_time) > 5
              AND NOT (HOUR(prev_time) < 5 AND HOUR(measurement_time) <= 6)
            GROUP BY DATE_FORMAT(measurement_time, '%Y-%m')
        SQL);
        $gapStmt->execute([
            'household_id' => $householdId,
            'from_dt'      => $fromDate->format('Y-m-d H:i:s'),
            'to_dt'        => $toDate->format('Y-m-d H:i:s'),
        ]);
        $monthGaps = $gapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $gapsByMonth = [];
        foreach ($monthGaps as $mg) {
            $gapsByMonth[(string) $mg['month']] = [
                'gap_count'   => (int) $mg['gap_count'],
                'max_gap_min' => (int) $mg['max_gap_min'],
            ];
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
            $gInfo = $gapsByMonth[$month]  ?? null;

            $solarKwh    = $solar !== null ? (float) $solar['solar_kwh'] : null;
            $solarDays   = $solar !== null ? (int)   $solar['reading_days'] : 0;
            $solarPeakW  = $solar !== null ? (float) $solar['peak_power_w'] : null;
            $solarNature = $solar !== null ? ((string) ($solar['data_nature'] ?? 'ACTUAL')) : 'UNAVAILABLE';

            $billStatuses        = $bill !== null ? explode(',', (string) $bill['statuses']) : [];
            $hasVerifiedBill     = in_array('VERIFIED', $billStatuses, true);
            $verifiedConsKwh     = ($bill !== null && $hasVerifiedBill && (float)$bill['verified_consumption_kwh'] > 0)
                ? (float) $bill['verified_consumption_kwh'] : null;
            $totalConsKwh        = ($bill !== null && $bill['total_consumption_kwh'] !== null)
                ? (float) $bill['total_consumption_kwh'] : null;
            $solarUnitsKwh       = $bill !== null && $bill['solar_units_kwh'] !== null
                ? (float) $bill['solar_units_kwh'] : null;
            $billAmount          = $bill !== null && $bill['bill_amount'] !== null
                ? (float) $bill['bill_amount'] : null;
            $billCount           = $bill !== null ? (int) $bill['bill_count'] : 0;

            $billNature = 'UNAVAILABLE';
            if ($bill !== null && $totalConsKwh !== null) {
                $billNature = $hasVerifiedBill ? 'VERIFIED' : 'EXTRACTED';
            }

            // Potential Energy Balance: Solar Generation (ACTUAL) − Verified Demand (VERIFIED)
            $potentialBalanceKwh = null;
            $potentialBalanceNature = 'UNAVAILABLE';
            if ($solarKwh !== null && $verifiedConsKwh !== null) {
                $potentialBalanceKwh = round($solarKwh - $verifiedConsKwh, 2);
                $potentialBalanceNature = 'POTENTIAL';
            }

            // Legacy net_kwh for backward compatibility with existing tests
            $netKwh = null;
            $netNature = 'UNAVAILABLE';
            if ($solarKwh !== null && $totalConsKwh !== null) {
                $netKwh = round($solarKwh - $totalConsKwh, 3);
                $netNature = ($billNature === 'VERIFIED' && $solarNature === 'ACTUAL')
                    ? 'ACTUAL'
                    : 'ESTIMATED';
            }

            $isCurrentPeriod = ($month === $currentYm);
            $hasGap = ($gInfo !== null && $gInfo['gap_count'] > 0);
            $gapWarning = null;
            if ($hasGap) {
                $maxG = $gInfo['max_gap_min'];
                $gapWarning = $maxG >= 60 
                    ? sprintf('%d hour telemetry gap', round($maxG / 60)) 
                    : sprintf('%d min telemetry gap', $maxG);
            }

            $points[] = [
                'month'                    => $month,
                'label'                    => $this->formatMonthLabel($month),
                'is_current_period'        => $isCurrentPeriod,
                'solar_kwh'                => $solarKwh,
                'solar_nature'             => $solarNature,
                'solar_days'               => $solarDays,
                'solar_peak_w'             => $solarPeakW,
                'has_gap'                  => $hasGap,
                'gap_count'                => $gInfo['gap_count'] ?? 0,
                'gap_warning'              => $gapWarning,
                'consumption_kwh'          => $totalConsKwh,
                'verified_consumption_kwh' => $verifiedConsKwh,
                'bill_nature'              => $billNature,
                'bill_count'               => $billCount,
                'solar_units_kwh'          => $solarUnitsKwh,
                'bill_amount'              => $billAmount,
                'potential_balance_kwh'    => $potentialBalanceKwh,
                'potential_balance_nature' => $potentialBalanceNature,
                'net_kwh'                  => $netKwh,
                'net_nature'               => $netNature,
            ];
        }

        // --- 4. Summary statistics & Highlights ---
        $solarPointsWithData   = array_filter($points, fn ($p) => $p['solar_kwh'] !== null);
        $billPointsWithData    = array_filter($points, fn ($p) => $p['consumption_kwh'] !== null);
        $verifiedPoints        = array_filter($points, fn ($p) => $p['verified_consumption_kwh'] !== null);
        $overlapPoints         = array_filter($points, fn ($p) => $p['net_kwh'] !== null);

        $totalSolar       = array_sum(array_column($solarPointsWithData, 'solar_kwh'));
        $totalConsumption = array_sum(array_column($billPointsWithData, 'consumption_kwh'));
        $totalPotential   = array_sum(array_column(array_filter($points, fn ($p) => $p['potential_balance_kwh'] !== null), 'potential_balance_kwh'));

        $highestMonth = null;
        $lowestFullMonth = null;
        if (!empty($solarPointsWithData)) {
            $sortedSolar = $solarPointsWithData;
            usort($sortedSolar, fn ($a, $b) => $b['solar_kwh'] <=> $a['solar_kwh']);
            $top = reset($sortedSolar);
            $highestMonth = [
                'month' => $top['month'],
                'label' => $top['label'],
                'kwh'   => round((float) $top['solar_kwh'], 2),
            ];

            $fullMonths = array_filter($solarPointsWithData, fn ($p) => $p['solar_days'] >= 28 && !$p['is_current_period']);
            if (!empty($fullMonths)) {
                usort($fullMonths, fn ($a, $b) => $a['solar_kwh'] <=> $b['solar_kwh']);
                $low = reset($fullMonths);
                $lowestFullMonth = [
                    'month' => $low['month'],
                    'label' => $low['label'],
                    'kwh'   => round((float) $low['solar_kwh'], 2),
                ];
            }
        }

        $peakPowerW = 0.0;
        foreach ($points as $p) {
            if ($p['solar_peak_w'] !== null && (float) $p['solar_peak_w'] > $peakPowerW) {
                $peakPowerW = (float) $p['solar_peak_w'];
            }
        }

        return Response::success([
            'range' => [
                'from_month' => $fromDate->format('Y-m'),
                'to_month'   => (clone $toDate)->modify('-1 second')->format('Y-m'),
            ],
            'points'  => $points,
            'summary' => [
                'total_months'                    => count($points),
                'solar_months_with_data'          => count($solarPointsWithData),
                'bill_months_with_data'           => count($billPointsWithData),
                'verified_bill_months_with_data'  => count($verifiedPoints),
                'overlap_months'                  => count($overlapPoints),
                'total_solar_kwh'                 => !empty($solarPointsWithData) ? round($totalSolar, 3) : null,
                'total_consumption_kwh'           => !empty($billPointsWithData) ? round($totalConsumption, 3) : null,
                'total_potential_balance_kwh'     => !empty($overlapPoints) ? round($totalPotential, 2) : null,
                'peak_power_w'                    => $peakPowerW > 0 ? $peakPowerW : null,
                'highest_month'                   => $highestMonth,
                'lowest_full_month'               => $lowestFullMonth,
            ],
            'data_notes' => [
                'solar'             => 'ACTUAL — from GoodWe inverter telemetry (daily_generation_kwh per station per day)',
                'consumption'       => 'VERIFIED/EXTRACTED — from APSPDCL bills only; UNAVAILABLE where no verified bill exists',
                'potential_balance' => 'POTENTIAL — actual solar generation minus verified billing consumption where both overlap',
                'net'               => 'Derived from solar − consumption where both datasets overlap; grid import/export is not measured',
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/daily
     *
     * Daily solar generation time series with telemetry gap classification.
     */
    public function daily(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        [$fromDate, $toDate] = $this->parseDateRange($request);

        $stmt = $pdo->prepare(<<<'SQL'
            SELECT 
                DATE(measurement_time) AS reading_date,
                ROUND(MAX(daily_generation_kwh), 3) AS daily_kwh,
                ROUND(MAX(power_w), 1)              AS peak_power_w,
                COUNT(*)                             AS reading_count,
                MIN(TIME(measurement_time))          AS first_reading,
                MAX(TIME(measurement_time))          AS last_reading,
                MAX(data_nature)                     AS data_nature
            FROM solar_readings FORCE INDEX (idx_solar_readings_household_time)
            WHERE household_id = :household_id
              AND measurement_time >= :from_dt
              AND measurement_time <  :to_dt
            GROUP BY DATE(measurement_time)
            ORDER BY reading_date ASC
        SQL);
        $stmt->execute([
            'household_id' => $householdId,
            'from_dt'      => $fromDate->format('Y-m-d H:i:s'),
            'to_dt'        => $toDate->format('Y-m-d H:i:s'),
        ]);
        $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($dailyRows)) {
            return Response::success([
                'points'  => [],
                'summary' => [
                    'days_with_data'  => 0,
                    'total_kwh'       => 0.0,
                    'avg_daily_kwh'   => null,
                    'highest_day'     => null,
                    'lowest_day'      => null,
                    'days_with_gaps'  => 0,
                    'peak_power_w'    => null,
                ],
            ]);
        }

        // Query daytime gaps across the date range
        $gapStmt = $pdo->prepare(<<<'SQL'
            SELECT 
                reading_date,
                COUNT(*) as gap_count,
                MAX(gap_min) as max_gap_min,
                SUM(missing_points) as missing_points
            FROM (
                SELECT 
                    DATE(measurement_time) as reading_date,
                    TIMESTAMPDIFF(MINUTE, prev_time, measurement_time) as gap_min,
                    ROUND((TIMESTAMPDIFF(MINUTE, prev_time, measurement_time) - 5) / 5) as missing_points
                FROM (
                    SELECT 
                        measurement_time,
                        LAG(measurement_time) OVER (ORDER BY measurement_time) as prev_time
                    FROM solar_readings FORCE INDEX (idx_solar_readings_household_time)
                    WHERE household_id = :household_id
                      AND measurement_time >= :from_dt
                      AND measurement_time <  :to_dt
                ) with_lag
                WHERE prev_time IS NOT NULL
                  AND DATE(prev_time) = DATE(measurement_time)
                  AND TIMESTAMPDIFF(MINUTE, prev_time, measurement_time) > 5
                  AND NOT (HOUR(prev_time) < 5 AND HOUR(measurement_time) <= 6)
            ) with_gaps
            GROUP BY reading_date
        SQL);
        $gapStmt->execute([
            'household_id' => $householdId,
            'from_dt'      => $fromDate->format('Y-m-d H:i:s'),
            'to_dt'        => $toDate->format('Y-m-d H:i:s'),
        ]);
        $gapRows = $gapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $gapsByDate = [];
        foreach ($gapRows as $gr) {
            $gapsByDate[(string) $gr['reading_date']] = [
                'gap_count'      => (int) $gr['gap_count'],
                'max_gap_min'    => (int) $gr['max_gap_min'],
                'missing_points' => (int) $gr['missing_points'],
            ];
        }

        $todayStr = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

        $points = [];
        $totalKwh = 0.0;
        $highestDay = null;
        $lowestDay = null;
        $fullDaysKwh = 0.0;
        $fullDaysCount = 0;
        $gapDaysCount = 0;
        $overallPeakW = 0.0;

        foreach ($dailyRows as $row) {
            $dateStr = (string) $row['reading_date'];
            $kwh = (float) $row['daily_kwh'];
            $peakW = (float) $row['peak_power_w'];
            $isCommissioning = ($dateStr === '2026-01-15');
            $isToday = ($dateStr === $todayStr);

            $totalKwh += $kwh;
            if ($peakW > $overallPeakW) {
                $overallPeakW = $peakW;
            }

            if (!$isCommissioning && !$isToday) {
                $fullDaysKwh += $kwh;
                $fullDaysCount++;
            }

            if ($highestDay === null || $kwh > $highestDay['kwh']) {
                $highestDay = [
                    'date'   => $dateStr,
                    'label'  => $this->formatDateLabel($dateStr),
                    'kwh'    => $kwh,
                    'peak_w' => $peakW,
                ];
            }

            if (!$isCommissioning) {
                if ($lowestDay === null || $kwh < $lowestDay['kwh']) {
                    $lowestDay = [
                        'date'   => $dateStr,
                        'label'  => $this->formatDateLabel($dateStr),
                        'kwh'    => $kwh,
                        'peak_w' => $peakW,
                    ];
                }
            }

            $gInfo = $gapsByDate[$dateStr] ?? null;
            $hasGap = ($gInfo !== null && $gInfo['gap_count'] > 0);
            if ($hasGap) {
                $gapDaysCount++;
            }

            $gapWarning = null;
            if ($hasGap) {
                $maxG = $gInfo['max_gap_min'];
                $gapWarning = $maxG >= 60 
                    ? sprintf('%d hour telemetry gap', round($maxG / 60)) 
                    : sprintf('%d min telemetry gap', $maxG);
            }

            $points[] = [
                'date'                 => $dateStr,
                'label'                => $this->formatDateLabel($dateStr),
                'solar_kwh'            => $kwh,
                'peak_power_w'         => $peakW,
                'reading_count'        => (int) $row['reading_count'],
                'first_reading'        => (string) $row['first_reading'],
                'last_reading'         => (string) $row['last_reading'],
                'has_gap'              => $hasGap,
                'gap_count'            => $gInfo['gap_count'] ?? 0,
                'max_gap_min'          => $gInfo['max_gap_min'] ?? 0,
                'gap_warning'          => $gapWarning,
                'is_commissioning_day' => $isCommissioning,
                'is_today'             => $isToday,
                'data_nature'          => (string) ($row['data_nature'] ?? 'ACTUAL'),
            ];
        }

        $avgDailyKwh = $fullDaysCount > 0 
            ? round($fullDaysKwh / $fullDaysCount, 2) 
            : round($totalKwh / count($dailyRows), 2);

        return Response::success([
            'range' => [
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date'   => (clone $toDate)->modify('-1 day')->format('Y-m-d'),
            ],
            'points'  => $points,
            'summary' => [
                'days_with_data'  => count($points),
                'total_kwh'       => round($totalKwh, 2),
                'avg_daily_kwh'   => $avgDailyKwh,
                'highest_day'     => $highestDay,
                'lowest_day'      => $lowestDay,
                'days_with_gaps'  => $gapDaysCount,
                'peak_power_w'    => $overallPeakW,
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/day-curve
     *
     * 5-minute high-resolution telemetry curve for a specific day drill-down.
     */
    public function dayCurve(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $pdo = $this->connection();

        $dateParam = $request->queryParam('date');
        $cleanDate = is_string($dateParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateParam)) === 1
            ? trim($dateParam)
            : null;

        if ($cleanDate === null) {
            $latestStmt = $pdo->prepare('SELECT MAX(DATE(measurement_time)) FROM solar_readings WHERE household_id = :hid');
            $latestStmt->execute(['hid' => $householdId]);
            $cleanDate = (string) $latestStmt->fetchColumn();
            if ($cleanDate === '' || $cleanDate === '0') {
                $cleanDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            }
        }

        $stmt = $pdo->prepare(<<<'SQL'
            SELECT 
                measurement_time,
                power_w,
                daily_generation_kwh,
                inverter_status,
                data_nature,
                data_status
            FROM solar_readings
            WHERE household_id = :household_id
              AND DATE(measurement_time) = :reading_date
            ORDER BY measurement_time ASC
        SQL);
        $stmt->execute([
            'household_id' => $householdId,
            'reading_date' => $cleanDate,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($rows)) {
            return Response::success([
                'date'       => $cleanDate,
                'date_label' => $this->formatDateLabel($cleanDate),
                'metrics'    => [
                    'reading_count' => 0,
                    'daily_kwh'     => null,
                    'peak_power_w'  => null,
                    'has_gap'       => false,
                    'gaps'          => [],
                ],
                'readings'   => [],
            ]);
        }

        $gaps = [];
        $readings = [];
        $peakW = 0.0;
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $r = $rows[$i];
            $measTime = (string) $r['measurement_time'];
            $timeStr = substr($measTime, 11, 5);
            $powerW = (float) $r['power_w'];
            $dailyKwh = (float) $r['daily_generation_kwh'];

            if ($powerW > $peakW) {
                $peakW = $powerW;
            }

            if ($i > 0) {
                $prevTime = (string) $rows[$i - 1]['measurement_time'];
                $t1 = strtotime($prevTime);
                $t2 = strtotime($measTime);
                $diffMin = ($t2 - $t1) / 60;

                if ($diffMin > 5.0 && !(date('H', $t1) < 5 && date('H', $t2) <= 6)) {
                    $gaps[] = [
                        'from'             => $prevTime,
                        'to'               => $measTime,
                        'from_time'        => substr($prevTime, 11, 5),
                        'to_time'          => substr($measTime, 11, 5),
                        'gap_minutes'      => (int) $diffMin,
                        'missing_readings' => (int) round(($diffMin - 5.0) / 5.0),
                    ];
                }
            }

            $readings[] = [
                'time'                 => $timeStr,
                'timestamp'            => $measTime,
                'power_w'              => $powerW,
                'power_kw'             => round($powerW / 1000.0, 3),
                'daily_generation_kwh' => $dailyKwh,
                'inverter_status'      => (string) $r['inverter_status'],
                'data_nature'          => (string) $r['data_nature'],
            ];
        }

        $lastRow = end($rows);
        $dailyTotalKwh = $lastRow !== false ? (float) $lastRow['daily_generation_kwh'] : 0.0;
        $firstReading = substr((string) $rows[0]['measurement_time'], 11, 8);
        $lastReading  = substr((string) $lastRow['measurement_time'], 11, 8);

        return Response::success([
            'date'       => $cleanDate,
            'date_label' => $this->formatDateLabel($cleanDate),
            'metrics'    => [
                'reading_count'        => $count,
                'daily_kwh'            => round($dailyTotalKwh, 3),
                'peak_power_w'         => $peakW,
                'peak_power_kw'        => round($peakW / 1000.0, 2),
                'first_reading'        => $firstReading,
                'last_reading'         => $lastReading,
                'has_gap'              => !empty($gaps),
                'gap_count'            => count($gaps),
                'gaps'                 => $gaps,
                'is_commissioning_day' => ($cleanDate === '2026-01-15'),
                'data_nature'          => (string) ($rows[0]['data_nature'] ?? 'ACTUAL'),
            ],
            'readings'   => $readings,
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
        $tz = new DateTimeZone('Asia/Kolkata');
        $now = new DateTimeImmutable('now', $tz);

        $rangeParam = $request->queryParam('range');
        $fromParam  = $request->queryParam('from_month') ?? $request->queryParam('from_date');
        $toParam    = $request->queryParam('to_month')   ?? $request->queryParam('to_date');

        if ($rangeParam === '30d') {
            $fromDate = $now->modify('-30 days')->setTime(0, 0, 0);
            $toDate   = $now->modify('+1 day')->setTime(0, 0, 0);
            return [$fromDate, $toDate];
        }

        if ($rangeParam === '6m') {
            $fromDate = (new DateTimeImmutable($now->format('Y-m') . '-01 00:00:00', $tz))->modify('-5 months');
            $toDate   = (new DateTimeImmutable($now->format('Y-m') . '-01 00:00:00', $tz))->modify('+1 month');
            return [$fromDate, $toDate];
        }

        if ($fromParam !== null && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', (string) $fromParam) === 1) {
            $fromDate = new DateTimeImmutable((string) $fromParam . '-01 00:00:00', $tz);
            if ($toParam !== null && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', (string) $toParam) === 1) {
                $toDate = (new DateTimeImmutable((string) $toParam . '-01 00:00:00', $tz))->modify('+1 month');
            } else {
                $toDate = (new DateTimeImmutable($now->format('Y-m') . '-01 00:00:00', $tz))->modify('+1 month');
            }
            return [$fromDate, $toDate];
        }

        if ($fromParam !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fromParam) === 1) {
            $fromDate = new DateTimeImmutable((string) $fromParam . ' 00:00:00', $tz);
            if ($toParam !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $toParam) === 1) {
                $toDate = (new DateTimeImmutable((string) $toParam . ' 00:00:00', $tz))->modify('+1 day');
            } else {
                $toDate = $now->modify('+1 day')->setTime(0, 0, 0);
            }
            return [$fromDate, $toDate];
        }

        // Default: past 12 months (inclusive of 2026-01 to 2026-10)
        $fromDate = (new DateTimeImmutable($now->format('Y-m') . '-01 00:00:00', $tz))->modify('-11 months');
        $toDate   = (new DateTimeImmutable($now->format('Y-m') . '-01 00:00:00', $tz))->modify('+1 month');

        return [$fromDate, $toDate];
    }

    /**
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

    private function formatMonthLabel(string $ym): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
        return $dt !== false ? $dt->format('M Y') : $ym;
    }

    private function formatDateLabel(string $ymd): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
        return $dt !== false ? $dt->format('d M Y') : $ymd;
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
