<?php

declare(strict_types=1);

namespace App\Domain\Forecast;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Repositories\BillRepository;
use App\Repositories\SolarReadingRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * F3 — Household Electricity Consumption Forecast Model (docs/01 §14, docs/02 §2, docs/04 §16).
 *
 * Deterministic model predicting future household electricity consumption from:
 * - Verified electricity bills (`bills` table via `BillRepository`)
 * - In-flight interval smart meter telemetry (`solar_readings.consumption_power_w` if present)
 *
 * Hard Invariants:
 * - AI must NOT perform numerical calculations.
 * - If sufficient verified history does not exist, produce an honest
 *   low-confidence/insufficient-data state and explain what additional history is needed.
 * - Never invent historical values or fabricate precision.
 */
final class ConsumptionForecastModel
{
    public const MODEL_VERSION = 'SPMS_CONSUMPTION_V1_DETERMINISTIC';

    /** Default fallback daily consumption when 0 bills exist (only used for heuristic preview if explicitly permitted). */
    private const BASELINE_DAILY_KWH = 12.0;

    public function __construct(
        private readonly BillRepository $bills,
        private readonly SolarReadingRepository $readings
    ) {
    }

    /**
     * Generate electricity consumption forecast for a given household, horizon, and reference point.
     */
    public function generate(
        string $householdId,
        string $horizon,
        DateTimeImmutable $referenceTime
    ): ForecastSnapshot {
        $window = ForecastHorizon::computeTimeWindow($horizon, $referenceTime);
        $start = $window['start'];
        $end = $window['end'];
        $resolution = $window['resolution'];

        $limitations = [];
        $sources = [];

        // 1. Gather verified bills for household
        $allBills = $this->bills->listForHousehold($householdId);
        $verifiedBills = array_filter(
            $allBills,
            static fn (Bill $b): bool => $b->status() === BillStatus::VERIFIED
        );

        $verifiedBillCount = count($verifiedBills);

        // 2. Gather verified consumption amounts and daily averages
        $dailyUsages = [];
        foreach ($verifiedBills as $bill) {
            $consumption = $bill->value(BillField::ENERGY[0]); // 'consumption_kwh'
            $periodStart = $bill->value(BillField::BILLING_PERIOD_START);
            $periodEnd = $bill->value(BillField::BILLING_PERIOD_END);

            if ($consumption !== null && (float) $consumption > 0) {
                $days = 30; // default month
                if ($periodStart !== null && $periodEnd !== null) {
                    $dStart = DateTimeImmutable::createFromFormat('Y-m-d', $periodStart);
                    $dEnd = DateTimeImmutable::createFromFormat('Y-m-d', $periodEnd);
                    if ($dStart !== false && $dEnd !== false) {
                        $diff = $dStart->diff($dEnd)->days;
                        if ($diff > 0) {
                            $days = $diff;
                        }
                    }
                }
                $dailyUsages[] = (float) $consumption / $days;
            }
        }

        // 3. Determine Adaptive State & Confidence
        if ($dailyUsages === []) {
            // ZERO verified bills: produce an honest INSUFFICIENT state
            $limitations[] = 'Insufficient historical consumption data: at least 1 verified electricity bill or smart meter reading is required to generate a household consumption forecast.';
            $limitations[] = 'Upload and verify your recent electricity bills on the Bills page to establish a verified household consumption baseline.';

            return new ForecastSnapshot(
                forecastType: 'CONSUMPTION',
                horizon: $horizon,
                period: [
                    'start' => $start->format('Y-m-d\TH:i:s\Z'),
                    'end' => $end->format('Y-m-d\TH:i:s\Z'),
                    'resolution' => $resolution,
                ],
                predictedValue: null,
                uncertaintyRange: ['lower' => null, 'upper' => null],
                confidence: ForecastConfidence::INSUFFICIENT,
                dataNature: ForecastSnapshot::NATURE_ESTIMATED,
                dataQuality: ForecastSnapshot::QUALITY_UNVERIFIED,
                provenance: [
                    'forecast_source' => 'DETERMINISTIC',
                    'model_name' => 'CONSUMPTION_DETERMINISTIC_BASELINE',
                    'model_version' => self::MODEL_VERSION,
                    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                    'sources' => ['insufficient_evidence'],
                    'evidence_count' => 0,
                    'verified_bills_count' => 0,
                ],
                limitations: $limitations,
                timeSeriesPoints: []
            );
        }

        // We have verified bill data!
        $sources[] = 'verified_electricity_bills';

        // Calculate weighted daily average consumption (more recent bills carry higher weight)
        $weightSum = 0.0;
        $weightedDailyKwh = 0.0;
        $idx = 0;
        foreach ($dailyUsages as $usage) {
            $weight = 1.0 / (1.0 + $idx * 0.2); // 1.0, 0.83, 0.71, etc.
            $weightedDailyKwh += $usage * $weight;
            $weightSum += $weight;
            $idx++;
        }
        $avgDailyKwh = round($weightedDailyKwh / $weightSum, 3);

        // Adaptive confidence & uncertainty based on bill count
        [$confidence, $uncertaintyPct, $dataQuality] = $this->determineAdaptiveConfidence($verifiedBillCount);

        $dataNature = ForecastSnapshot::NATURE_PREDICTED;
        try {
            $db = \App\Support\Database::connection();
            $stmt = $db->prepare('SELECT a.mode FROM accounts a JOIN household_memberships hm ON hm.account_id = a.id WHERE hm.household_id = :hid LIMIT 1');
            $stmt->execute(['hid' => $householdId]);
            if ($stmt->fetchColumn() === 'DEMO_TEST') {
                $dataNature = ForecastSnapshot::NATURE_SIMULATED;
            }
        } catch (\Throwable) {
            // Non-fatal fallback
        }

        if ($verifiedBillCount === 1) {
            $limitations[] = 'Single verified bill history: seasonal variations and intra-month fluctuations cannot be modeled until multiple billing cycles are verified.';
        } elseif ($verifiedBillCount < 4) {
            $limitations[] = sprintf('Limited bill history (%d verified bills): forecast confidence is moderate and will strengthen with further billing cycles.', $verifiedBillCount);
        }

        // Check if verified billing history contains gaps / incomplete history
        $hasIncompleteHistory = false;
        foreach ($verifiedBills as $vb) {
            $vbFlags = $this->bills->findQualityFlags($vb->id(), $householdId);
            foreach ($vbFlags as $vf) {
                if ($vf->code() === \App\Domain\Bill\QualityCode::INCOMPLETE_HISTORY) {
                    $hasIncompleteHistory = true;
                    break 2;
                }
            }
        }

        if ($hasIncompleteHistory) {
            $limitations[] = 'Historical consumption contains billing sequence gaps; forecast confidence is constrained and uncertainty intervals are widened.';
            if ($confidence === ForecastConfidence::HIGH) {
                $confidence = ForecastConfidence::MODERATE;
            }
            $uncertaintyPct = min(50.0, $uncertaintyPct * 1.3);
        }

        // 4. Generate time-series decomposition
        $points = [];
        $totalPredictedKwh = 0.0;
        $current = $start;

        if ($resolution === ForecastHorizon::RESOLUTION_HOURLY) {
            $tzObj = new DateTimeZone('Asia/Kolkata');
            while ($current < $end) {
                $localCurrent = $current->setTimezone($tzObj);
                $localHour = (int) $localCurrent->format('H');
                $timestampKey = $current->format('Y-m-d\TH:i:s\Z');

                // Standard residential diurnal load profile:
                // Peak morning 07-09, dip mid-day, peak evening 18-22, base night 23-06
                $hourlyLoadFactor = $this->residentialDiurnalLoadFactor($localHour);
                $predictedKwh = round($avgDailyKwh * $hourlyLoadFactor, 3);
                $predictedPowerW = round($predictedKwh * 1000.0, 1);

                $lowerKwh = max(0.0, round($predictedKwh * (1.0 - $uncertaintyPct / 100.0), 3));
                $upperKwh = round($predictedKwh * (1.0 + $uncertaintyPct / 100.0), 3);

                $lowerW = max(0.0, round($predictedPowerW * (1.0 - $uncertaintyPct / 100.0), 1));
                $upperW = round($predictedPowerW * (1.0 + $uncertaintyPct / 100.0), 1);

                $points[] = [
                    'timestamp' => $timestampKey,
                    'hour' => $localHour,
                    'local_hour' => $localHour,
                    'local_time' => $localCurrent->format('Y-m-d H:i:s'),
                    'predicted_w' => $predictedPowerW,
                    'lower_w' => $lowerW,
                    'upper_w' => $upperW,
                    'predicted_kwh' => $predictedKwh,
                    'lower_kwh' => $lowerKwh,
                    'upper_kwh' => $upperKwh,
                    'data_nature' => $dataNature,
                    'confidence' => $confidence,
                ];

                $totalPredictedKwh += $predictedKwh;
                $current = $current->modify('+1 hour');
            }
        } else {
            // Daily resolution for 7-day or monthly
            while ($current < $end) {
                $dateKey = $current->format('Y-m-d');
                $timestampKey = $current->format('Y-m-d\T00:00:00\Z');

                $predictedKwh = $avgDailyKwh;
                $lowerKwh = max(0.0, round($predictedKwh * (1.0 - $uncertaintyPct / 100.0), 3));
                $upperKwh = round($predictedKwh * (1.0 + $uncertaintyPct / 100.0), 3);

                $points[] = [
                    'timestamp' => $timestampKey,
                    'date' => $dateKey,
                    'predicted_kwh' => $predictedKwh,
                    'lower_kwh' => $lowerKwh,
                    'upper_kwh' => $upperKwh,
                    'data_nature' => $dataNature,
                    'confidence' => $confidence,
                ];

                $totalPredictedKwh += $predictedKwh;
                $current = $current->modify('+1 day');
            }
        }

        $totalPredictedKwh = round($totalPredictedKwh, 3);
        $totalLowerKwh = max(0.0, round($totalPredictedKwh * (1.0 - $uncertaintyPct / 100.0), 3));
        $totalUpperKwh = round($totalPredictedKwh * (1.0 + $uncertaintyPct / 100.0), 3);

        return new ForecastSnapshot(
            forecastType: 'CONSUMPTION',
            horizon: $horizon,
            period: [
                'start' => $start->format('Y-m-d\TH:i:s\Z'),
                'end' => $end->format('Y-m-d\TH:i:s\Z'),
                'resolution' => $resolution,
            ],
            predictedValue: $totalPredictedKwh,
            uncertaintyRange: [
                'lower' => $totalLowerKwh,
                'upper' => $totalUpperKwh,
            ],
            confidence: $confidence,
            dataNature: $dataNature,
            dataQuality: $dataQuality,
            provenance: [
                'forecast_source' => 'DETERMINISTIC',
                'model_name' => 'CONSUMPTION_DETERMINISTIC_BASELINE',
                'model_version' => self::MODEL_VERSION,
                'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'sources' => $sources,
                'evidence_count' => $verifiedBillCount,
                'verified_bills_count' => $verifiedBillCount,
                'average_daily_kwh' => $avgDailyKwh,
            ],
            limitations: $limitations,
            timeSeriesPoints: $points
        );
    }

    /**
     * Standard residential diurnal load factor (sums to approximately 1.0 across 24 hours).
     */
    private function residentialDiurnalLoadFactor(int $hour): float
    {
        // 24 hour load curve factors (fraction of daily total)
        // Morning wake up ~06-09, daytime moderate ~10-17, evening peak ~18-22, night drop ~23-05
        $factors = [
            0 => 0.025, 1 => 0.022, 2 => 0.020, 3 => 0.020, 4 => 0.022, 5 => 0.028,
            6 => 0.038, 7 => 0.055, 8 => 0.062, 9 => 0.050, 10 => 0.040, 11 => 0.038,
            12 => 0.040, 13 => 0.038, 14 => 0.036, 15 => 0.038, 16 => 0.042, 17 => 0.052,
            18 => 0.068, 19 => 0.075, 20 => 0.070, 21 => 0.058, 22 => 0.045, 23 => 0.036,
        ];

        return $factors[$hour] ?? (1.0 / 24.0);
    }

    /**
     * @return array{0: string, 1: float, 2: string} [confidence, uncertaintyPct, dataQuality]
     */
    private function determineAdaptiveConfidence(int $billCount): array
    {
        if ($billCount === 1) {
            return [ForecastConfidence::LOW, 35.0, ForecastSnapshot::QUALITY_PARTIAL];
        }

        if ($billCount < 4) {
            return [ForecastConfidence::MODERATE, 22.0, ForecastSnapshot::QUALITY_GOOD];
        }

        // 4+ verified bills across multiple months/seasons
        return [ForecastConfidence::HIGH, 14.0, ForecastSnapshot::QUALITY_GOOD];
    }
}
