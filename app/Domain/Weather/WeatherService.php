<?php

declare(strict_types=1);

namespace App\Domain\Weather;

use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use App\Integrations\Weather\FakeWeatherProvider;
use App\Integrations\Weather\OpenMeteoGeocodingClient;
use App\Integrations\Weather\OpenMeteoWeatherProvider;
use App\Repositories\HouseholdRepository;
use App\Support\AppException;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * SPMS Weather Intelligence — Canonical Household Weather Service.
 *
 * Provides normalized, authoritative weather evidence for an authenticated household:
 * - 100% server-side: no frontend/browser direct external calls to Open-Meteo.
 * - Supporting predictive evidence ONLY — never labeled as actual solar generation.
 * - Multi-tier caching: reuses existing Open-Meteo cache without duplicate network requests.
 * - Honest states: ACTIVE (FRESH), STALE, UNAVAILABLE, FALLBACK.
 * - Zero fabricated data when location cannot be resolved or external provider times out.
 * - Household-scoped: strictly bound to authenticated household configuration.
 */
final class WeatherService
{
    private readonly HouseholdRepository $households;
    private readonly OpenMeteoGeocodingClient $geocoding;
    private readonly WeatherProviderInterface $weather;

    public function __construct(
        ?HouseholdRepository $households = null,
        ?OpenMeteoGeocodingClient $geocoding = null,
        ?WeatherProviderInterface $weather = null
    ) {
        $this->households = $households ?? new HouseholdRepository();
        $this->geocoding = $geocoding ?? new OpenMeteoGeocodingClient();

        if ($weather !== null) {
            $this->weather = $weather;
        } else {
            $weatherDriver = strtolower(trim((string) Config::get('WEATHER_PROVIDER', 'openmeteo')));
            $isDemo = Config::get('SPMS_DATA_ENVIRONMENT') === 'demo';

            if ($weatherDriver === 'fake' || $isDemo) {
                $this->weather = new FakeWeatherProvider();
            } else {
                $this->weather = new OpenMeteoWeatherProvider();
            }
        }
    }

    /**
     * Retrieve normalized weather structure for a household.
     *
     * @param string $householdId
     * @param DateTimeImmutable|null $referenceTime
     * @param bool $forceFresh
     * @return array<string, mixed>
     */
    public function getWeatherForHousehold(
        string $householdId,
        ?DateTimeImmutable $referenceTime = null,
        bool $forceFresh = false
    ): array {
        $household = $this->households->findById($householdId);
        if ($household === null) {
            throw new AppException(
                'HOUSEHOLD_NOT_FOUND',
                'Household not found.',
                404
            );
        }

        $locationLabel = $household->locationLabel();
        $householdTz = $household->timezone() ?? 'Asia/Kolkata';

        $refTime = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $localTz = new DateTimeZone($householdTz);
        $localNow = $refTime->setTimezone($localTz);

        // 24-hour time window starting from beginning of today through end of tomorrow
        $windowStart = $localNow->setTime(0, 0, 0);
        $windowEnd = $localNow->modify('+1 day')->setTime(23, 59, 59);

        // State 1: Location not configured (UNAVAILABLE)
        if ($locationLabel === null || trim($locationLabel) === '') {
            return [
                'household_id' => $householdId,
                'location' => null,
                'latitude' => null,
                'longitude' => null,
                'timezone' => $householdTz,
                'provider' => 'Astronomical Clear-Sky Model',
                'provider_timestamp' => null,
                'fetched_timestamp' => null,
                'status' => 'UNAVAILABLE',
                'freshness' => 'UNAVAILABLE',
                'available' => false,
                'temperature' => null,
                'cloud_cover' => null,
                'direct_normal_irradiance' => null,
                'shortwave_irradiance' => null,
                'precipitation_probability' => null,
                'precipitation' => null,
                'weather_code' => null,
                'condition' => 'UNAVAILABLE',
                'solar_impact' => 'UNAVAILABLE',
                'solar_impact_description' => 'Household location is not configured; solar forecast relies on clear-sky diurnal fallback.',
                'sunrise' => null,
                'sunset' => null,
                'hourly' => [],
                'limitations' => ['Household location label is not configured. Clear-sky diurnal model applies.'],
                'error' => 'Household location label is not configured.',
            ];
        }

        // State 2: Attempt Geocoding
        $geoResult = $this->geocoding->resolve($locationLabel, $householdTz);
        if (!$geoResult['resolved']) {
            return [
                'household_id' => $householdId,
                'location' => $locationLabel,
                'latitude' => null,
                'longitude' => null,
                'timezone' => $householdTz,
                'provider' => 'Astronomical Clear-Sky Model',
                'provider_timestamp' => null,
                'fetched_timestamp' => null,
                'status' => 'FALLBACK',
                'freshness' => 'FALLBACK',
                'available' => false,
                'temperature' => null,
                'cloud_cover' => null,
                'direct_normal_irradiance' => null,
                'shortwave_irradiance' => null,
                'precipitation_probability' => null,
                'precipitation' => null,
                'weather_code' => null,
                'condition' => 'FALLBACK',
                'solar_impact' => 'MODERATE',
                'solar_impact_description' => 'Location coordinates could not be resolved; clear-sky physical solar model applies.',
                'sunrise' => null,
                'sunset' => null,
                'hourly' => [],
                'limitations' => ['Geographic location coordinates could not be resolved. Clear-sky diurnal model applies.'],
                'error' => $geoResult['error'] ?? 'Location could not be resolved.',
            ];
        }

        $lat = (float) $geoResult['latitude'];
        $lon = (float) $geoResult['longitude'];
        $locationName = $geoResult['name'] ?? $locationLabel;

        // Compute physical sunrise and sunset times deterministically via PHP built-in date_sun_info
        $sunInfo = date_sun_info($localNow->getTimestamp(), $lat, $lon);
        $sunriseIso = is_int($sunInfo['sunrise'])
            ? (new DateTimeImmutable('@' . $sunInfo['sunrise']))->setTimezone($localTz)->format('c')
            : null;
        $sunsetIso = is_int($sunInfo['sunset'])
            ? (new DateTimeImmutable('@' . $sunInfo['sunset']))->setTimezone($localTz)->format('c')
            : null;

        // State 3: Fetch weather factors from provider (memory/disk cached)
        $weatherResult = $this->weather->getSolarFactors(
            $lat,
            $lon,
            $windowStart,
            $windowEnd
        );

        if (!$weatherResult['available']) {
            return [
                'household_id' => $householdId,
                'location' => $locationName,
                'latitude' => round($lat, 4),
                'longitude' => round($lon, 4),
                'timezone' => $householdTz,
                'provider' => $weatherResult['provider'] ?? 'Open-Meteo',
                'provider_timestamp' => null,
                'fetched_timestamp' => $weatherResult['retrieved_at'] ?? null,
                'status' => 'FALLBACK',
                'freshness' => 'FALLBACK',
                'available' => false,
                'temperature' => null,
                'cloud_cover' => null,
                'direct_normal_irradiance' => null,
                'shortwave_irradiance' => null,
                'precipitation_probability' => null,
                'precipitation' => null,
                'weather_code' => null,
                'condition' => 'FALLBACK',
                'solar_impact' => 'MODERATE',
                'solar_impact_description' => 'External weather service unavailable; solar forecast relies on clear-sky diurnal fallback.',
                'sunrise' => $sunriseIso,
                'sunset' => $sunsetIso,
                'hourly' => [],
                'limitations' => ['External weather provider unavailable: clear-sky physical solar pattern fallback model is active.'],
                'error' => $weatherResult['error'] ?? 'Weather provider unavailable.',
            ];
        }

        // State 4: Freshness check (within 1 hour = ACTIVE/FRESH, older = STALE)
        $retrievedAt = $weatherResult['retrieved_at'] ?? $refTime->format('Y-m-d H:i:s');
        $retrievedEpoch = strtotime($retrievedAt) ?: $refTime->getTimestamp();
        $ageSeconds = abs($refTime->getTimestamp() - $retrievedEpoch);
        $isStale = $ageSeconds > 3600;

        $status = $isStale ? 'STALE' : 'ACTIVE';
        $freshness = $isStale ? 'STALE' : 'FRESH';

        // Extract hourly forecast periods and determine representative condition
        $hourlyFactors = $weatherResult['hourly_factors'] ?? [];
        $currentEpoch = $localNow->getTimestamp();
        $currentFactor = null;
        $closestDiff = PHP_INT_MAX;

        $daytimeCloudSum = 0.0;
        $daytimeDniSum = 0.0;
        $daytimeCount = 0;
        $mappedHourly = [];

        foreach ($hourlyFactors as $hf) {
            $hfTimeStr = $hf['timestamp'];
            try {
                $hfDt = new DateTimeImmutable($hfTimeStr);
                $hfLocal = $hfDt->setTimezone($localTz);
                $epoch = $hfLocal->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            $diff = abs($epoch - $currentEpoch);
            if ($diff < $closestDiff) {
                $closestDiff = $diff;
                $currentFactor = $hf;
            }

            $hour = (int) $hfLocal->format('H');
            $isDaylight = ($hour >= 6 && $hour <= 18);

            $cloudPct = isset($hf['cloud_cover_pct']) && is_numeric($hf['cloud_cover_pct']) ? (float) $hf['cloud_cover_pct'] : null;
            $dni = isset($hf['direct_normal_irradiance_wm2']) && is_numeric($hf['direct_normal_irradiance_wm2']) ? (float) $hf['direct_normal_irradiance_wm2'] : null;
            $precipProb = isset($hf['precipitation_probability_pct']) && is_numeric($hf['precipitation_probability_pct']) ? (float) $hf['precipitation_probability_pct'] : 0.0;

            if ($isDaylight && $cloudPct !== null) {
                $daytimeCloudSum += $cloudPct;
                $daytimeCount++;
                if ($dni !== null) {
                    $daytimeDniSum += $dni;
                }
            }

            $hourSolarImpact = 'MODERATE';
            if ($cloudPct !== null) {
                if ($cloudPct < 30.0 && (($dni !== null && $dni > 300.0) || ($hf['clear_sky_index'] ?? 0.5) >= 0.70)) {
                    $hourSolarImpact = 'STRONG';
                } elseif ($cloudPct > 70.0 || $precipProb >= 60.0) {
                    $hourSolarImpact = 'LOW';
                }
            }

            $mappedHourly[] = [
                'timestamp' => $hfTimeStr,
                'local_time' => $hfLocal->format('Y-m-d H:i:s'),
                'hour' => $hour,
                'is_daylight' => $isDaylight,
                'temperature_c' => $hf['temperature_c'] ?? null,
                'cloud_cover_pct' => $cloudPct,
                'precipitation_probability_pct' => $precipProb,
                'precipitation_mm' => $hf['precipitation'] ?? 0.0,
                'direct_normal_irradiance_wm2' => $dni,
                'shortwave_radiation_wm2' => $hf['shortwave_radiation_wm2'] ?? null,
                'condition' => $hf['condition'] ?? 'CLEAR',
                'weather_code' => $hf['weather_code'] ?? ($hf['condition'] === 'CLEAR' ? 0 : 2),
                'clear_sky_index' => $hf['clear_sky_index'] ?? null,
                'solar_impact' => $hourSolarImpact,
            ];
        }

        if ($currentFactor === null && !empty($hourlyFactors)) {
            $currentFactor = $hourlyFactors[0];
        }

        // Compute overall daytime solar impact interpretation
        $avgDaytimeCloud = $daytimeCount > 0 ? ($daytimeCloudSum / $daytimeCount) : 20.0;
        $avgDaytimeDni = $daytimeCount > 0 ? ($daytimeDniSum / $daytimeCount) : 400.0;

        $solarImpact = 'MODERATE';
        $solarImpactDescription = 'Moderate solar conditions expected with partial cloud cover.';

        if ($avgDaytimeCloud < 30.0 || $avgDaytimeDni > 450.0) {
            $solarImpact = 'STRONG';
            $solarImpactDescription = 'Strong solar conditions expected with low cloud cover and high solar irradiance.';
        } elseif ($avgDaytimeCloud > 70.0) {
            $solarImpact = 'LOW';
            $solarImpactDescription = 'Reduced solar conditions expected due to elevated cloud cover.';
        }

        $providerName = ($weatherResult['provider'] ?? '') === 'FAKE_WEATHER_PROVIDER'
            ? 'Simulated Open-Meteo'
            : 'Open-Meteo';

        $providerTimestamp = $currentFactor['timestamp'] ?? $retrievedAt;

        return [
            'household_id' => $householdId,
            'location' => $locationName,
            'latitude' => round($lat, 4),
            'longitude' => round($lon, 4),
            'timezone' => $householdTz,
            'provider' => $providerName,
            'provider_timestamp' => $providerTimestamp,
            'fetched_timestamp' => $retrievedAt,
            'status' => $status,
            'freshness' => $freshness,
            'available' => true,
            'temperature' => $currentFactor['temperature_c'] ?? null,
            'cloud_cover' => $currentFactor['cloud_cover_pct'] ?? null,
            'direct_normal_irradiance' => $currentFactor['direct_normal_irradiance_wm2'] ?? null,
            'shortwave_irradiance' => $currentFactor['shortwave_radiation_wm2'] ?? null,
            'precipitation_probability' => $currentFactor['precipitation_probability_pct'] ?? null,
            'precipitation' => $currentFactor['precipitation'] ?? 0.0,
            'weather_code' => $currentFactor['weather_code'] ?? ($currentFactor['condition'] === 'CLEAR' ? 0 : 2),
            'condition' => $currentFactor['condition'] ?? 'CLEAR',
            'solar_impact' => $solarImpact,
            'solar_impact_description' => $solarImpactDescription,
            'sunrise' => $sunriseIso,
            'sunset' => $sunsetIso,
            'hourly' => array_slice($mappedHourly, 0, 24),
            'limitations' => $isStale ? ['Weather evidence was fetched >1 hour ago and is classified as STALE. Solar forecast may reflect older atmospheric data.'] : [],
            'error' => null,
        ];
    }
}
