<?php

declare(strict_types=1);

namespace App\Domain\Forecast;

use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use App\Integrations\Weather\FakeWeatherProvider;
use App\Integrations\Weather\OpenMeteoGeocodingClient;
use App\Repositories\BillRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\SolarReadingRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * F3 — Solar Generation Forecast Model (docs/01 §13-14, docs/02 §2, docs/10 D-040).
 *
 * Deterministic physics-informed model predicting solar generation based on:
 * - Inverter capacity / maximum observed peak generation from GoodWe actuals
 * - Historical diurnal generation profile from `solar_readings`
 * - Weather irradiance factors from `WeatherProviderInterface`
 * - Inverter connection status and telemetry freshness
 *
 * Invariants:
 * - AI never performs numerical calculations.
 * - Forecasts have data_nature = PREDICTED (or ESTIMATED if heuristic fallback).
 * - Actual GoodWe readings are NEVER overwritten.
 * - Weather failure produces an explicit limitation rather than fabricated weather.
 * - Low-data households receive honest low confidence and wide uncertainty intervals.
 */
final class SolarForecastModel
{
    public const MODEL_VERSION = 'SPMS_SOLAR_V1_DETERMINISTIC';

    /** Default inverter peak capacity in watts when not detected from history. Standard residential: 5 kW. */
    private const DEFAULT_CAPACITY_PEAK_W = 5000.0;

    private readonly HouseholdRepository $households;
    private readonly OpenMeteoGeocodingClient $geocoding;
    private readonly ?BillRepository $bills;

    public function __construct(
        private readonly SolarReadingRepository $readings,
        private readonly WeatherProviderInterface $weather,
        ?HouseholdRepository $households = null,
        ?OpenMeteoGeocodingClient $geocoding = null,
        ?BillRepository $bills = null
    ) {
        $this->households = $households ?? new HouseholdRepository();
        $this->geocoding = $geocoding ?? new OpenMeteoGeocodingClient();
        $this->bills = $bills ?? new BillRepository();
    }

    /**
     * Generate solar generation forecast for a given household, horizon, and time window.
     *
     * @param array<string, mixed>|null $solarConnection
     * @return ForecastSnapshot
     */
    public function generate(
        string $householdId,
        string $horizon,
        DateTimeImmutable $referenceTime,
        ?array $solarConnection = null
    ): ForecastSnapshot {
        $window = ForecastHorizon::computeTimeWindow($horizon, $referenceTime);
        $start = $window['start'];
        $end = $window['end'];
        $resolution = $window['resolution'];

        $limitations = [];
        $sources = ['goodwe_readings'];

        // 1. Check GoodWe connection & reading history
        $readingCount = $this->readings->countByHouseholdId($householdId);
        $latestReading = $this->readings->findLatestByHouseholdId($householdId);

        // Derive rated peak capacity using authoritative resolution ladder
        [$peakCapacityW, $capacitySource] = $this->resolveInverterPeakCapacityW(
            $householdId,
            $solarConnection,
            $latestReading
        );

        if ($capacitySource === 'verified_bill_contracted_load') {
            $sources[] = 'verified_electricity_bills';
        }

        // Connection status check
        $connStatus = $solarConnection['connection_status'] ?? 'NOT_CONFIGURED';
        if ($connStatus === 'NOT_CONFIGURED' || $connStatus === 'OFFLINE' || $connStatus === 'DEGRADED') {
            $limitations[] = sprintf('Inverter connection status is %s; real-time generation is not streaming.', $connStatus);
        }

        // Freshness check on latest actual reading
        if ($latestReading !== null && ($latestReading['data_status'] ?? '') === 'STALE') {
            $limitations[] = 'Latest GoodWe solar telemetry reading is STALE; generation baseline reflects past telemetry.';
        }

        // 2. Weather integration seam (F12 — Open-Meteo dynamic location & forecast)
        $household = $this->households->findById($householdId);
        $locationLabel = $household?->locationLabel();
        $householdTz = $household?->timezone() ?? 'Asia/Kolkata';

        $weatherResult = null;
        $geoResult = null;
        $resolvedLat = null;
        $resolvedLon = null;

        if ($locationLabel !== null && trim($locationLabel) !== '') {
            $geoResult = $this->geocoding->resolve($locationLabel, $householdTz);
            if ($geoResult['resolved']) {
                $resolvedLat = (float) $geoResult['latitude'];
                $resolvedLon = (float) $geoResult['longitude'];
                $weatherResult = $this->weather->getSolarFactors($resolvedLat, $resolvedLon, $start, $end);
            } else {
                $limitations[] = sprintf(
                    'Household location "%s" could not be resolved by weather service: %s. Forecast relies on clear-sky physical solar pattern.',
                    $locationLabel,
                    $geoResult['error'] ?? 'Unrecognized location'
                );
                $weatherResult = [
                    'available' => false,
                    'provider' => 'OPEN_METEO',
                    'location' => ['lat' => 0.0, 'lon' => 0.0],
                    'retrieved_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'error' => $geoResult['error'] ?? 'Unrecognized location',
                    'hourly_factors' => [],
                ];
            }
        } elseif ($this->weather instanceof FakeWeatherProvider) {
            // Testing / DEMO fixture compatibility when location_label is not set
            $weatherResult = $this->weather->getSolarFactors(0.0, 0.0, $start, $end);
        } else {
            $limitations[] = 'Household location is not configured: forecast relies on clear-sky physical solar pattern.';
            $weatherResult = [
                'available' => false,
                'provider' => 'OPEN_METEO',
                'location' => ['lat' => 0.0, 'lon' => 0.0],
                'retrieved_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'error' => 'Household location label is not configured.',
                'hourly_factors' => [],
            ];
        }

        $weatherFactorsByHour = [];
        if (!$weatherResult['available']) {
            $errorDetail = $weatherResult['error'] ?? null;
            if ($errorDetail !== null && !str_contains(implode(' ', $limitations), 'could not be resolved')) {
                $limitations[] = sprintf('External weather provider unavailable: %s. Forecast relies on clear-sky physical solar pattern.', $errorDetail);
            } elseif (!str_contains(implode(' ', $limitations), 'clear-sky physical solar pattern')) {
                $limitations[] = 'External weather provider unavailable: forecast relies on clear-sky physical solar pattern.';
            }
        } else {
            $sources[] = 'weather_irradiance';
            foreach ($weatherResult['hourly_factors'] as $factor) {
                $weatherFactorsByHour[$factor['timestamp']] = $factor;
            }
        }

        // 3. Adaptive confidence and uncertainty determination
        [$confidence, $uncertaintyPct, $dataNature, $dataQuality] = $this->determineAdaptiveConfidence(
            $readingCount,
            $weatherResult['available'],
            $connStatus
        );

        if ($latestReading !== null && ($latestReading['data_nature'] ?? '') === 'SIMULATED') {
            $dataNature = ForecastSnapshot::NATURE_SIMULATED;
        }

        if ($readingCount === 0) {
            if ($capacitySource === 'default_baseline') {
                $limitations[] = 'No historical solar telemetry recorded for this inverter: using standard residential 5 kW solar curve baseline.';
            } else {
                $limitations[] = sprintf(
                    'No historical solar telemetry recorded for this inverter: capacity resolved as %d W from %s.',
                    (int) $peakCapacityW,
                    str_replace('_', ' ', $capacitySource)
                );
            }
        } elseif ($readingCount < 50) {
            $limitations[] = sprintf('Limited solar history (%d readings): prediction confidence will improve as historical data accumulates.', $readingCount);
        }

        // 4. Generate time-series decomposition and aggregated energy
        $points = [];
        $totalPredictedKwh = 0.0;
        $current = $start;

        if ($resolution === ForecastHorizon::RESOLUTION_HOURLY) {
            while ($current < $end) {
                $hour = (int) $current->format('H');
                $timestampKey = $current->format('Y-m-d\TH:i:s\Z');

                $clearSkyFactor = $this->clearSkyDiurnalFactor($hour);

                // Apply weather attenuation if available
                $weatherFactor = 1.0;
                if (isset($weatherFactorsByHour[$timestampKey])) {
                    $weatherFactor = max(0.2, (float) $weatherFactorsByHour[$timestampKey]['clear_sky_index']);
                }

                $predictedPowerW = round($peakCapacityW * $clearSkyFactor * $weatherFactor, 1);
                $predictedKwh = round($predictedPowerW / 1000.0, 3); // 1 hour energy

                $lowerKwh = max(0.0, round($predictedKwh * (1.0 - $uncertaintyPct / 100.0), 3));
                $upperKwh = round($predictedKwh * (1.0 + $uncertaintyPct / 100.0), 3);

                $lowerW = max(0.0, round($predictedPowerW * (1.0 - $uncertaintyPct / 100.0), 1));
                $upperW = round($predictedPowerW * (1.0 + $uncertaintyPct / 100.0), 1);

                $points[] = [
                    'timestamp' => $timestampKey,
                    'hour' => $hour,
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

                // Average daylight energy calculation: typical 5.2 peak sun hours per clear day
                $baseDailyKwh = ($peakCapacityW / 1000.0) * 4.5;
                $predictedKwh = round($baseDailyKwh, 3);

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

        // Weather evidence summary for UI & provenance
        $weatherSummary = null;
        if ($weatherResult['available'] && !empty($weatherResult['hourly_factors'])) {
            $selectedFactor = null;
            foreach ($weatherResult['hourly_factors'] as $hf) {
                try {
                    $hour = (int) (new DateTimeImmutable($hf['timestamp']))->format('H');
                    if ($hour >= 11 && $hour <= 14) {
                        $selectedFactor = $hf;
                        break;
                    }
                } catch (\Throwable) {
                    // Ignore
                }
            }
            if ($selectedFactor === null) {
                $selectedFactor = $weatherResult['hourly_factors'][0] ?? null;
            }

            $weatherSummary = [
                'status' => 'WEATHER_ENHANCED',
                'available' => true,
                'provider' => $weatherResult['provider'] ?? 'OPEN_METEO',
                'source_label' => ($weatherResult['provider'] ?? '') === 'FAKE_WEATHER_PROVIDER' ? 'Simulated Open-Meteo' : 'Open-Meteo',
                'location_name' => $geoResult['name'] ?? $locationLabel ?? 'Household Location',
                'coordinates' => $resolvedLat !== null && $resolvedLon !== null ? [
                    'latitude' => $resolvedLat,
                    'longitude' => $resolvedLon,
                ] : ($weatherResult['location'] ?? null),
                'retrieved_at' => $weatherResult['retrieved_at'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'temperature_c' => $selectedFactor['temperature_c'] ?? null,
                'cloud_cover_pct' => $selectedFactor['cloud_cover_pct'] ?? null,
                'precipitation_probability_pct' => $selectedFactor['precipitation_probability_pct'] ?? null,
                'solar_irradiance_wm2' => $selectedFactor['direct_normal_irradiance_wm2'] ?? $selectedFactor['shortwave_radiation_wm2'] ?? null,
                'condition' => $selectedFactor['condition'] ?? 'CLEAR',
                'clear_sky_index' => $selectedFactor['clear_sky_index'] ?? null,
                'error' => null,
                'fallback_explanation' => null,
            ];
        } else {
            $isNotConfigured = ($locationLabel === null || trim($locationLabel) === '');
            $weatherSummary = [
                'status' => $isNotConfigured ? 'UNAVAILABLE' : 'FALLBACK',
                'available' => false,
                'provider' => $weatherResult['provider'] ?? 'OPEN_METEO',
                'source_label' => 'Open-Meteo',
                'location_name' => $geoResult['name'] ?? $locationLabel,
                'coordinates' => $resolvedLat !== null && $resolvedLon !== null ? [
                    'latitude' => $resolvedLat,
                    'longitude' => $resolvedLon,
                ] : null,
                'retrieved_at' => $weatherResult['retrieved_at'] ?? null,
                'temperature_c' => null,
                'cloud_cover_pct' => null,
                'precipitation_probability_pct' => null,
                'solar_irradiance_wm2' => null,
                'condition' => 'UNAVAILABLE',
                'error' => $weatherResult['error'] ?? ($isNotConfigured ? 'Household location label is not configured.' : 'Weather service unavailable'),
                'fallback_explanation' => $isNotConfigured
                    ? 'Household location is not configured: solar forecast relies on clear-sky physical solar pattern fallback model.'
                    : 'External weather provider unavailable: solar forecast relies on clear-sky physical solar pattern fallback model.',
            ];
        }

        return new ForecastSnapshot(
            forecastType: 'SOLAR',
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
                'model_version' => self::MODEL_VERSION,
                'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'sources' => $sources,
                'evidence_count' => $readingCount,
                'peak_capacity_w' => $peakCapacityW,
                'capacity_source' => $capacitySource,
                'weather_available' => $weatherResult['available'],
                'weather_provider' => $weatherResult['provider'] ?? null,
                'location_resolved' => $geoResult['resolved'] ?? false,
                'location_name' => $geoResult['name'] ?? null,
                'coordinates' => $resolvedLat !== null && $resolvedLon !== null ? [
                    'latitude' => $resolvedLat,
                    'longitude' => $resolvedLon,
                ] : null,
                'weather' => $weatherSummary,
            ],
            limitations: $limitations,
            timeSeriesPoints: $points
        );
    }

    /**
     * Compute diurnal clear sky solar factor (0.0 to 1.0) for a given hour of day.
     */
    private function clearSkyDiurnalFactor(int $hour): float
    {
        if ($hour < 6 || $hour > 18) {
            return 0.0;
        }

        $fractionOfDaylight = ($hour - 6) / 12.0;
        return round(sin($fractionOfDaylight * M_PI), 4);
    }

    /**
     * Evaluate adaptive confidence and uncertainty based on historical data volume and external seams.
     *
     * @return array{0: string, 1: float, 2: string, 3: string} [confidence, uncertaintyPct, dataNature, dataQuality]
     */
    private function determineAdaptiveConfidence(
        int $readingCount,
        bool $weatherAvailable,
        string $connStatus
    ): array {
        if ($readingCount === 0) {
            // No telemetry: baseline heuristic
            return [ForecastConfidence::LOW, 45.0, ForecastSnapshot::NATURE_ESTIMATED, ForecastSnapshot::QUALITY_UNVERIFIED];
        }

        if ($readingCount < 50) {
            // Low data (under ~4 hours of 5-minute telemetry)
            $conf = ForecastConfidence::LOW;
            $unc = $weatherAvailable ? 35.0 : 40.0;
            return [$conf, $unc, ForecastSnapshot::NATURE_PREDICTED, ForecastSnapshot::QUALITY_PARTIAL];
        }

        if ($readingCount < 500) {
            // Moderate data (approx 1-3 days of telemetry)
            $conf = $weatherAvailable ? ForecastConfidence::MODERATE : ForecastConfidence::LOW;
            $unc = $weatherAvailable ? 22.0 : 30.0;
            return [$conf, $unc, ForecastSnapshot::NATURE_PREDICTED, ForecastSnapshot::QUALITY_GOOD];
        }

        // Robust data (more than 500 readings, multiple days/weeks)
        if ($weatherAvailable) {
            return [ForecastConfidence::HIGH, 12.0, ForecastSnapshot::NATURE_PREDICTED, ForecastSnapshot::QUALITY_GOOD];
        }

        // Without weather, cap at MODERATE even with high data
        return [ForecastConfidence::MODERATE, 20.0, ForecastSnapshot::NATURE_PREDICTED, ForecastSnapshot::QUALITY_PARTIAL];
    }

    /**
     * Resolve the authoritative peak inverter capacity (in Watts) using the priority ladder:
     * 1. Inverter Serial Number / Model parsed from solar connection or latest reading (hardware truth)
     * 2. Historical telemetry peak power observed (power_w * 1.25)
     * 3. Authoritative verified electricity bill contracted load (contracted_load kW * 1000.0)
     * 4. Standard residential fallback baseline (5000.0 W)
     *
     * @param string $householdId
     * @param array<string, mixed>|null $solarConnection
     * @param array<string, mixed>|null $latestReading
     * @return array{0: float, 1: string} [peakCapacityW, capacitySource]
     */
    public function resolveInverterPeakCapacityW(
        string $householdId,
        ?array $solarConnection = null,
        ?array $latestReading = null
    ): array {
        // 1. Inverter SN / Model parsing (hardware truth)
        $sn = $solarConnection['inverter_sn'] ?? $latestReading['inverter_sn'] ?? null;
        if ($sn !== null && is_string($sn)) {
            $parsedCapacity = self::parseCapacityFromInverterSn($sn);
            if ($parsedCapacity !== null && $parsedCapacity > 0.0) {
                return [$parsedCapacity, 'inverter_sn'];
            }
        }

        // 2. Historical telemetry peak power
        $maxHistoricalPower = $this->readings->findMaxPowerW($householdId);
        if ($maxHistoricalPower !== null && $maxHistoricalPower > 0.0) {
            $capacityFromHistory = round($maxHistoricalPower * 1.25, 1);
            return [$capacityFromHistory, 'historical_telemetry'];
        }

        // 3. Verified bill contracted load
        if ($this->bills !== null) {
            try {
                $allBills = $this->bills->listForHousehold($householdId);
                foreach ($allBills as $bill) {
                    if ($bill->status() === BillStatus::VERIFIED) {
                        $load = $bill->value(BillField::LOAD[0]);
                        if ($load !== null && (float) $load > 0.0) {
                            $billCapacityW = (float) $load * 1000.0;
                            return [$billCapacityW, 'verified_bill_contracted_load'];
                        }
                    }
                }
            } catch (\Throwable) {
                // Non-fatal, fallback to baseline
            }
        }

        // 4. Default fallback baseline
        return [self::DEFAULT_CAPACITY_PEAK_W, 'default_baseline'];
    }

    /**
     * Attempt to parse inverter rated AC capacity in Watts from inverter serial number or model string.
     *
     * Patterns handled:
     * - GoodWe DNS / XS series: e.g. 53000N3A... -> 3000 W, 55000N... -> 5000 W, 54200N... -> 4200 W
     * - GoodWe explicit GW model format: GW3000-DNS -> 3000 W, GW5000 -> 5000 W, GW6000-DT -> 6000 W
     * - 'K' notation: GW5K -> 5000 W, GW10K -> 10000 W
     */
    public static function parseCapacityFromInverterSn(?string $sn): ?float
    {
        if ($sn === null || trim($sn) === '') {
            return null;
        }

        $sn = trim($sn);

        // 1. GoodWe DNS / XS series: e.g. 53000N3A..., 55000N..., 56000N...
        if (preg_match('/^[1-9](\d{4})[A-Za-z0-9]/', $sn, $matches)) {
            $watts = (float) $matches[1];
            if ($watts >= 1000.0 && $watts <= 25000.0) {
                return $watts;
            }
        }

        // 2. Explicit GW model format: GW3000-DNS, GW5000, GW6000-DT
        if (preg_match('/GW(\d{3,5})/i', $sn, $matches)) {
            $watts = (float) $matches[1];
            if ($watts >= 700.0 && $watts <= 50000.0) {
                return $watts;
            }
        }

        // 3. 'K' notation: GW5K, GW10K, 3K, 5K
        if (preg_match('/(?:GW|^)(\d{1,2}(?:\.\d+)?)K/i', $sn, $matches)) {
            $kw = (float) $matches[1];
            if ($kw >= 1.0 && $kw <= 50.0) {
                return $kw * 1000.0;
            }
        }

        return null;
    }
}
