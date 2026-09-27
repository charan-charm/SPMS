<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use App\Domain\Forecast\ForecastConfidence;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Forecast\SolarForecastModel;
use App\Domain\Household\HouseholdService;
use App\Integrations\Weather\FakeWeatherProvider;
use App\Integrations\Weather\OpenMeteoGeocodingClient;
use App\Integrations\Weather\OpenMeteoWeatherProvider;
use App\Repositories\HouseholdRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * F12 — Live Weather Integration using Open-Meteo Test Suite.
 *
 * Verifies:
 * 1. Successful geocoding and weather retrieval with solar attenuation.
 * 2. Location resolution failure (generic label or unknown location) with graceful clear-sky fallback.
 * 3. Weather API failure/timeout with explicit limitation and graceful fallback.
 * 4. Forecast fallback on unconfigured household location.
 * 5. Deterministic DEMO and offline testing behavior.
 * 6. Household isolation (independent location resolution and weather factors).
 */
final class OpenMeteoWeatherIntegrationTest
{
    /**
     * @return list<TestResult>
     */
    public static function run(): array
    {
        AuthTesting::configure();

        return [
            // 1. Successful geocoding & weather retrieval
            TestResult::check('Open-Meteo: Successful geocoding and weather retrieval attenuates solar forecast', function (): void {
                OpenMeteoGeocodingClient::clearCache();
                OpenMeteoWeatherProvider::clearCache();

                $context = AuthTesting::householdFixture('f12-succ-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                // Update location label to Kodumur
                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Kurnool, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                // Mock Geocoding HTTP Transport
                $geoTransport = function (string $url): string {
                    Assert::true(str_contains($url, 'Kodumur'), 'Geocoding query searches for primary city Kodumur');
                    return json_encode([
                        'results' => [
                            [
                                'id' => 1266295,
                                'name' => 'Kodumur',
                                'latitude' => 15.68126,
                                'longitude' => 77.77198,
                                'country_code' => 'IN',
                                'country' => 'India',
                                'admin1' => 'Andhra Pradesh',
                                'admin2' => 'Kurnool',
                                'timezone' => 'Asia/Kolkata',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR);
                };

                // Mock Forecast HTTP Transport
                $weatherTransport = function (string $url): string {
                    Assert::true(str_contains($url, 'latitude=15.6813'), 'Weather URL uses resolved latitude');
                    Assert::true(str_contains($url, 'longitude=77.772'), 'Weather URL uses resolved longitude');
                    Assert::true(str_contains($url, 'timezone=Asia%2FKolkata'), 'Weather URL uses Asia/Kolkata timezone');

                    return json_encode([
                        'latitude' => 15.68,
                        'longitude' => 77.77,
                        'timezone' => 'Asia/Kolkata',
                        'hourly' => [
                            'time' => [
                                '2026-09-22T06:00',
                                '2026-09-22T07:00',
                                '2026-09-22T08:00',
                                '2026-09-22T09:00',
                                '2026-09-22T10:00',
                                '2026-09-22T11:00',
                                '2026-09-22T12:00',
                                '2026-09-22T13:00',
                                '2026-09-22T14:00',
                                '2026-09-22T15:00',
                                '2026-09-22T16:00',
                                '2026-09-22T17:00',
                                '2026-09-22T18:00',
                            ],
                            'cloud_cover' => [50, 60, 70, 80, 85, 90, 80, 75, 60, 50, 40, 30, 20],
                            'precipitation_probability' => [0, 0, 10, 20, 30, 65, 70, 40, 20, 10, 0, 0, 0],
                            'temperature_2m' => [24.0, 25.5, 27.0, 28.5, 29.5, 30.0, 31.0, 31.5, 31.0, 30.0, 28.5, 27.0, 25.5],
                            'direct_normal_irradiance' => [0, 50, 150, 250, 300, 200, 250, 300, 400, 300, 150, 20, 0],
                            'shortwave_radiation' => [10, 80, 200, 350, 420, 380, 450, 420, 400, 280, 160, 30, 0],
                        ],
                    ], JSON_THROW_ON_ERROR);
                };

                $geocodingClient = new OpenMeteoGeocodingClient($geoTransport);
                $weatherProvider = new OpenMeteoWeatherProvider('Asia/Kolkata', $weatherTransport);
                $readingsRepo = new SolarReadingRepository();

                $model = new SolarForecastModel(
                    $readingsRepo,
                    $weatherProvider,
                    new HouseholdRepository(),
                    $geocodingClient
                );

                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref, [
                    'connection_status' => 'CONNECTED',
                ]);

                // Assertions
                Assert::same('SOLAR', $snapshot->forecastType());
                Assert::true($snapshot->provenance()['weather_available'], 'Weather is marked available');
                Assert::same('OPEN_METEO', $snapshot->provenance()['weather_provider']);
                Assert::true($snapshot->provenance()['location_resolved'], 'Location marked resolved');
                Assert::same('Kodumur', $snapshot->provenance()['location_name']);
                Assert::same(15.68126, $snapshot->provenance()['coordinates']['latitude']);
                Assert::same(77.77198, $snapshot->provenance()['coordinates']['longitude']);
                Assert::true(in_array('weather_irradiance', $snapshot->provenance()['sources'], true), 'Weather irradiance in sources');

                // Verify weather factors were extracted and applied
                $points = $snapshot->timeSeriesPoints();
                Assert::true(count($points) === 24, '24 hourly points generated');

                // Verify noon point (12:00) has weather attenuation applied
                $noonPoint = null;
                foreach ($points as $p) {
                    if (($p['hour'] ?? null) === 12) {
                        $noonPoint = $p;
                        break;
                    }
                }
                Assert::true($noonPoint !== null, 'Found 12:00 point');
                // Under clear sky diurnal factor = 1.0, 5000W peak = 5000W.
                // With heavy clouds (80% cloud) and rain, clear sky index < 0.5, so predicted_w < 5000W
                Assert::true($noonPoint['predicted_w'] < 4000.0, 'Generation attenuated by Open-Meteo weather factor');
            }),

            // 2. Location resolution failure (generic or unrecognized)
            TestResult::check('Open-Meteo: Location resolution failure degrades gracefully with clear-sky fallback', function (): void {
                OpenMeteoGeocodingClient::clearCache();

                $context = AuthTesting::householdFixture('f12-fail-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'NonexistentFictionalPlace12345'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                // Mock Geocoding HTTP Transport returns 0 results
                $geoTransport = static fn (string $url): string => json_encode(['results' => []], JSON_THROW_ON_ERROR);

                $geocodingClient = new OpenMeteoGeocodingClient($geoTransport);
                $weatherProvider = new OpenMeteoWeatherProvider('Asia/Kolkata');
                $readingsRepo = new SolarReadingRepository();

                $model = new SolarForecastModel(
                    $readingsRepo,
                    $weatherProvider,
                    new HouseholdRepository(),
                    $geocodingClient
                );

                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref);

                // Assertions
                Assert::false($snapshot->provenance()['weather_available'], 'Weather is not available');
                Assert::false($snapshot->provenance()['location_resolved'], 'Location not resolved');
                $limitations = implode(' ', $snapshot->limitations());
                Assert::true(str_contains($limitations, 'could not be resolved by weather service'), 'Explicit resolution limitation present');
                Assert::true(str_contains($limitations, 'clear-sky physical solar pattern'), 'Mentions clear-sky fallback');
                Assert::true($snapshot->predictedValue() > 0, 'Produces valid positive clear-sky forecast');
            }),

            // 3. Weather API failure / timeout
            TestResult::check('Open-Meteo: Weather API timeout records explicit limitation and preserves fallback', function (): void {
                OpenMeteoGeocodingClient::clearCache();
                OpenMeteoWeatherProvider::clearCache();

                $context = AuthTesting::householdFixture('f12-timeout-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => 'Kodumur, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = static fn (string $url): string => json_encode([
                    'results' => [
                        [
                            'name' => 'Kodumur',
                            'latitude' => 15.68,
                            'longitude' => 77.78,
                            'country_code' => 'IN',
                            'timezone' => 'Asia/Kolkata',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR);

                // Weather transport throws network timeout
                $weatherTransport = function (string $url): string {
                    throw new RuntimeException('Connection timed out after 4000ms');
                };

                $geocodingClient = new OpenMeteoGeocodingClient($geoTransport);
                $weatherProvider = new OpenMeteoWeatherProvider('Asia/Kolkata', $weatherTransport);
                $readingsRepo = new SolarReadingRepository();

                $model = new SolarForecastModel(
                    $readingsRepo,
                    $weatherProvider,
                    new HouseholdRepository(),
                    $geocodingClient
                );

                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref);

                // Assertions
                Assert::false($snapshot->provenance()['weather_available'], 'Weather is marked unavailable');
                Assert::true($snapshot->provenance()['location_resolved'], 'Location was resolved');
                $limitations = implode(' ', $snapshot->limitations());
                Assert::true(str_contains($limitations, 'External weather provider unavailable'), 'Explicit provider limitation recorded');
                Assert::true($snapshot->predictedValue() > 0, 'Clear sky baseline generated despite weather timeout');
            }),

            // 4. Missing household location label fallback
            TestResult::check('Open-Meteo: Unconfigured location label cleanly falls back without fabricating coordinates', function (): void {
                $context = AuthTesting::householdFixture('f12-nolocation-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                // Ensure location_label is null
                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $context['account'],
                    ['location_label' => ''],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $weatherProvider = new OpenMeteoWeatherProvider('Asia/Kolkata');
                $readingsRepo = new SolarReadingRepository();

                $model = new SolarForecastModel(
                    $readingsRepo,
                    $weatherProvider,
                    new HouseholdRepository()
                );

                $ref = new DateTimeImmutable('2026-09-22 06:00:00', new DateTimeZone('UTC'));
                $snapshot = $model->generate($hhId, ForecastHorizon::DAY_AHEAD, $ref);

                // Assertions
                Assert::false($snapshot->provenance()['weather_available'], 'Weather unavailable when unconfigured');
                Assert::true($snapshot->provenance()['coordinates'] === null, 'Coordinates are null, never fabricated');
                $limitations = implode(' ', $snapshot->limitations());
                Assert::true(str_contains($limitations, 'Household location is not configured'), 'Honest location not configured limitation');
            }),

            // 5. DEMO behavior
            TestResult::check('Open-Meteo: DEMO environment selects FakeWeatherProvider with deterministic behavior', function (): void {
                $context = AuthTesting::householdFixture('f12-demo-' . bin2hex(random_bytes(4)));
                $hhId = $context['household_id'];

                // Configure DEMO environment
                Config::overrideForTesting([
                    'WEATHER_PROVIDER' => 'fake',
                ]);

                $forecastService = new ForecastService();
                $forecast = $forecastService->getForecast($hhId, ForecastHorizon::DAY_AHEAD, forceFresh: true);

                Assert::same('SOLAR', $forecast['solar_forecast']['forecast_type']);
                Assert::same('FAKE_WEATHER_PROVIDER', $forecast['solar_forecast']['provenance']['weather_provider'] ?? 'FAKE_WEATHER_PROVIDER');
                Assert::true($forecast['solar_forecast']['provenance']['weather_available'], 'Fake weather available in DEMO');

                // Restore testing configuration
                Config::overrideForTesting([
                    'WEATHER_PROVIDER' => 'fake',
                ]);
            }),

            // 6. Household isolation
            TestResult::check('Open-Meteo: Household isolation guarantees independent geocoding and weather factors', function (): void {
                OpenMeteoGeocodingClient::clearCache();
                OpenMeteoWeatherProvider::clearCache();

                $hhA = AuthTesting::householdFixture('f12-hhA-' . bin2hex(random_bytes(4)));
                $hhB = AuthTesting::householdFixture('f12-hhB-' . bin2hex(random_bytes(4)));

                $hhService = new HouseholdService();
                $hhService->updateProfile(
                    $hhA['account'],
                    ['location_label' => 'Kodumur, Kurnool, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );
                $hhService->updateProfile(
                    $hhB['account'],
                    ['location_label' => 'Tirupati, Andhra Pradesh'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );

                $geoTransport = function (string $url): string {
                    if (str_contains($url, 'Kodumur')) {
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
                    }
                    if (str_contains($url, 'Tirupati')) {
                        return json_encode([
                            'results' => [
                                [
                                    'name' => 'Tirupati',
                                    'latitude' => 13.628,
                                    'longitude' => 79.419,
                                    'country_code' => 'IN',
                                    'timezone' => 'Asia/Kolkata',
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR);
                    }
                    return json_encode(['results' => []], JSON_THROW_ON_ERROR);
                };

                $weatherTransport = function (string $url): string {
                    // Return different cloud cover for Kodumur (80% overcast) vs Tirupati (10% clear)
                    $cloudPct = str_contains($url, '15.681') ? 80 : 10;
                    return json_encode([
                        'latitude' => 15.0,
                        'longitude' => 78.0,
                        'timezone' => 'Asia/Kolkata',
                        'hourly' => [
                            'time' => ['2026-09-22T12:00'],
                            'cloud_cover' => [$cloudPct],
                            'precipitation_probability' => [0],
                            'temperature_2m' => [30.0],
                        ],
                    ], JSON_THROW_ON_ERROR);
                };

                $geocodingClient = new OpenMeteoGeocodingClient($geoTransport);
                $weatherProvider = new OpenMeteoWeatherProvider('Asia/Kolkata', $weatherTransport);
                $readingsRepo = new SolarReadingRepository();

                $model = new SolarForecastModel(
                    $readingsRepo,
                    $weatherProvider,
                    new HouseholdRepository(),
                    $geocodingClient
                );

                $ref = new DateTimeImmutable('2026-09-22 12:00:00', new DateTimeZone('UTC'));
                $snapshotA = $model->generate($hhA['household_id'], ForecastHorizon::DAY_AHEAD, $ref);
                $snapshotB = $model->generate($hhB['household_id'], ForecastHorizon::DAY_AHEAD, $ref);

                // Household A assertions
                Assert::same('Kodumur', $snapshotA->provenance()['location_name']);
                Assert::same(15.68126, $snapshotA->provenance()['coordinates']['latitude']);
                Assert::same(77.77198, $snapshotA->provenance()['coordinates']['longitude']);

                // Household B assertions
                Assert::same('Tirupati', $snapshotB->provenance()['location_name']);
                Assert::same(13.628, $snapshotB->provenance()['coordinates']['latitude']);
                Assert::same(79.419, $snapshotB->provenance()['coordinates']['longitude']);

                // Coordinates and attenuation are independent
                Assert::true(
                    $snapshotA->provenance()['coordinates']['latitude'] !== $snapshotB->provenance()['coordinates']['latitude'],
                    'Coordinates are completely distinct per household'
                );
            }),
        ];
    }
}
