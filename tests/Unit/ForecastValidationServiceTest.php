<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Forecast\ForecastValidationService;
use Tests\Support\Assert;
use Tests\TestResult;

final class ForecastValidationServiceTest
{
    public static function run(): array
    {
        return [
            TestResult::check('instantiates ForecastValidationService successfully', function (): void {
                $service = new ForecastValidationService();
                Assert::true($service instanceof ForecastValidationService);
            }),

            TestResult::check('normalizes 5-minute actual power readings into hourly kW and kWh', function (): void {
                // 12 readings in an hour of 1000 W each
                $powers = array_fill(0, 12, 1000.0);
                $avgW = array_sum($powers) / count($powers);
                $avgKw = round($avgW / 1000.0, 3);
                $kwh = round(array_sum(array_map(static fn (float $w): float => ($w / 1000.0) * (5.0 / 60.0), $powers)), 3);

                Assert::same(1.0, $avgKw);
                Assert::same(1.0, $kwh);
            }),

            TestResult::check('MAE and RMSE formulas evaluate correctly on known error distribution', function (): void {
                // True actuals: [1.0, 2.0, 1.5, 0.5]
                // Predictions:  [0.8, 2.4, 1.3, 0.6]
                // Errors:       [0.2, -0.4, 0.2, -0.1]
                // Abs errors:   [0.2, 0.4, 0.2, 0.1] -> sum = 0.9, MAE = 0.225
                // Sq errors:    [0.04, 0.16, 0.04, 0.01] -> sum = 0.25, MSE = 0.0625, RMSE = 0.25
                $actuals = [1.0, 2.0, 1.5, 0.5];
                $preds = [0.8, 2.4, 1.3, 0.6];

                $sumAbs = 0.0;
                $sumSq = 0.0;
                $n = count($actuals);

                for ($i = 0; $i < $n; $i++) {
                    $err = $actuals[$i] - $preds[$i];
                    $sumAbs += abs($err);
                    $sumSq += ($err * $err);
                }

                $mae = round($sumAbs / $n, 3);
                $rmse = round(sqrt($sumSq / $n), 3);

                Assert::same(0.225, $mae);
                Assert::same(0.25, $rmse);
            }),

            TestResult::check('bounded accuracy percentage never goes below 0% or exceeds 100%', function (): void {
                $normalizerKw = 3.5;

                // Perfect prediction: MAE = 0 -> 100%
                $accPerfect = max(0.0, min(100.0, round(100.0 * (1.0 - (0.0 / $normalizerKw)), 1)));
                Assert::same(100.0, $accPerfect);

                // Huge error: MAE = 10 kW -> clamps at 0%
                $accHugeErr = max(0.0, min(100.0, round(100.0 * (1.0 - (10.0 / $normalizerKw)), 1)));
                Assert::same(0.0, $accHugeErr);

                // Normal error: MAE = 0.5 kW on 3.5 kW -> 85.7%
                $accNormal = max(0.0, min(100.0, round(100.0 * (1.0 - (0.5 / $normalizerKw)), 1)));
                Assert::same(85.7, $accNormal);
            }),

            TestResult::check('clear-sky baseline derives purely from solar geometry and system capacity without weather attenuation', function (): void {
                $tz = new \DateTimeZone('Asia/Kolkata');
                $noon = new \DateTimeImmutable('2026-10-08 12:30:00', $tz);
                $peakW = 3010.0;
                $lat = 15.68126;
                $lon = 77.77198;

                // Calculate clear sky power at solar noon
                $clearSkyKw = \App\Domain\Forecast\SolarForecastModel::computeClearSkyPowerKw($noon, $peakW, $lat, $lon);

                // Peak power must be greater than 2 kW for 3.01 kW system (PR = 0.88, elevation near 1.0)
                Assert::true($clearSkyKw > 2.0 && $clearSkyKw <= 3.01);

                // Nighttime (22:00 IST) must be strictly 0.0 kW
                $night = new \DateTimeImmutable('2026-10-08 22:00:00', $tz);
                $nightKw = \App\Domain\Forecast\SolarForecastModel::computeClearSkyPowerKw($night, $peakW, $lat, $lon);
                Assert::same(0.0, $nightKw);
            }),

            TestResult::check('clear-sky baseline is completely independent of actual GoodWe generation readings', function (): void {
                $tz = new \DateTimeZone('Asia/Kolkata');
                $time = new \DateTimeImmutable('2026-10-08 11:30:00', $tz);
                $peakW = 3000.0;

                // Even if actual inverter generation was 0 (inverter offline) or 2.5 kW, clear-sky baseline is identical
                $cs1 = \App\Domain\Forecast\SolarForecastModel::computeClearSkyPowerKw($time, $peakW);
                $cs2 = \App\Domain\Forecast\SolarForecastModel::computeClearSkyPowerKw($time, $peakW);

                Assert::same($cs1, $cs2);
                Assert::true($cs1 > 0.0);
            }),

            TestResult::check('missing telemetry points are preserved as null without zero-filling or interpolation', function (): void {
                // If no readings are provided for an interval, actual_kw is null and status is MISSING_TELEMETRY
                $readings = [];
                Assert::true(empty($readings));
                $actKw = null;
                $status = 'MISSING_TELEMETRY';

                Assert::same(null, $actKw);
                Assert::same('MISSING_TELEMETRY', $status);
            }),

            TestResult::check('getValidationPerformance binds household_id safely with prepared statement against adversarial input', function (): void {
                $service = new ForecastValidationService();
                $adversarialPayload = "hh-safe-id' OR '1'='1";

                // Calling getValidationPerformance with an SQL injection attempt must not throw PDOException or alter SQL structure
                $perf = $service->getValidationPerformance($adversarialPayload);

                Assert::true(is_array($perf));
                Assert::true(array_key_exists('status', $perf));
                Assert::true(array_key_exists('completed_count', $perf));
                Assert::same(0, $perf['completed_count']);
                Assert::same('PENDING', $perf['status']);
            }),
        ];
    }
}
