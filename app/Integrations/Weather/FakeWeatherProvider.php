<?php

declare(strict_types=1);

namespace App\Integrations\Weather;

use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * F3 — Fake Weather Provider for automated testing and local development.
 *
 * Generates deterministic solar irradiance factors without external network requests.
 * Allows simulating provider downtime/failures to verify explicit limitation behavior.
 */
final class FakeWeatherProvider implements WeatherProviderInterface
{
    private bool $simulateFailure = false;
    private float $fixedCloudCoverPct = 15.0; // 15% default clear/partly cloudy

    public function setSimulateFailure(bool $failure): void
    {
        $this->simulateFailure = $failure;
    }

    public function setCloudCoverPct(float $pct): void
    {
        $this->fixedCloudCoverPct = max(0.0, min(100.0, $pct));
    }

    public function getSolarFactors(
        float $latitude,
        float $longitude,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        if ($this->simulateFailure) {
            return [
                'available' => false,
                'provider' => 'FAKE_WEATHER_PROVIDER',
                'location' => ['lat' => $latitude, 'lon' => $longitude],
                'retrieved_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'error' => 'Simulated external weather service timeout/connection failure.',
                'hourly_factors' => [],
            ];
        }

        $hourly = [];
        $current = $start;

        while ($current < $end) {
            $hour = (int) $current->format('H');

            // Physical diurnal solar irradiance factor (0 at night, bell curve between 06:00 and 18:00)
            $solarElevationFactor = 0.0;
            if ($hour >= 6 && $hour <= 18) {
                // Peak at 12 (solar noon)
                $fractionOfDaylight = ($hour - 6) / 12.0; // 0.0 to 1.0
                $solarElevationFactor = sin($fractionOfDaylight * M_PI);
            }

            // Cloud attenuation factor: 1.0 (clear sky) down to 0.25 (heavy clouds)
            $cloudAttenuation = 1.0 - ($this->fixedCloudCoverPct / 100.0 * 0.75);
            $clearSkyIndex = max(0.25, min(1.0, round($cloudAttenuation, 3)));

            $condition = $this->fixedCloudCoverPct > 60.0 ? 'CLOUDY' : ($this->fixedCloudCoverPct > 25.0 ? 'PARTLY_CLOUDY' : 'CLEAR');
            $weatherCode = $condition === 'CLEAR' ? 0 : ($condition === 'PARTLY_CLOUDY' ? 2 : 3);
            $solarImpact = $this->fixedCloudCoverPct < 30.0 ? 'STRONG' : ($this->fixedCloudCoverPct > 60.0 ? 'LOW' : 'MODERATE');

            $hourly[] = [
                'timestamp' => $current->format('Y-m-d\TH:i:s\Z'),
                'clear_sky_index' => $clearSkyIndex,
                'cloud_cover_pct' => $this->fixedCloudCoverPct,
                'temperature_c' => 28.5,
                'precipitation_probability_pct' => 5.0,
                'precipitation' => 0.0,
                'direct_normal_irradiance_wm2' => round($solarElevationFactor * $cloudAttenuation * 800.0, 1),
                'shortwave_radiation_wm2' => round($solarElevationFactor * $cloudAttenuation * 850.0, 1),
                'weather_code' => $weatherCode,
                'condition' => $condition,
                'solar_impact' => $solarImpact,
            ];

            $current = $current->modify('+1 hour');
        }

        return [
            'available' => true,
            'provider' => 'FAKE_WEATHER_PROVIDER',
            'location' => ['lat' => $latitude, 'lon' => $longitude],
            'retrieved_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'error' => null,
            'hourly_factors' => $hourly,
        ];
    }
}
