<?php

declare(strict_types=1);

namespace App\Integrations\Weather;

use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * F12 — Live Open-Meteo Weather & Solar Irradiance Provider.
 *
 * Connects to Open-Meteo's free Forecast API to fetch hourly weather variables:
 * - cloud_cover (%)
 * - precipitation_probability (%)
 * - temperature_2m (°C)
 * - direct_normal_irradiance (W/m²)
 * - shortwave_radiation (W/m²)
 *
 * Key guarantees:
 * - 100% server-side: zero client-side direct calls to external weather APIs.
 * - Non-commercial Open-Meteo tier: no API keys or credentials required.
 * - Graceful failure: errors/timeouts return available=false with explicit error limitation.
 * - Multi-tier caching: in-memory and disk cache prevents unnecessary repeated external calls.
 * - Deterministic testing support: accepts mockable HTTP transport callable.
 * - Aligns hourly factors directly with the requesting time window in UTC.
 */
final class OpenMeteoWeatherProvider implements WeatherProviderInterface
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const TIMEOUT_SECONDS = 4;
    private const CACHE_TTL_SECONDS = 1800; // 30 minutes

    /** @var array<string, array<string, mixed>> In-memory cache */
    private static array $memoryCache = [];

    /** @var (callable(string): string)|null */
    private $httpTransport;

    public function __construct(
        private readonly string $timezone = 'Asia/Kolkata',
        ?callable $httpTransport = null
    ) {
        $this->httpTransport = $httpTransport;
    }

    /**
     * Clear the in-memory and disk cache (primarily for unit testing).
     */
    public static function clearCache(): void
    {
        self::$memoryCache = [];
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spms_weather_cache';
        if (is_dir($dir)) {
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Retrieve solar irradiance and atmospheric factors for a time window.
     *
     * @return array{
     *     available: bool,
     *     provider: string,
     *     location: array{lat: float, lon: float},
     *     retrieved_at: string,
     *     error: ?string,
     *     hourly_factors: list<array{
     *         timestamp: string,
     *         clear_sky_index: float,
     *         cloud_cover_pct: float,
     *         temperature_c: ?float,
     *         condition: string
     *     }>
     * }
     */
    public function getSolarFactors(
        float $latitude,
        float $longitude,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $retrievedAt = $nowUtc->format('Y-m-d H:i:s');

        // Check cache
        $cacheKey = sprintf(
            'om_%s_%s_%s_%s_%s',
            number_format($latitude, 3, '.', ''),
            number_format($longitude, 3, '.', ''),
            $this->timezone,
            $start->format('YmdH'),
            $end->format('YmdH')
        );

        if (array_key_exists($cacheKey, self::$memoryCache)) {
            return self::$memoryCache[$cacheKey];
        }

        $diskResult = $this->readFromDiskCache($cacheKey);
        if ($diskResult !== null) {
            self::$memoryCache[$cacheKey] = $diskResult;
            return $diskResult;
        }

        try {
            $tz = new DateTimeZone($this->timezone);
            $startDate = $start->setTimezone($tz)->format('Y-m-d');
            $endDate = $end->setTimezone($tz)->format('Y-m-d');

            $url = self::FORECAST_URL . '?' . http_build_query([
                'latitude' => round($latitude, 4),
                'longitude' => round($longitude, 4),
                'hourly' => 'cloud_cover,precipitation_probability,temperature_2m,direct_normal_irradiance,shortwave_radiation',
                'timezone' => $this->timezone,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            $rawJson = $this->fetchUrl($url);
            $data = json_decode($rawJson, true);

            if (!is_array($data) || !isset($data['hourly']) || !is_array($data['hourly'])) {
                $errorMsg = is_array($data) && isset($data['reason']) ? (string) $data['reason'] : 'Invalid JSON response from Open-Meteo';
                throw new RuntimeException($errorMsg);
            }

            $hourlyFactors = $this->extractHourlyFactors($data['hourly'], $start, $end);

            $result = [
                'available' => true,
                'provider' => 'OPEN_METEO',
                'location' => [
                    'lat' => round($latitude, 4),
                    'lon' => round($longitude, 4),
                ],
                'retrieved_at' => $retrievedAt,
                'error' => null,
                'hourly_factors' => $hourlyFactors,
            ];

            self::$memoryCache[$cacheKey] = $result;
            $this->writeToDiskCache($cacheKey, $result);

            return $result;
        } catch (Throwable $e) {
            return [
                'available' => false,
                'provider' => 'OPEN_METEO',
                'location' => [
                    'lat' => round($latitude, 4),
                    'lon' => round($longitude, 4),
                ],
                'retrieved_at' => $retrievedAt,
                'error' => sprintf('Open-Meteo forecast error: %s', $e->getMessage()),
                'hourly_factors' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $hourly
     * @return list<array{
     *     timestamp: string,
     *     clear_sky_index: float,
     *     cloud_cover_pct: float,
     *     temperature_c: ?float,
     *     condition: string
     * }>
     */
    private function extractHourlyFactors(array $hourly, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $times = $hourly['time'] ?? [];
        $clouds = $hourly['cloud_cover'] ?? [];
        $precips = $hourly['precipitation_probability'] ?? [];
        $temps = $hourly['temperature_2m'] ?? [];

        $dnis = $hourly['direct_normal_irradiance'] ?? [];
        $swrs = $hourly['shortwave_radiation'] ?? [];

        if (!is_array($times) || $times === []) {
            return [];
        }

        $tz = new DateTimeZone($this->timezone);
        $points = [];

        foreach ($times as $idx => $timeStr) {
            try {
                $dt = new DateTimeImmutable((string) $timeStr, $tz);
                $points[] = [
                    'epoch' => $dt->getTimestamp(),
                    'cloud' => isset($clouds[$idx]) && is_numeric($clouds[$idx]) ? (float) $clouds[$idx] : 0.0,
                    'precip' => isset($precips[$idx]) && is_numeric($precips[$idx]) ? (float) $precips[$idx] : 0.0,
                    'temp' => isset($temps[$idx]) && is_numeric($temps[$idx]) ? (float) $temps[$idx] : null,
                    'dni' => isset($dnis[$idx]) && is_numeric($dnis[$idx]) ? (float) $dnis[$idx] : null,
                    'swr' => isset($swrs[$idx]) && is_numeric($swrs[$idx]) ? (float) $swrs[$idx] : null,
                ];
            } catch (Throwable) {
                continue;
            }
        }

        if ($points === []) {
            return [];
        }

        $factors = [];
        $current = $start;

        while ($current < $end) {
            $targetEpoch = $current->getTimestamp();

            // Find closest Open-Meteo hourly data point (within 3600 seconds)
            $best = null;
            $bestDiff = PHP_INT_MAX;

            foreach ($points as $p) {
                $diff = abs($p['epoch'] - $targetEpoch);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $p;
                }
            }

            if ($best !== null && $bestDiff <= 3600) {
                $cloudCoverPct = max(0.0, min(100.0, $best['cloud']));
                $precipProb = max(0.0, min(100.0, $best['precip']));
                $tempC = $best['temp'];
                $dni = $best['dni'] ?? null;
                $swr = $best['swr'] ?? null;

                // Physical cloud attenuation factor: 1.0 (clear sky) down to 0.25 (100% cloud cover)
                $cloudAttenuation = 1.0 - ($cloudCoverPct / 100.0 * 0.75);

                // Rain probability attenuation: dense precipitation further attenuates solar irradiance
                $rainAttenuation = 1.0;
                if ($precipProb > 50.0) {
                    $rainAttenuation = 1.0 - (($precipProb - 50.0) / 100.0 * 0.35);
                }

                // Clamped clear-sky index between 0.2 and 1.0
                $clearSkyIndex = max(0.2, min(1.0, round($cloudAttenuation * $rainAttenuation, 3)));

                // Deterministic condition classification
                $condition = 'CLEAR';
                if ($precipProb >= 60.0) {
                    $condition = 'RAIN';
                } elseif ($cloudCoverPct >= 75.0) {
                    $condition = 'OVERCAST';
                } elseif ($cloudCoverPct >= 40.0) {
                    $condition = 'CLOUDY';
                } elseif ($cloudCoverPct >= 15.0) {
                    $condition = 'PARTLY_CLOUDY';
                }

                $factors[] = [
                    'timestamp' => $current->format('Y-m-d\TH:i:s\Z'),
                    'clear_sky_index' => $clearSkyIndex,
                    'cloud_cover_pct' => round($cloudCoverPct, 1),
                    'temperature_c' => $tempC !== null ? round($tempC, 1) : null,
                    'precipitation_probability_pct' => round($precipProb, 1),
                    'direct_normal_irradiance_wm2' => $dni !== null ? round((float) $dni, 1) : null,
                    'shortwave_radiation_wm2' => $swr !== null ? round((float) $swr, 1) : null,
                    'condition' => $condition,
                ];
            }

            $current = $current->modify('+1 hour');
        }

        return $factors;
    }

    private function fetchUrl(string $url): string
    {
        if ($this->httpTransport !== null) {
            return ($this->httpTransport)(url: $url);
        }

        return $this->executeCurl($url);
    }

    private function executeCurl(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'SPMS/1.0 (Solar Power Monitoring System)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($errNo !== 0) {
            throw new RuntimeException(sprintf('Network error (%d): %s', $errNo, $errMsg));
        }

        if ($httpCode >= 400) {
            throw new RuntimeException(sprintf('HTTP error %d', $httpCode));
        }

        if (!is_string($response) || $response === '') {
            throw new RuntimeException('Empty response from Open-Meteo forecast service');
        }

        return $response;
    }

    private function diskCachePath(string $key): string
    {
        $hash = md5($key);
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spms_weather_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readFromDiskCache(string $key): ?array
    {
        $file = $this->diskCachePath($key);
        if (!file_exists($file)) {
            return null;
        }

        if ((time() - filemtime($file)) > self::CACHE_TTL_SECONDS) {
            @unlink($file);
            return null;
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeToDiskCache(string $key, array $data): void
    {
        $file = $this->diskCachePath($key);
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
