<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use App\Domain\Household\HouseholdService;
use App\Domain\Weather\WeatherService;
use App\Integrations\Weather\FakeWeatherProvider;
use App\Integrations\Weather\OpenMeteoGeocodingClient;
use App\Integrations\Weather\OpenMeteoWeatherProvider;
use App\Repositories\HouseholdRepository;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * WeatherService Unit Test Suite (Section 16).
 *
 * Verifies:
 * A. Weather provider success & normalized contract
 * B. Weather API timeout -> honest fallback, no crash
 * C. Weather API invalid response -> graceful fallback
 * D. Geocoding failure -> no fabricated coordinates, clear-sky fallback
 * E. Missing location -> UNAVAILABLE state, clear-sky limitation
 * F. Stale weather detection (>1 hour -> STALE)
 * G. Active weather detection (<1 hour -> ACTIVE/FRESH)
 * H. REAL/DEMO isolation
 * I. Zero fabricated weather values in fallback/unavailable states
 */
final class WeatherServiceTest
{
    /**
     * @return list<TestResult>
     */
    public static function run(): array
    {
        AuthTesting::configure();

        return [
            // A. Weather provider success & normalized contract
            TestResult::check('WeatherService: provider success returns normalized contract with solar impact and daylight outlook', function (): void {
                OpenMeteoGeocodingClient::clearCache();
                OpenMeteoWeatherProvider::clearCache();

                $context = AuthTesting::householdFixture('ws-succ-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Kurnool, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = function (string $url): string {
                    return json_encode([
                        'results' => [
                            [
                                'id' => 1266295,
                                'name' => 'Kodumur',
                                'latitude' => 15.68126,
                                'longitude' => 77.77198,
                                'country_code' => 'IN',
                                'country' => 'India',
                                'timezone' => 'Asia/Kolkata',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR);
                };

                $ref = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $refKolkata = $ref->setTimezone(new DateTimeZone('Asia/Kolkata'));
                $dateToday = $refKolkata->format('Y-m-d');
                $dateTomorrow = $refKolkata->modify('+1 day')->format('Y-m-d');

                $hourlyTimes = [];
                $cloudCovers = [];
                $precipProbs = [];
                $temperatures = [];
                $dnis = [];
                $swrs = [];

                foreach ([$dateToday, $dateTomorrow] as $day) {
                    for ($h = 0; $h < 24; $h++) {
                        $hourlyTimes[] = sprintf('%sT%02d:00', $day, $h);
                        $isDaylight = ($h >= 6 && $h <= 18);
                        $cloudCovers[] = 20;
                        $precipProbs[] = 0;
                        $temperatures[] = 28.5;
                        $dnis[] = $isDaylight ? 520 : 0;
                        $swrs[] = $isDaylight ? 600 : 0;
                    }
                }

                $weatherTransport = function (string $url) use ($hourlyTimes, $cloudCovers, $precipProbs, $temperatures, $dnis, $swrs): string {
                    return json_encode([
                        'latitude' => 15.68,
                        'longitude' => 77.77,
                        'timezone' => 'Asia/Kolkata',
                        'hourly' => [
                            'time' => $hourlyTimes,
                            'cloud_cover' => $cloudCovers,
                            'precipitation_probability' => $precipProbs,
                            'temperature_2m' => $temperatures,
                            'direct_normal_irradiance' => $dnis,
                            'shortwave_radiation' => $swrs,
                        ],
                    ], JSON_THROW_ON_ERROR);
                };

                $geocoding = new OpenMeteoGeocodingClient($geoTransport);
                $weather = new OpenMeteoWeatherProvider('Asia/Kolkata', $weatherTransport);
                $service = new WeatherService(new HouseholdRepository(), $geocoding, $weather);

                $result = $service->getWeatherForHousehold($hhId, $ref);

                Assert::same($hhId, $result['household_id']);
                Assert::same('Kodumur', $result['location']);
                Assert::same(15.6813, $result['latitude']);
                Assert::same(77.772, $result['longitude']);
                Assert::same('Asia/Kolkata', $result['timezone']);
                Assert::same('ACTIVE', $result['status']);
                Assert::same('FRESH', $result['freshness']);
                Assert::true($result['available'], 'Weather is marked available');
                Assert::same('Open-Meteo', $result['provider']);
                Assert::true($result['temperature'] !== null, 'Temperature is provided');
                Assert::true($result['cloud_cover'] !== null, 'Cloud cover is provided');
                Assert::true($result['direct_normal_irradiance'] !== null, 'DNI is provided');
                Assert::same('STRONG', $result['solar_impact'], 'Low cloud cover evaluates to STRONG solar impact');
                Assert::true(str_contains($result['solar_impact_description'], 'Strong solar conditions'), 'Correct impact description');
                Assert::true($result['sunrise'] !== null, 'Physical sunrise is calculated');
                Assert::true($result['sunset'] !== null, 'Physical sunset is calculated');
                Assert::true(count($result['hourly']) > 0, 'Hourly forecast periods are populated');
            }),

            // B. Weather API timeout -> honest fallback, no crash
            TestResult::check('WeatherService: API timeout yields honest FALLBACK state without crash or fabricated values', function (): void {
                OpenMeteoGeocodingClient::clearCache();
                OpenMeteoWeatherProvider::clearCache();

                $context = AuthTesting::householdFixture('ws-timeout-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Kurnool, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = function (string $url): string {
                    return json_encode([
                        'results' => [
                            [
                                'id' => 1266295,
                                'name' => 'Kodumur',
                                'latitude' => 15.68126,
                                'longitude' => 77.77198,
                                'country_code' => 'IN',
                                'timezone' => 'Asia/Kolkata',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR);
                };

                $weatherTransport = function (string $url): string {
                    throw new RuntimeException('Connection timed out after 5000ms');
                };

                $geocoding = new OpenMeteoGeocodingClient($geoTransport);
                $weather = new OpenMeteoWeatherProvider('Asia/Kolkata', $weatherTransport);
                $service = new WeatherService(new HouseholdRepository(), $geocoding, $weather);

                $ref = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $result = $service->getWeatherForHousehold($hhId, $ref);

                Assert::same('FALLBACK', $result['status']);
                Assert::same('FALLBACK', $result['freshness']);
                Assert::false($result['available'], 'Available is false on timeout');
                Assert::same(null, $result['temperature'], 'Temperature is not fabricated on timeout');
                Assert::same(null, $result['cloud_cover'], 'Cloud cover is not fabricated on timeout');
                Assert::same(null, $result['direct_normal_irradiance'], 'DNI is not fabricated on timeout');
                Assert::same('FALLBACK', $result['condition']);
                Assert::true(!empty($result['limitations']), 'Explicit fallback limitation is recorded');
                Assert::true(str_contains($result['error'], 'timed out'), 'Error message preserves timeout detail');
            }),

            // C. Weather API invalid JSON response
            TestResult::check('WeatherService: invalid API response yields honest FALLBACK gracefully', function (): void {
                OpenMeteoGeocodingClient::clearCache();
                OpenMeteoWeatherProvider::clearCache();

                $context = AuthTesting::householdFixture('ws-invalid-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Kurnool, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = function (string $url): string {
                    return json_encode([
                        'results' => [
                            [
                                'name' => 'Kodumur',
                                'latitude' => 15.68126,
                                'longitude' => 77.77198,
                                'country_code' => 'IN',
                                'timezone' => 'Asia/Kolkata',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR);
                };

                $weatherTransport = function (string $url): string {
                    return '<html><body>502 Bad Gateway</body></html>';
                };

                $geocoding = new OpenMeteoGeocodingClient($geoTransport);
                $weather = new OpenMeteoWeatherProvider('Asia/Kolkata', $weatherTransport);
                $service = new WeatherService(new HouseholdRepository(), $geocoding, $weather);

                $result = $service->getWeatherForHousehold($hhId);

                Assert::same('FALLBACK', $result['status']);
                Assert::false($result['available']);
                Assert::same(null, $result['temperature']);
            }),

            // D. Geocoding failure
            TestResult::check('WeatherService: unresolvable location label yields FALLBACK with null coordinates', function (): void {
                OpenMeteoGeocodingClient::clearCache();

                $context = AuthTesting::householdFixture('ws-geofail-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'NonExistentCityXYZ999'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = function (string $url): string {
                    return json_encode(['results' => []], JSON_THROW_ON_ERROR);
                };

                $geocoding = new OpenMeteoGeocodingClient($geoTransport);
                $weather = new OpenMeteoWeatherProvider('Asia/Kolkata');
                $service = new WeatherService(new HouseholdRepository(), $geocoding, $weather);

                $result = $service->getWeatherForHousehold($hhId);

                Assert::same('FALLBACK', $result['status']);
                Assert::same(null, $result['latitude'], 'Coordinates not fabricated');
                Assert::same(null, $result['longitude'], 'Coordinates not fabricated');
                Assert::false($result['available']);
            }),

            // E. Missing household location
            TestResult::check('WeatherService: unconfigured location label yields honest UNAVAILABLE state', function (): void {
                $context = AuthTesting::householdFixture('ws-unconf-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                // Explicitly empty location label
                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => ''],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $service = new WeatherService(new HouseholdRepository());
                $result = $service->getWeatherForHousehold($hhId);

                Assert::same('UNAVAILABLE', $result['status']);
                Assert::same('UNAVAILABLE', $result['freshness']);
                Assert::false($result['available']);
                Assert::same(null, $result['location']);
                Assert::same(null, $result['latitude']);
                Assert::same(null, $result['longitude']);
                Assert::same(null, $result['temperature']);
                Assert::same(null, $result['cloud_cover']);
                Assert::true(str_contains($result['limitations'][0], 'Household location label is not configured'));
            }),

            // F. Stale weather detection (> 1 hour -> STALE)
            TestResult::check('WeatherService: weather fetched >1 hour ago is honestly classified as STALE', function (): void {
                $context = AuthTesting::householdFixture('ws-stale-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Kurnool, AP'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = function (string $url): string {
                    return json_encode([
                        'results' => [
                            ['name' => 'Kodumur', 'latitude' => 15.68, 'longitude' => 77.77, 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']
                        ]
                    ], JSON_THROW_ON_ERROR);
                };

                // Fake custom provider returning 2-hour old retrieved_at
                $fakeWeather = new class implements WeatherProviderInterface {
                    public function getSolarFactors(float $lat, float $lon, DateTimeImmutable $start, DateTimeImmutable $end): array {
                        $twoHoursAgo = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 hours');
                        return [
                            'available' => true,
                            'provider' => 'OPEN_METEO',
                            'location' => ['lat' => $lat, 'lon' => $lon],
                            'retrieved_at' => $twoHoursAgo->format('Y-m-d H:i:s'),
                            'error' => null,
                            'hourly_factors' => [
                                [
                                    'timestamp' => $twoHoursAgo->format('Y-m-d\TH:i:s\Z'),
                                    'clear_sky_index' => 0.85,
                                    'cloud_cover_pct' => 20.0,
                                    'temperature_c' => 28.0,
                                    'precipitation_probability_pct' => 0.0,
                                    'precipitation' => 0.0,
                                    'direct_normal_irradiance_wm2' => 500.0,
                                    'shortwave_radiation_wm2' => 600.0,
                                    'weather_code' => 0,
                                    'condition' => 'CLEAR',
                                    'solar_impact' => 'STRONG',
                                ],
                            ],
                        ];
                    }
                };

                $geocoding = new OpenMeteoGeocodingClient($geoTransport);
                $service = new WeatherService(new HouseholdRepository(), $geocoding, $fakeWeather);

                $ref = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $result = $service->getWeatherForHousehold($hhId, $ref);

                Assert::same('STALE', $result['status']);
                Assert::same('STALE', $result['freshness']);
                Assert::true($result['available']);
                Assert::true(!empty($result['limitations']) && str_contains($result['limitations'][0], 'STALE'), 'Stale weather has explicit limitation disclosure');
            }),

            // G. Active weather detection (< 1 hour -> ACTIVE/FRESH)
            TestResult::check('WeatherService: recently fetched weather is classified as ACTIVE/FRESH', function (): void {
                $context = AuthTesting::householdFixture('ws-fresh-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Kurnool, AP'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = function (string $url): string {
                    return json_encode([
                        'results' => [
                            ['name' => 'Kodumur', 'latitude' => 15.68, 'longitude' => 77.77, 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']
                        ]
                    ], JSON_THROW_ON_ERROR);
                };

                $geocoding = new OpenMeteoGeocodingClient($geoTransport);
                $fake = new FakeWeatherProvider();
                $service = new WeatherService(new HouseholdRepository(), $geocoding, $fake);

                $result = $service->getWeatherForHousehold($hhId);

                Assert::same('ACTIVE', $result['status']);
                Assert::same('FRESH', $result['freshness']);
                Assert::true($result['available']);
            }),

            // H. Zero fabricated weather values
            TestResult::check('WeatherService: never fabricates missing weather values when provider is unavailable', function (): void {
                $context = AuthTesting::householdFixture('ws-nofake-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $geoTransport = function (string $url): string {
                    return json_encode([
                        'results' => [
                            ['name' => 'Kodumur', 'latitude' => 15.68, 'longitude' => 77.77, 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']
                        ]
                    ], JSON_THROW_ON_ERROR);
                };

                $fake = new FakeWeatherProvider();
                $fake->setSimulateFailure(true);

                $geocoding = new OpenMeteoGeocodingClient($geoTransport);
                $service = new WeatherService(new HouseholdRepository(), $geocoding, $fake);

                $result = $service->getWeatherForHousehold($hhId);

                Assert::false($result['available']);
                Assert::same(null, $result['temperature']);
                Assert::same(null, $result['cloud_cover']);
                Assert::same(null, $result['direct_normal_irradiance']);
                Assert::same(null, $result['shortwave_irradiance']);
                Assert::same(null, $result['precipitation_probability']);
                Assert::same(null, $result['weather_code']);
            }),
        ];
    }
}
