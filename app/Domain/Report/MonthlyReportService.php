<?php

declare(strict_types=1);

namespace App\Domain\Report;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\ServiceNumber;
use App\Repositories\BillRepository;
use App\Support\AppException;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * F8 — Monthly Household Energy Report Service.
 *
 * Prepares authoritative, household-scoped monthly report data:
 * - Actual GoodWe solar generation (daily & monthly aggregated kWh)
 * - Estimated household demand (from verified utility statements or baseline)
 * - Potential energy balance (solar vs demand, never claimed as measured grid export)
 * - Authoritative billing breakdown (with strict trust boundary isolation)
 * - Factual plain-English interpretation & data limitations
 *
 * Canonical Timezone: Asia/Kolkata
 */
final class MonthlyReportService
{
    private const TIMEZONE = 'Asia/Kolkata';

    private ?PDO $pdo;

    public function __construct(
        private readonly BillRepository $bills = new BillRepository(),
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo;
    }

    /**
     * Generate complete report payload for a specific month (YYYY-MM).
     *
     * @return array<string, mixed>
     */
    public function generateReport(string $householdId, ?string $requestedMonth = null): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $currentMonth = (new DateTimeImmutable('now', $tz))->format('Y-m');

        // 1. Resolve target month
        $month = $this->resolveMonth($householdId, $requestedMonth, $currentMonth);

        if ($month > $currentMonth) {
            throw new AppException(
                'FUTURE_MONTH_NOT_ALLOWED',
                'Reports cannot be generated for future months.',
                422
            );
        }

        $startDate = new DateTimeImmutable("{$month}-01 00:00:00", $tz);
        $daysInMonth = (int) $startDate->format('t');
        $endDate = $startDate->modify('last day of this month')->setTime(23, 59, 59);

        // 2. Fetch available months list for navigation controls
        $availableMonths = $this->fetchAvailableMonths($householdId, $currentMonth);

        // 3. Solar Generation & Coverage for target month
        $solarData = $this->fetchSolarData($householdId, $startDate, $endDate, $daysInMonth);

        // 4. Billing Data for target month (with trust boundary distinction)
        $billingData = $this->fetchBillingData($householdId, $startDate, $endDate, $daysInMonth);

        // 5. Synthesis: Household Demand & Energy Balance
        $demandKwh = $billingData['estimated_demand_kwh'];
        $solarKwh = $solarData['total_solar_kwh'];

        $potentialBalanceKwh = null;
        $balanceStatus = null;
        if ($solarKwh !== null && $demandKwh !== null) {
            $potentialBalanceKwh = round($solarKwh - $demandKwh, 2);
            if ($potentialBalanceKwh > 0.05) {
                $balanceStatus = 'SURPLUS';
            } elseif ($potentialBalanceKwh < -0.05) {
                $balanceStatus = 'DEFICIT';
            } else {
                $balanceStatus = 'BALANCED';
            }
        }

        // 6. Synthesis: Daily trend series for charting
        $dailyTrends = $this->buildDailyTrends(
            $startDate,
            $daysInMonth,
            $solarData['daily_readings'],
            $billingData['daily_baseline_demand_kwh']
        );

        $solarNature = $solarData['data_nature'] ?? ($solarKwh !== null ? 'ACTUAL' : null);
        $isSimulated = ($solarNature === 'SIMULATED');
        $solarProvenance = $isSimulated ? 'Simulated DEMO' : 'GoodWe SEMS';

        // 7. Plain-English Factual Interpretation & Limitations
        $insight = $this->buildInsight(
            $startDate->format('F Y'),
            $solarKwh,
            $demandKwh,
            $potentialBalanceKwh,
            $balanceStatus,
            $billingData['verified_count'],
            $billingData['awaiting_review_count'],
            $solarData['days_with_data'],
            $daysInMonth,
            $solarNature
        );

        return [
            'period' => [
                'month' => $month,
                'label' => $startDate->format('F Y'),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'days_in_month' => $daysInMonth,
                'previous_month' => $startDate->modify('-1 month')->format('Y-m'),
                'next_month' => $month < $currentMonth ? $startDate->modify('+1 month')->format('Y-m') : null,
                'is_current_month' => $month === $currentMonth,
            ],
            'available_months' => $availableMonths,
            'kpis' => [
                'solar_generation_kwh' => $solarKwh,
                'solar_data_nature' => $solarNature ?? 'ACTUAL',
                'solar_provenance' => $solarProvenance,
                'household_demand_kwh' => $demandKwh,
                'demand_data_nature' => $demandKwh !== null ? 'ESTIMATED' : null,
                'demand_source' => $billingData['demand_source'],
                'demand_provenance' => 'Verified billing history',
                'potential_balance_kwh' => $potentialBalanceKwh,
                'balance_status' => $balanceStatus,
                'balance_data_nature' => $potentialBalanceKwh !== null ? 'POTENTIAL' : null,
                'balance_provenance' => 'Analytical balance',
                'latest_verified_bill' => $billingData['latest_verified_bill'],
                'energy_cost_inr' => $billingData['energy_cost_inr'],
                'cost_status' => $billingData['cost_status'],
                'bills_summary' => [
                    'verified_count' => $billingData['verified_count'],
                    'awaiting_review_count' => $billingData['awaiting_review_count'],
                    'total_count' => $billingData['total_count'],
                ],
                'solar_coverage' => [
                    'readings_count' => $solarData['readings_count'],
                    'days_with_data' => $solarData['days_with_data'],
                    'total_days_in_month' => $daysInMonth,
                    'first_reading' => $solarData['first_reading'],
                    'last_reading' => $solarData['last_reading'],
                    'data_status' => $solarData['data_status'],
                    'parameter_availability' => $solarData['parameter_availability'],
                ],
            ],
            'insight' => $insight,
            'daily_trends' => $dailyTrends,
            'bills' => $billingData['bills'],
        ];
    }

    private function resolveMonth(string $householdId, ?string $requestedMonth, string $currentMonth): string
    {
        if ($requestedMonth !== null && trim($requestedMonth) !== '') {
            $trimmed = trim($requestedMonth);
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $trimmed)) {
                throw new AppException(
                    'INVALID_REPORT_MONTH',
                    'Invalid month format. Expected YYYY-MM.',
                    422
                );
            }
            return $trimmed;
        }

        // Default to latest month with recorded solar or billing data
        $pdo = $this->connection();
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT MAX(month_val) AS latest_month FROM (
                SELECT DATE_FORMAT(measurement_time, '%Y-%m') AS month_val
                FROM solar_readings
                WHERE household_id = :h1
                UNION
                SELECT DATE_FORMAT(billing_period_start, '%Y-%m') AS month_val
                FROM bills
                WHERE household_id = :h2
            ) AS combined_months
            WHERE month_val <= :current_month
        SQL);
        $stmt->execute([
            'h1' => $householdId,
            'h2' => $householdId,
            'current_month' => $currentMonth,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (!empty($row['latest_month']) && is_string($row['latest_month']))
            ? $row['latest_month']
            : $currentMonth;
    }

    /**
     * @return list<array{month: string, label: string}>
     */
    private function fetchAvailableMonths(string $householdId, string $currentMonth): array
    {
        $pdo = $this->connection();
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT DISTINCT month_val FROM (
                SELECT DATE_FORMAT(measurement_time, '%Y-%m') AS month_val
                FROM solar_readings
                WHERE household_id = :h1
                UNION
                SELECT DATE_FORMAT(billing_period_start, '%Y-%m') AS month_val
                FROM bills
                WHERE household_id = :h2
            ) AS combined_months
            WHERE month_val IS NOT NULL AND month_val <= :current_month
            ORDER BY month_val DESC
        SQL);
        $stmt->execute([
            'h1' => $householdId,
            'h2' => $householdId,
            'current_month' => $currentMonth,
        ]);
        $months = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Ensure current month is available even with 0 historical entries
        if (!in_array($currentMonth, $months, true)) {
            array_unshift($months, $currentMonth);
        }

        $result = [];
        $tz = new DateTimeZone(self::TIMEZONE);
        foreach ($months as $m) {
            if (is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d', "{$m}-01", $tz);
                $result[] = [
                    'month' => $m,
                    'label' => $dt !== false ? $dt->format('F Y') : $m,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSolarData(
        string $householdId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        int $daysInMonth
    ): array {
        $pdo = $this->connection();

        // 1. Daily generation maximums for the target month
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT 
                DATE(measurement_time) AS reading_date,
                ROUND(MAX(daily_generation_kwh), 3) AS daily_kwh
            FROM solar_readings
            WHERE household_id = :household_id
              AND measurement_time >= :start_dt
              AND measurement_time <= :end_dt
            GROUP BY DATE(measurement_time)
            ORDER BY reading_date ASC
        SQL);
        $stmt->execute([
            'household_id' => $householdId,
            'start_dt' => $startDate->format('Y-m-d 00:00:00'),
            'end_dt' => $endDate->format('Y-m-d 23:59:59'),
        ]);
        $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $dailyReadings = [];
        $totalSolar = 0.0;
        foreach ($dailyRows as $r) {
            $d = (string) $r['reading_date'];
            $val = (float) $r['daily_kwh'];
            $dailyReadings[$d] = $val;
            $totalSolar += $val;
        }

        $daysWithData = count($dailyReadings);

        // 2. High-level coverage statistics & parameter availability
        $statsStmt = $pdo->prepare(<<<'SQL'
            SELECT 
                COUNT(*) AS total_readings,
                MIN(measurement_time) AS first_reading,
                MAX(measurement_time) AS last_reading,
                MAX(data_nature) AS data_nature,
                COUNT(power_w) AS power_count,
                COUNT(pv_power_w) AS pv_power_count,
                COUNT(ac_voltage_v) AS ac_voltage_count,
                COUNT(ac_current_a) AS ac_current_count,
                COUNT(ac_frequency_hz) AS frequency_count,
                COUNT(temperature_c) AS temperature_count
            FROM solar_readings
            WHERE household_id = :household_id
              AND measurement_time >= :start_dt
              AND measurement_time <= :end_dt
        SQL);
        $statsStmt->execute([
            'household_id' => $householdId,
            'start_dt' => $startDate->format('Y-m-d 00:00:00'),
            'end_dt' => $endDate->format('Y-m-d 23:59:59'),
        ]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $readingsCount = (int) ($stats['total_readings'] ?? 0);
        $dataStatus = 'NO_DATA';
        if ($readingsCount > 0) {
            $dataStatus = $daysWithData >= $daysInMonth ? 'FRESH' : 'PARTIAL';
        }

        return [
            'total_solar_kwh' => $daysWithData > 0 ? round($totalSolar, 2) : null,
            'days_with_data' => $daysWithData,
            'readings_count' => $readingsCount,
            'first_reading' => $stats['first_reading'] ?? null,
            'last_reading' => $stats['last_reading'] ?? null,
            'data_nature' => $stats['data_nature'] ?? ($readingsCount > 0 ? 'ACTUAL' : null),
            'data_status' => $dataStatus,
            'daily_readings' => $dailyReadings,
            'parameter_availability' => [
                'power' => ((int) ($stats['power_count'] ?? 0)) > 0,
                'pv_power' => ((int) ($stats['pv_power_count'] ?? 0)) > 0,
                'ac_voltage' => ((int) ($stats['ac_voltage_count'] ?? 0)) > 0,
                'ac_current' => ((int) ($stats['ac_current_count'] ?? 0)) > 0,
                'frequency' => ((int) ($stats['frequency_count'] ?? 0)) > 0,
                'temperature' => ((int) ($stats['temperature_count'] ?? 0)) > 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchBillingData(
        string $householdId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        int $daysInMonth
    ): array {
        $allBills = $this->bills->listForHousehold($householdId);

        // 1. Filter bills overlapping this calendar month
        $startStr = $startDate->format('Y-m-d');
        $endStr = $endDate->format('Y-m-d');

        $monthBills = array_filter($allBills, static function (Bill $b) use ($startStr, $endStr): bool {
            $pStart = $b->value(BillField::BILLING_PERIOD_START);
            $pEnd = $b->value(BillField::BILLING_PERIOD_END);
            if ($pStart === null || $pEnd === null) {
                return false;
            }
            if (!in_array($b->status(), [BillStatus::VERIFIED, BillStatus::NEEDS_REVIEW, BillStatus::EXTRACTED], true)) {
                return false;
            }
            return $pStart <= $endStr && $pEnd >= $startStr;
        });

        // Sort chronological
        usort($monthBills, static function (Bill $a, Bill $b): int {
            return strcmp(
                $a->value(BillField::BILLING_PERIOD_START) ?? '',
                $b->value(BillField::BILLING_PERIOD_START) ?? ''
            );
        });

        $verifiedBills = array_filter($monthBills, static fn (Bill $b): bool => $b->status() === BillStatus::VERIFIED);
        $verifiedCount = count($verifiedBills);
        $awaitingCount = count($monthBills) - $verifiedCount;

        // Formatted bill rows for UI / table
        $formattedBills = [];
        foreach ($monthBills as $bill) {
            $rawSn = $bill->value(BillField::SERVICE_NUMBER);
            $formattedBills[] = [
                'id' => $bill->id(),
                'billing_period_start' => $bill->value(BillField::BILLING_PERIOD_START),
                'billing_period_end' => $bill->value(BillField::BILLING_PERIOD_END),
                'service_number' => $rawSn !== null ? (ServiceNumber::mask($rawSn) ?? $rawSn) : '',
                'consumption_kwh' => $bill->value('consumption_kwh') !== null ? (float) $bill->value('consumption_kwh') : null,
                'solar_export_units_kwh' => $bill->value('solar_units_kwh') !== null ? (float) $bill->value('solar_units_kwh') : null,
                'net_units_kwh' => $bill->value('net_units_kwh') !== null ? (float) $bill->value('net_units_kwh') : null,
                'bill_amount' => $bill->value('bill_amount') !== null ? (float) $bill->value('bill_amount') : null,
                'energy_charges' => $bill->value('energy_charges') !== null ? (float) $bill->value('energy_charges') : null,
                'fixed_charges' => $bill->value('fixed_charges') !== null ? (float) $bill->value('fixed_charges') : null,
                'other_charges' => $bill->value('other_charges') !== null ? (float) $bill->value('other_charges') : null,
                'status' => $bill->status(),
                'source' => $bill->value('source') ?? 'APSPDCL_BILL',
            ];
        }

        // 2. Derive Estimated Household Demand & Daily Baseline
        // Priority A: Direct VERIFIED bill covering this month
        $estimatedDemandKwh = null;
        $dailyBaselineDemandKwh = null;
        $demandSource = 'UNAVAILABLE';
        $energyCostInr = null;
        $costStatus = 'UNAVAILABLE';

        if ($verifiedCount > 0) {
            // Sum verified consumption for bills in this month
            $vSum = 0.0;
            $cSum = 0.0;
            $hasCost = false;

            foreach ($verifiedBills as $vb) {
                $c = $vb->value('consumption_kwh');
                if ($c !== null && (float) $c > 0) {
                    $vSum += (float) $c;
                }
                $amt = $vb->value('bill_amount');
                if ($amt !== null && is_numeric($amt)) {
                    $cSum += (float) $amt;
                    $hasCost = true;
                }
            }

            if ($vSum > 0) {
                $estimatedDemandKwh = round($vSum, 2);
                $dailyBaselineDemandKwh = round($vSum / max(1, $daysInMonth), 2);
                $demandSource = 'VERIFIED_BILL_STATEMENT';
            }

            if ($hasCost) {
                $energyCostInr = round($cSum, 2);
                $costStatus = 'VERIFIED_STATEMENT_TOTAL';
            }
        }

        // Priority B: Historical verified bills baseline if current month has no verified bill
        if ($estimatedDemandKwh === null) {
            $allVerified = array_filter(
                $allBills,
                static fn (Bill $b): bool => $b->status() === BillStatus::VERIFIED
            );

            if (count($allVerified) > 0) {
                $totalUnits = 0.0;
                $totalDays = 0;
                $totalEnergyCharges = 0.0;
                $totalChargeUnits = 0.0;

                foreach ($allVerified as $vb) {
                    $c = $vb->value('consumption_kwh');
                    $pS = $vb->value(BillField::BILLING_PERIOD_START);
                    $pE = $vb->value(BillField::BILLING_PERIOD_END);
                    if ($c !== null && (float) $c > 0 && $pS !== null && $pE !== null) {
                        $dS = DateTimeImmutable::createFromFormat('Y-m-d', $pS);
                        $dE = DateTimeImmutable::createFromFormat('Y-m-d', $pE);
                        if ($dS !== false && $dE !== false) {
                            $diff = max(1, $dS->diff($dE)->days);
                            $totalUnits += (float) $c;
                            $totalDays += $diff;

                            $ec = $vb->value('energy_charges');
                            if ($ec !== null && (float) $ec > 0) {
                                $totalEnergyCharges += (float) $ec;
                                $totalChargeUnits += (float) $c;
                            }
                        }
                    }
                }

                if ($totalDays > 0 && $totalUnits > 0) {
                    $dailyRate = $totalUnits / $totalDays;
                    $estimatedDemandKwh = round($dailyRate * $daysInMonth, 2);
                    $dailyBaselineDemandKwh = round($dailyRate, 2);
                    $demandSource = 'HISTORICAL_VERIFIED_BASELINE';

                    if ($totalChargeUnits > 0 && $totalEnergyCharges > 0) {
                        $unitRate = $totalEnergyCharges / $totalChargeUnits;
                        $energyCostInr = round($estimatedDemandKwh * $unitRate, 2);
                        $costStatus = 'ESTIMATED_FROM_CANONICAL_TARIFF';
                    }
                }
            }
        }

        $latestVerifiedBill = null;
        if ($verifiedCount > 0) {
            $sortedVerified = array_values($verifiedBills);
            usort($sortedVerified, static fn (Bill $a, Bill $b): int => strcmp(
                $b->value(BillField::BILLING_PERIOD_END) ?? '',
                $a->value(BillField::BILLING_PERIOD_END) ?? ''
            ));
            $topVb = $sortedVerified[0];
            $pStart = $topVb->value(BillField::BILLING_PERIOD_START);
            $pEnd = $topVb->value(BillField::BILLING_PERIOD_END);
            $amtVal = $topVb->value('bill_amount');

            $dtStart = $pStart ? DateTimeImmutable::createFromFormat('Y-m-d', $pStart, new DateTimeZone(self::TIMEZONE)) : false;
            $dtEnd = $pEnd ? DateTimeImmutable::createFromFormat('Y-m-d', $pEnd, new DateTimeZone(self::TIMEZONE)) : false;

            $periodLabel = ($dtStart !== false && $dtEnd !== false)
                ? $dtStart->format('d M') . ' → ' . $dtEnd->format('d M')
                : ($pStart . ' → ' . $pEnd);

            $latestVerifiedBill = [
                'amount' => $amtVal !== null && is_numeric($amtVal) ? (float) $amtVal : null,
                'billing_period_start' => $pStart,
                'billing_period_end' => $pEnd,
                'billing_period_label' => $periodLabel,
                'matches_calendar_month' => ($pStart === $startStr && $pEnd === $endStr),
                'status' => 'VERIFIED',
                'provenance' => 'Verified utility statement',
            ];
        }

        return [
            'bills' => $formattedBills,
            'verified_count' => $verifiedCount,
            'awaiting_review_count' => $awaitingCount,
            'total_count' => count($monthBills),
            'estimated_demand_kwh' => $estimatedDemandKwh,
            'daily_baseline_demand_kwh' => $dailyBaselineDemandKwh,
            'demand_source' => $demandSource,
            'energy_cost_inr' => $energyCostInr,
            'cost_status' => $costStatus,
            'latest_verified_bill' => $latestVerifiedBill,
        ];
    }

    /**
     * @param array<string, float> $dailySolar
     * @return list<array<string, mixed>>
     */
    private function buildDailyTrends(
        DateTimeImmutable $startDate,
        int $daysInMonth,
        array $dailySolar,
        ?float $dailyBaselineDemand
    ): array {
        $trends = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dayStr = sprintf('%02d', $day);
            $dateStr = $startDate->format('Y-m-') . $dayStr;

            $solarKwh = $dailySolar[$dateStr] ?? null;
            $demandKwh = $dailyBaselineDemand;

            $balanceKwh = null;
            if ($solarKwh !== null && $demandKwh !== null) {
                $balanceKwh = round($solarKwh - $demandKwh, 2);
            }

            $trends[] = [
                'date' => $dateStr,
                'day' => $day,
                'solar_generation_kwh' => $solarKwh,
                'estimated_demand_kwh' => $demandKwh,
                'potential_balance_kwh' => $balanceKwh,
            ];
        }

        return $trends;
    }

    /**
     * @return array{headline: string, narrative: string, summary: string, provenance_line: string, solar_statement: string, demand_statement: string, limitations: list<string>}
     */
    private function buildInsight(
        string $monthLabel,
        ?float $solarKwh,
        ?float $demandKwh,
        ?float $balanceKwh,
        ?string $balanceStatus,
        int $verifiedCount,
        int $awaitingCount,
        int $daysWithData,
        int $daysInMonth,
        ?string $solarDataNature = 'ACTUAL'
    ): array {
        $limitations = [];
        $summary = '';
        $isSimulated = ($solarDataNature === 'SIMULATED');

        if ($solarKwh === null && $demandKwh === null) {
            $summary = "No verified billing history or solar telemetry records were found for {$monthLabel}.";
            $limitations[] = 'Connect your GoodWe inverter or upload utility statements to generate report analysis.';
            return [
                'headline' => "No Data Recorded for {$monthLabel}",
                'narrative' => $summary,
                'summary' => $summary,
                'provenance_line' => 'UNAVAILABLE solar • UNAVAILABLE demand • UNAVAILABLE balance',
                'solar_statement' => $isSimulated
                    ? 'No simulated solar generation telemetry recorded for this reporting period.'
                    : 'No GoodWe inverter solar generation telemetry recorded for this reporting period.',
                'demand_statement' => 'Household demand estimate is unavailable because no verified electricity billing history exists.',
                'limitations' => $limitations,
            ];
        }

        if ($solarKwh !== null && $demandKwh !== null && $balanceKwh !== null) {
            if ($balanceStatus === 'SURPLUS') {
                $summary = sprintf(
                    'Solar generation (%.2f kWh) exceeded estimated household demand (%.2f kWh) during %s, yielding a potential energy surplus of approximately %.2f kWh.',
                    $solarKwh,
                    $demandKwh,
                    $monthLabel,
                    $balanceKwh
                );
            } elseif ($balanceStatus === 'DEFICIT') {
                $summary = sprintf(
                    'Household demand (%.2f kWh) exceeded solar generation (%.2f kWh) during %s, resulting in a potential net energy deficit of approximately %.2f kWh.',
                    $demandKwh,
                    $solarKwh,
                    $monthLabel,
                    abs($balanceKwh)
                );
            } else {
                $summary = sprintf(
                    'Solar generation (%.2f kWh) closely matched estimated household demand (%.2f kWh) during %s.',
                    $solarKwh,
                    $demandKwh,
                    $monthLabel
                );
            }
        } elseif ($solarKwh !== null) {
            $summary = sprintf(
                'Recorded %.2f kWh of %s solar generation across %d day(s) during %s. Estimated household demand is unavailable because no verified billing history has been established.',
                $solarKwh,
                $isSimulated ? 'simulated' : 'actual',
                $daysWithData,
                $monthLabel
            );
        } else {
            $summary = sprintf(
                'Estimated household demand is %.2f kWh for %s. Solar generation data is unavailable for this period.',
                $demandKwh,
                $monthLabel
            );
        }

        $headline = match ($balanceStatus) {
            'SURPLUS' => "Net Energy Surplus for {$monthLabel}",
            'DEFICIT' => "Net Energy Deficit for {$monthLabel}",
            'BALANCED' => "Balanced Energy Performance for {$monthLabel}",
            default => $solarKwh !== null
                ? "Solar Generation Recorded for {$monthLabel}"
                : ($demandKwh !== null ? "Household Demand Recorded for {$monthLabel}" : "No Data Recorded for {$monthLabel}"),
        };

        $solarStatement = $solarKwh !== null
            ? ($isSimulated
                ? sprintf('Simulated %.2f kWh of demo solar generation across %d recorded day(s).', $solarKwh, $daysWithData)
                : sprintf('Measured %.2f kWh of actual GoodWe inverter solar generation across %d recorded day(s).', $solarKwh, $daysWithData))
            : ($isSimulated
                ? 'No simulated solar generation telemetry recorded for this reporting period.'
                : 'No GoodWe inverter solar generation telemetry recorded for this reporting period.');

        $demandStatement = $demandKwh !== null
            ? sprintf('Estimated household demand of %.2f kWh derived from verified utility billing records.', $demandKwh)
            : 'Household demand estimate is unavailable because no verified electricity billing history exists.';

        // Factual limitations disclosure
        if ($balanceKwh !== null) {
            $limitations[] = 'Potential energy balance is an analytical comparison of generation vs estimated demand, not a measured grid export.';
        }
        if ($demandKwh !== null) {
            $limitations[] = 'Household demand is estimated from verified utility billing history; smart meter telemetry is not connected.';
        }
        if ($isSimulated && $solarKwh !== null) {
            $limitations[] = 'Solar generation figures represent simulated demo scenario telemetry for demonstration and testing purposes.';
        }
        if ($awaitingCount > 0) {
            $limitations[] = sprintf(
                '%d electricity statement(s) are currently awaiting user review and have been excluded from authoritative demand calculations.',
                $awaitingCount
            );
        }
        if ($daysWithData > 0 && $daysWithData < $daysInMonth) {
            $limitations[] = sprintf(
                'Solar telemetry was recorded for %d of %d days during %s.',
                $daysWithData,
                $daysInMonth,
                $monthLabel
            );
        }

        $solarProvBadge = $isSimulated ? 'SIMULATED solar' : 'ACTUAL solar';

        return [
            'headline' => $headline,
            'narrative' => $summary,
            'summary' => $summary,
            'provenance_line' => "{$solarProvBadge} • ESTIMATED demand • POTENTIAL balance",
            'solar_statement' => $solarStatement,
            'demand_statement' => $demandStatement,
            'limitations' => $limitations,
        ];
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
