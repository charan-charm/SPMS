<?php

declare(strict_types=1);

namespace App\Domain\Forecast\ML;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use DateTimeImmutable;
use DateTimeZone;

/**
 * F3 ML — Forecast Feature Builder (docs/01 §14, docs/02 §2, docs/10 D-040).
 *
 * Extracts and normalizes feature sets for solar generation and household consumption models.
 *
 * Strict Invariants:
 *  - Chronological ordering: time-series observations are processed strictly in ascending chronological order.
 *  - No future leakage: features for observation at index i only depend on information at or before i.
 *  - No fabricated observations: only authentic verified bills and recorded solar telemetry are included.
 *  - Physical bounds: nighttime solar observations (hour < 6 or hour > 18) are separated from daylight regression.
 */
final class ForecastFeatureBuilder
{
    public const SOLAR_FEATURE_VERSION = 'SOLAR_FEATURES_V1';
    public const CONSUMPTION_FEATURE_VERSION = 'CONSUMPTION_FEATURES_V1';

    /**
     * Build chronological training/evaluation dataset from solar readings.
     *
     * @param list<array<string, mixed>> $readings Raw solar_readings rows
     * @param float $peakCapacityW Authoritative peak capacity
     * @param array<string, array<string, mixed>> $weatherFactorsByHour Optional hourly weather map
     * @return list<array{
     *     timestamp: string,
     *     hour: int,
     *     features: list<float>,
     *     target: float,
     *     measurement_time: string
     * }>
     */
    public function buildSolarDataset(
        array $readings,
        float $peakCapacityW,
        array $weatherFactorsByHour = []
    ): array {
        if ($readings === []) {
            return [];
        }

        // 1. Sort readings chronologically ASC to prevent time-series reversal
        $sorted = $readings;
        usort($sorted, static function (array $a, array $b): int {
            return strcmp((string) ($a['measurement_time'] ?? ''), (string) ($b['measurement_time'] ?? ''));
        });

        $dataset = [];
        $rollingPowerHistory = [];
        $capacityNorm = max(100.0, $peakCapacityW) / 5000.0;

        foreach ($sorted as $reading) {
            $measurementTimeStr = (string) ($reading['measurement_time'] ?? '');
            if ($measurementTimeStr === '') {
                continue;
            }

            try {
                $dt = new DateTimeImmutable($measurementTimeStr);
            } catch (\Throwable) {
                continue;
            }

            $hour = (int) $dt->format('H');
            $dayOfWeek = (int) $dt->format('w'); // 0-6
            $dayOfYear = (int) $dt->format('z'); // 0-365

            // Filter to daylight hours only (06:00 to 18:00) for regression fitting
            if ($hour < 6 || $hour > 18) {
                continue;
            }

            $powerW = isset($reading['power_w']) ? (float) $reading['power_w'] : 0.0;
            // Physical clamp on observed target: generation cannot be negative or exceed physical capacity
            $targetW = max(0.0, min($peakCapacityW * 1.05, $powerW));

            // Basis functions for diurnal solar cycle (peak at hour 12, zero at 6 and 18)
            $daylightFraction = ($hour - 6) / 12.0;
            $diurnalSin = round(sin($daylightFraction * M_PI), 4);
            $diurnalCos = round(cos($daylightFraction * M_PI), 4);

            // Weather variables (cloud cover, solar irradiance, temperature)
            $isoKey = $dt->format('Y-m-d\TH:00:00\Z');
            $weather = $weatherFactorsByHour[$isoKey] ?? null;

            $cloudFactor = 1.0;
            $irradianceNorm = $diurnalSin; // default to clear-sky profile if no weather
            $tempNorm = 0.0;

            if ($weather !== null) {
                if (isset($weather['clear_sky_index'])) {
                    $cloudFactor = max(0.1, min(1.0, (float) $weather['clear_sky_index']));
                } elseif (isset($weather['cloud_cover_pct'])) {
                    $cloudFactor = max(0.1, min(1.0, 1.0 - ((float) $weather['cloud_cover_pct'] / 100.0)));
                }

                if (isset($weather['direct_normal_irradiance_wm2'])) {
                    $irradianceNorm = max(0.0, min(1.2, (float) $weather['direct_normal_irradiance_wm2'] / 1000.0));
                } elseif (isset($weather['shortwave_radiation_wm2'])) {
                    $irradianceNorm = max(0.0, min(1.2, (float) $weather['shortwave_radiation_wm2'] / 1000.0));
                }

                if (isset($weather['temperature_c'])) {
                    $tempNorm = ((float) $weather['temperature_c'] - 25.0) / 25.0; // centered at 25C
                }
            } elseif (isset($reading['temperature_c']) && $reading['temperature_c'] !== null) {
                $tempNorm = ((float) $reading['temperature_c'] - 25.0) / 25.0;
            }

            // Rolling history strictly from past observations (prevent leakage)
            $pastPowerMean = 0.0;
            if ($rollingPowerHistory !== []) {
                $pastPowerMean = array_sum($rollingPowerHistory) / count($rollingPowerHistory);
            }
            $lagNorm = $pastPowerMean / max(100.0, $peakCapacityW);

            // Feature vector: [1.0 (bias), diurnalSin, diurnalCos, cloudFactor, irradianceNorm, tempNorm, capacityNorm, lagNorm, dayOfWeekNorm]
            $features = [
                1.0,
                $diurnalSin,
                $diurnalCos,
                $cloudFactor,
                $irradianceNorm,
                $tempNorm,
                $capacityNorm,
                $lagNorm,
                $dayOfWeek / 6.0,
            ];

            $dataset[] = [
                'timestamp' => $isoKey,
                'hour' => $hour,
                'features' => $features,
                'target' => $targetW,
                'measurement_time' => $measurementTimeStr,
            ];

            // Update rolling history with current observation after feature extraction
            $rollingPowerHistory[] = $targetW;
            if (count($rollingPowerHistory) > 48) {
                array_shift($rollingPowerHistory);
            }
        }

        return $dataset;
    }

    /**
     * Build chronological training/evaluation dataset from verified electricity bills.
     *
     * @param list<Bill> $bills Verified bills for household
     * @return list<array{
     *     period_start: string,
     *     period_end: string,
     *     days: int,
     *     features: list<float>,
     *     target: float
     * }>
     */
    public function buildConsumptionDataset(array $bills): array
    {
        // 1. Filter strictly to authoritative VERIFIED bills with positive consumption and valid dates
        $verified = [];
        foreach ($bills as $bill) {
            if ($bill->status() !== BillStatus::VERIFIED) {
                continue;
            }

            $consumption = $bill->value(BillField::ENERGY[0]); // 'consumption_kwh'
            $periodStart = $bill->value(BillField::BILLING_PERIOD_START);
            $periodEnd = $bill->value(BillField::BILLING_PERIOD_END);

            if ($consumption === null || (float) $consumption <= 0.0) {
                continue;
            }

            if ($periodStart === null || $periodEnd === null) {
                continue;
            }

            $dStart = DateTimeImmutable::createFromFormat('Y-m-d', $periodStart);
            $dEnd = DateTimeImmutable::createFromFormat('Y-m-d', $periodEnd);
            if ($dStart === false || $dEnd === false || $dStart >= $dEnd) {
                continue;
            }

            $days = $dStart->diff($dEnd)->days;
            if ($days <= 0) {
                $days = 30;
            }

            $verified[] = [
                'bill' => $bill,
                'start_dt' => $dStart,
                'end_dt' => $dEnd,
                'start_str' => $periodStart,
                'end_str' => $periodEnd,
                'days' => $days,
                'total_kwh' => (float) $consumption,
                'daily_kwh' => (float) $consumption / $days,
            ];
        }

        if ($verified === []) {
            return [];
        }

        // 2. Sort chronologically ASC (oldest first)
        usort($verified, static function (array $a, array $b): int {
            return $a['start_dt'] <=> $b['start_dt'];
        });

        // 3. Extract features chronologically without future leakage
        $dataset = [];
        $pastDailyUsages = [];

        foreach ($verified as $idx => $item) {
            $month = (int) $item['start_dt']->format('n'); // 1-12
            $seasonSin = round(sin((2.0 * M_PI * $month) / 12.0), 4);
            $seasonCos = round(cos((2.0 * M_PI * $month) / 12.0), 4);

            // Recency index (0.0 for oldest, 1.0 for newest)
            $recency = count($verified) > 1 ? ($idx / (count($verified) - 1)) : 1.0;

            // Rolling statistics strictly from past bills
            $lagDailyKwh = $pastDailyUsages !== [] ? end($pastDailyUsages) : $item['daily_kwh'];
            $rollingMeanKwh = $pastDailyUsages !== []
                ? array_sum($pastDailyUsages) / count($pastDailyUsages)
                : $item['daily_kwh'];

            // Features: [1.0 (bias), seasonSin, seasonCos, recency, lagNorm, rollingNorm, durationNorm]
            $baseScale = max(1.0, $rollingMeanKwh);
            $features = [
                1.0,
                $seasonSin,
                $seasonCos,
                $recency,
                $lagDailyKwh / $baseScale,
                $rollingMeanKwh / $baseScale,
                ($item['days'] - 30.0) / 30.0,
            ];

            $dataset[] = [
                'period_start' => $item['start_str'],
                'period_end' => $item['end_str'],
                'days' => $item['days'],
                'features' => $features,
                'target' => $item['daily_kwh'],
            ];

            // Append to past history for subsequent steps
            $pastDailyUsages[] = $item['daily_kwh'];
        }

        return $dataset;
    }
}
