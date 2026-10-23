<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Intelligence\Detectors\WeatherConditionDetector;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightTaxonomy;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * F6/W1 — Unit tests for WeatherConditionDetector.
 *
 * Verifies detection of:
 * - WEATHER_PLANNING_WINDOW (clear conditions, high DNI)
 * - CLOUDY_PRODUCTION_RISK (dense cloud cover >65%, low DNI)
 * - WEATHER_VARIABILITY_RISK (variable clouds 35-70%, high precip prob)
 * - WEATHER_DATA_UNAVAILABLE (stale/fallback/missing weather)
 * - Respects PROVENANCE: PREDICTED vs ACTUAL vs UNAVAILABLE
 * - Never converts unavailable weather into sunny conditions
 */
final class WeatherConditionDetectorTest
{
    /**
     * @return array<int, TestResult>
     */
    public static function run(): array
    {
        return [
            TestResult::check('WeatherConditionDetector detects clear planning window with high irradiance', function (): void {
                $detector = new WeatherConditionDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-sunny-01',
                    'weather' => [
                        'status' => 'ACTIVE',
                        'data_nature' => 'PREDICTED',
                        'condition' => 'Clear sky',
                        'solar_impact' => 'OPTIMAL',
                        'cloud_cover_pct' => 10,
                        'direct_normal_irradiance' => 750.0,
                        'daylight_hours' => 11.5,
                        'daylight_outlook' => 'Clear conditions favorable for midday solar generation.',
                        'forecast_available' => true,
                        'precipitation_probability' => 5,
                        'provenance' => [
                            'provider' => 'Open-Meteo NWP',
                            'forecast_reference_time' => '2026-10-21 06:00:00',
                        ],
                    ],
                ]);

                $candidates = $detector->detect($input);
                Assert::same(1, count($candidates));
                Assert::same(InsightTaxonomy::TYPE_WEATHER_PLANNING_WINDOW, $candidates[0]->type);
                Assert::same('Clear Skies Expected to Support Solar Production', $candidates[0]->title);
                Assert::true(in_array('weather', $candidates[0]->evidenceDomains, true));
                Assert::true(in_array('PREDICTED', $candidates[0]->natures, true));
                Assert::same('MODERATE', $candidates[0]->significanceHint);
            }),

            TestResult::check('WeatherConditionDetector detects cloudy production risk when daytime cloud cover is dense', function (): void {
                $detector = new WeatherConditionDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-cloudy-01',
                    'weather' => [
                        'status' => 'ACTIVE',
                        'data_nature' => 'PREDICTED',
                        'condition' => 'Overcast',
                        'solar_impact' => 'HEAVILY_REDUCED',
                        'cloud_cover_pct' => 85,
                        'direct_normal_irradiance' => 120.0,
                        'daylight_hours' => 11.5,
                        'daylight_outlook' => 'Dense cloud cover likely to suppress daytime solar output.',
                        'forecast_available' => true,
                        'precipitation_probability' => 10,
                        'provenance' => [
                            'provider' => 'Open-Meteo NWP',
                            'forecast_reference_time' => '2026-10-21 06:00:00',
                        ],
                    ],
                ]);

                $candidates = $detector->detect($input);
                Assert::same(1, count($candidates));
                Assert::same(InsightTaxonomy::TYPE_CLOUDY_PRODUCTION_RISK, $candidates[0]->type);
                Assert::same('Cloudier Conditions May Reduce Solar Production', $candidates[0]->title);
                Assert::same('HIGH', $candidates[0]->significanceHint);
                Assert::true(in_array('weather', $candidates[0]->evidenceDomains, true));
            }),

            TestResult::check('WeatherConditionDetector detects variability risk under scattered cloud conditions', function (): void {
                $detector = new WeatherConditionDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-variable-01',
                    'weather' => [
                        'status' => 'ACTIVE',
                        'data_nature' => 'PREDICTED',
                        'condition' => 'Scattered clouds',
                        'solar_impact' => 'MODERATELY_REDUCED',
                        'cloud_cover_pct' => 50,
                        'direct_normal_irradiance' => 380.0,
                        'daylight_hours' => 11.5,
                        'daylight_outlook' => 'Intermittent clouds and possible showers.',
                        'forecast_available' => true,
                        'precipitation_probability' => 65,
                        'provenance' => [
                            'provider' => 'Open-Meteo NWP',
                        ],
                    ],
                ]);

                $candidates = $detector->detect($input);
                Assert::same(1, count($candidates));
                Assert::same(InsightTaxonomy::TYPE_WEATHER_VARIABILITY_RISK, $candidates[0]->type);
                Assert::same('Variable Weather Conditions Expected', $candidates[0]->title);
                Assert::same('MODERATE', $candidates[0]->significanceHint);
            }),

            TestResult::check('WeatherConditionDetector reports data limitation when weather is stale, degraded, or fallback', function (): void {
                $detector = new WeatherConditionDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-unavail-01',
                    'weather' => [
                        'status' => 'FALLBACK',
                        'data_nature' => 'ESTIMATED',
                        'condition' => 'Fallback baseline',
                        'solar_impact' => 'UNKNOWN',
                        'cloud_cover_pct' => null,
                        'daylight_outlook' => 'Weather forecast unavailable; fallback clear-sky baseline active.',
                        'forecast_available' => false,
                        'limitations' => ['Weather service provider timed out; falling back to clear-sky geometry.'],
                        'provenance' => [
                            'provider' => 'FallbackClearSkyModel',
                        ],
                    ],
                ]);

                $candidates = $detector->detect($input);
                Assert::same(1, count($candidates));
                Assert::same(InsightTaxonomy::TYPE_WEATHER_DATA_UNAVAILABLE, $candidates[0]->type);
                Assert::same('Atmospheric Weather Forecast Unavailable', $candidates[0]->title);
                Assert::same('LOW', $candidates[0]->significanceHint);
                Assert::same([], $candidates[0]->natures);
                Assert::false(in_array('ACTUAL', $candidates[0]->natures, true));
                Assert::true(in_array('UNAVAILABLE', $candidates[0]->statuses, true));
            }),

            TestResult::check('WeatherConditionDetector returns empty candidate array when weather evidence is completely absent', function (): void {
                $detector = new WeatherConditionDetector();
                $input = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-empty-01',
                ]);

                $candidates = $detector->detect($input);
                Assert::same([], $candidates);
            }),
        ];
    }
}
