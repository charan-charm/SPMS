<?php

declare(strict_types=1);

namespace App\Integrations\Weather;

use App\Support\Config;
use RuntimeException;
use Throwable;

/**
 * F12 — Open-Meteo Geocoding Client.
 *
 * Resolves a household location label (e.g. "Kodumur, Kurnool, Andhra Pradesh",
 * "Tirupati, AP", "Hyderabad, Telangana") into geographic coordinates dynamically.
 *
 * Features:
 * - Validates candidate matches against the expected country/region (default India / IN).
 * - Caches resolved coordinates to eliminate redundant network round-trips.
 * - Supports an injectable HTTP transport for deterministic testing.
 * - Never hard-codes coordinates; falls back gracefully if geocoding fails.
 */
final class OpenMeteoGeocodingClient
{
    private const GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    private const TIMEOUT_SECONDS = 4;

    /** @var array<string, array<string, mixed>|null> In-memory cache per request/process */
    private static array $memoryCache = [];

    /** @var (callable(string): string)|null */
    private $httpTransport;

    /**
     * @param (callable(string): string)|null $httpTransport Custom HTTP GET callable for testing
     */
    public function __construct(?callable $httpTransport = null)
    {
        $this->httpTransport = $httpTransport;
    }

    /**
     * Clear the static in-memory and disk cache (primarily for unit testing).
     */
    public static function clearCache(): void
    {
        self::$memoryCache = [];
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spms_geocoding_cache';
        if (is_dir($dir)) {
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Resolve a location label to geographic coordinates.
     *
     * @return array{
     *     resolved: bool,
     *     latitude?: float,
     *     longitude?: float,
     *     name?: string,
     *     admin1?: ?string,
     *     country?: ?string,
     *     country_code?: ?string,
     *     timezone?: ?string,
     *     error?: string
     * }
     */
    public function resolve(string $locationLabel, string $householdTimezone = 'Asia/Kolkata'): array
    {
        $cleaned = trim($locationLabel);
        if ($cleaned === '') {
            return [
                'resolved' => false,
                'error' => 'Household location label is empty.',
            ];
        }

        // Generic non-geographic labels that cannot be resolved
        $genericLabels = ['home', 'primary residence', 'residence', 'my house', 'household', 'apartment', 'villa'];
        if (in_array(strtolower($cleaned), $genericLabels, true)) {
            return [
                'resolved' => false,
                'error' => sprintf("Location '%s' is a generic label and does not specify a geographic city or town.", $locationLabel),
            ];
        }

        // Check cache
        $cacheKey = strtolower($cleaned) . '|' . strtolower($householdTimezone);
        if (array_key_exists($cacheKey, self::$memoryCache)) {
            $cached = self::$memoryCache[$cacheKey];
            if ($cached !== null) {
                return $cached;
            }
        }

        // Check file cache if available
        $fileCacheResult = $this->readFromDiskCache($cacheKey);
        if ($fileCacheResult !== null) {
            self::$memoryCache[$cacheKey] = $fileCacheResult;
            return $fileCacheResult;
        }

        // Extract candidate city queries and region hints
        [$candidates, $regionHints] = $this->parseLocationQuery($cleaned);

        try {
            $results = [];
            $searchQuery = $candidates[0];
            foreach ($candidates as $candidate) {
                $candidateResults = $this->fetchGeocodingResults($candidate);
                if (!empty($candidateResults)) {
                    $results = $candidateResults;
                    $searchQuery = $candidate;
                    break;
                }
            }

            if ($results === []) {
                $failure = [
                    'resolved' => false,
                    'error' => sprintf("No geographic coordinates found on Open-Meteo for '%s'.", $cleaned),
                ];
                self::$memoryCache[$cacheKey] = $failure;
                return $failure;
            }

            // Find best matching candidate against region/country
            $bestMatch = $this->selectBestMatch($results, $regionHints, $householdTimezone);
            if ($bestMatch === null) {
                $failure = [
                    'resolved' => false,
                    'error' => sprintf("No matching location in the expected region/country found for '%s'.", $cleaned),
                ];
                self::$memoryCache[$cacheKey] = $failure;
                return $failure;
            }

            $resolved = [
                'resolved' => true,
                'latitude' => (float) $bestMatch['latitude'],
                'longitude' => (float) $bestMatch['longitude'],
                'name' => (string) ($bestMatch['name'] ?? $searchQuery),
                'admin1' => isset($bestMatch['admin1']) ? (string) $bestMatch['admin1'] : null,
                'country' => isset($bestMatch['country']) ? (string) $bestMatch['country'] : null,
                'country_code' => isset($bestMatch['country_code']) ? (string) $bestMatch['country_code'] : null,
                'timezone' => isset($bestMatch['timezone']) ? (string) $bestMatch['timezone'] : $householdTimezone,
            ];

            self::$memoryCache[$cacheKey] = $resolved;
            $this->writeToDiskCache($cacheKey, $resolved);

            return $resolved;
        } catch (Throwable $e) {
            return [
                'resolved' => false,
                'error' => sprintf('Open-Meteo geocoding request failed: %s', $e->getMessage()),
            ];
        }
    }

    /**
     * Parse location string into clean search candidate queries and region hints.
     * e.g. "H.no 4/111, Kodumur, Kurnool, Andhra Pradesh" -> [["Kodumur", "Kurnool"], ["kurnool", "andhra pradesh"]]
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function parseLocationQuery(string $raw): array
    {
        $normalizedRaw = str_replace(['/', '|', ';'], ',', $raw);
        $rawParts = array_map('trim', explode(',', $normalizedRaw));
        $candidates = [];
        $hints = [];

        foreach ($rawParts as $part) {
            if ($part === '') {
                continue;
            }

            // Clean parentheses & postal pin codes
            $cleanPart = trim((string) preg_replace('/\s*\([^)]*\)/', '', $part));
            $cleanPart = trim((string) preg_replace('/\b\d{6}\b/', '', $cleanPart));

            $isStreetNoise = (bool) preg_match('/^(h\.?\s*no|plot|door|d\.?\s*no|near|flat|shop|bldg|building|road|street|opp|opposite)\b/i', $cleanPart)
                || preg_match('/^\d+[\/\-]/', $cleanPart);

            if ($cleanPart !== '' && !$isStreetNoise) {
                $candidates[] = $cleanPart;
            }

            $hint = strtolower($cleanPart !== '' ? $cleanPart : $part);
            $hints[] = $hint;
            if ($hint === 'ap' || $hint === 'a.p.') {
                $hints[] = 'andhra pradesh';
            } elseif ($hint === 'ts' || $hint === 't.s.') {
                $hints[] = 'telangana';
            } elseif ($hint === 'ka') {
                $hints[] = 'karnataka';
            } elseif ($hint === 'tn') {
                $hints[] = 'tamil nadu';
            }
        }

        if (empty($candidates)) {
            $candidates[] = $rawParts[0];
        }

        return [$candidates, array_values(array_unique($hints))];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchGeocodingResults(string $query): array
    {
        $url = self::GEOCODING_URL . '?' . http_build_query([
            'name' => $query,
            'count' => 10,
            'language' => 'en',
            'format' => 'json',
        ]);

        if ($this->httpTransport !== null) {
            $raw = ($this->httpTransport)($url);
        } else {
            $raw = $this->executeCurl($url);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return [];
        }

        return $data['results'];
    }

    /**
     * Score and select best matching result.
     *
     * @param list<array<string, mixed>> $results
     * @param list<string> $regionHints
     * @return array<string, mixed>|null
     */
    private function selectBestMatch(array $results, array $regionHints, string $householdTimezone): ?array
    {
        $isIndiaHousehold = $householdTimezone === 'Asia/Kolkata'
            || in_array('india', $regionHints, true)
            || in_array('in', $regionHints, true);

        $scored = [];

        foreach ($results as $item) {
            $score = 0;
            $countryCode = strtoupper((string) ($item['country_code'] ?? ''));
            $admin1 = strtolower((string) ($item['admin1'] ?? ''));
            $admin2 = strtolower((string) ($item['admin2'] ?? ''));

            // Country match
            if ($isIndiaHousehold) {
                if ($countryCode === 'IN') {
                    $score += 100;
                } else {
                    $score -= 200; // Penalize foreign results when household is in India
                }
            }

            // Region hints matching (e.g. Andhra Pradesh, Kurnool, Telangana)
            foreach ($regionHints as $hint) {
                if ($admin1 !== '' && (str_contains($admin1, $hint) || str_contains($hint, $admin1))) {
                    $score += 50;
                }
                if ($admin2 !== '' && (str_contains($admin2, $hint) || str_contains($hint, $admin2))) {
                    $score += 40;
                }
            }

            // Timezone alignment
            $itemTimezone = (string) ($item['timezone'] ?? '');
            if ($itemTimezone !== '' && $itemTimezone === $householdTimezone) {
                $score += 25;
            }

            // Population / feature importance
            if (isset($item['population']) && is_numeric($item['population']) && $item['population'] > 0) {
                $score += 5;
            }

            $scored[] = ['score' => $score, 'item' => $item];
        }

        if ($scored === []) {
            return null;
        }

        // Sort descending by score
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        // Must have non-negative score for valid match
        return $scored[0]['score'] >= 0 ? $scored[0]['item'] : null;
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
            throw new RuntimeException('Empty response from Open-Meteo geocoding service');
        }

        return $response;
    }

    private function diskCachePath(string $key): string
    {
        $hash = md5($key);
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spms_geocoding_cache';
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

        // Valid for 7 days
        if ((time() - filemtime($file)) > (7 * 86400)) {
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
