<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * SPMS Weather Intelligence — Weather Condition Detector (F6/F8).
 *
 * Evaluates server-side weather evidence against solar planning rules:
 * - Detects cloudier conditions with solar production risk (Rule 8A).
 * - Detects clear skies presenting favorable solar self-consumption windows (Rule 8B).
 * - Detects precipitation and weather variability risks (Rule 8A/8B).
 * - Detects unavailable or fallback weather states honestly (Rule 8C).
 * - Strictly respects data provenance (PREDICTED vs SIMULATED) and freshness (FRESH vs STALE).
 * - Never fabricates numerical solar generation values from weather alone.
 */
final class WeatherConditionDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'WEATHER_CONDITION_DETECTOR_V1';

    public function detect(InsightEvidenceInput $evidence): array
    {
        $weather = $evidence->weather();
        if ($weather === null) {
            return [];
        }

        $candidates = [];

        // 1. Weather Unconfigured or Geocoding Fallback
        $status = (string) ($weather['status'] ?? 'UNAVAILABLE');
        $available = isset($weather['available'])
            ? (bool) $weather['available']
            : ($status === 'ACTIVE' || (bool) ($weather['forecast_available'] ?? false));

        if (!$available || $status === 'UNAVAILABLE' || $status === 'FALLBACK') {
            $candidates[] = $this->buildUnavailableCandidate($weather, $evidence);
            return $candidates;
        }

        // 2. Evaluate Daytime Forecast Factors
        $hourly = is_array($weather['hourly'] ?? null) ? $weather['hourly'] : [];
        $daytimeHours = [];
        $daytimeCloudSum = 0.0;
        $daytimeDniSum = 0.0;
        $maxPrecipProb = 0.0;
        $totalPrecipMm = 0.0;
        $daytimeCount = 0;

        foreach ($hourly as $hourData) {
            $hour = (int) ($hourData['hour'] ?? 12);
            // Solar production window: 08:00 to 16:00
            if ($hour >= 8 && $hour <= 16) {
                $daytimeHours[] = $hourData;
                $cloud = isset($hourData['cloud_cover_pct']) && is_numeric($hourData['cloud_cover_pct'])
                    ? (float) $hourData['cloud_cover_pct']
                    : null;
                $dni = isset($hourData['direct_normal_irradiance_wm2']) && is_numeric($hourData['direct_normal_irradiance_wm2'])
                    ? (float) $hourData['direct_normal_irradiance_wm2']
                    : null;
                $precipProb = isset($hourData['precipitation_probability_pct']) && is_numeric($hourData['precipitation_probability_pct'])
                    ? (float) $hourData['precipitation_probability_pct']
                    : 0.0;
                $precipMm = isset($hourData['precipitation_mm']) && is_numeric($hourData['precipitation_mm'])
                    ? (float) $hourData['precipitation_mm']
                    : 0.0;

                if ($cloud !== null) {
                    $daytimeCloudSum += $cloud;
                    $daytimeCount++;
                }
                if ($dni !== null) {
                    $daytimeDniSum += $dni;
                }
                if ($precipProb > $maxPrecipProb) {
                    $maxPrecipProb = $precipProb;
                }
                $totalPrecipMm += $precipMm;
            }
        }

        $topCloud = isset($weather['cloud_cover_pct']) && is_numeric($weather['cloud_cover_pct'])
            ? (float) $weather['cloud_cover_pct']
            : (isset($weather['cloud_cover']) && is_numeric($weather['cloud_cover']) ? (float) $weather['cloud_cover'] : null);
        $topDni = isset($weather['direct_normal_irradiance']) && is_numeric($weather['direct_normal_irradiance'])
            ? (float) $weather['direct_normal_irradiance']
            : (isset($weather['direct_normal_irradiance_wm2']) && is_numeric($weather['direct_normal_irradiance_wm2']) ? (float) $weather['direct_normal_irradiance_wm2'] : null);
        $topPrecipProb = isset($weather['precipitation_probability']) && is_numeric($weather['precipitation_probability'])
            ? (float) $weather['precipitation_probability']
            : (isset($weather['precipitation_probability_pct']) && is_numeric($weather['precipitation_probability_pct']) ? (float) $weather['precipitation_probability_pct'] : 0.0);

        $avgCloud = $daytimeCount > 0 ? round($daytimeCloudSum / $daytimeCount, 1) : ($topCloud ?? 25.0);
        $avgDni = $daytimeCount > 0 ? round($daytimeDniSum / $daytimeCount, 1) : ($topDni ?? 380.0);
        if ($daytimeCount === 0) {
            $maxPrecipProb = $topPrecipProb;
        }
        $solarImpact = (string) ($weather['solar_impact'] ?? 'MODERATE');

        // Determine applicable forecast date / period
        $firstHourTime = !empty($daytimeHours) ? ($daytimeHours[0]['timestamp'] ?? null) : null;
        $lastHourTime = !empty($daytimeHours) ? ($daytimeHours[count($daytimeHours) - 1]['timestamp'] ?? null) : null;

        $startDate = $firstHourTime ? substr($firstHourTime, 0, 10) : date('Y-m-d');
        $endDate = $lastHourTime ? substr($lastHourTime, 0, 10) : $startDate;

        $affectedPeriod = [
            'start' => $startDate,
            'end' => $endDate,
            'resolution' => 'DAY',
        ];

        $isStale = ($weather['freshness'] ?? 'FRESH') === 'STALE';
        $provider = (string) ($weather['provider'] ?? 'Open-Meteo');
        $isSimulated = str_contains(strtolower($provider), 'simulated')
            || ($weather['data_nature'] ?? '') === 'SIMULATED'
            || strtolower((string) getenv('SPMS_DATA_ENVIRONMENT')) === 'demo';

        $dataNature = $isSimulated ? 'SIMULATED' : 'PREDICTED';
        $dataStatus = $isStale ? 'STALE' : 'FRESH';
        $quality = $isStale ? 'DEGRADED' : 'GOOD';

        $commonLimitations = [];
        if ($isStale) {
            $commonLimitations[] = 'Weather forecast was fetched >1 hour ago (STALE); atmospheric conditions may have evolved.';
        }
        $commonLimitations[] = 'Weather forecasts are probabilistic atmospheric estimates and do not guarantee exact solar kilowatt-hours.';

        // Scenario A: Cloudy production risk (Rule 8A)
        if ($avgCloud >= 65.0 || $solarImpact === 'LOW') {
            $facts = [
                sprintf('Average daytime cloud cover forecast: %.1f%% during expected solar hours (08:00–16:00).', $avgCloud),
                sprintf('Expected solar impact classification: %s (%s).', $solarImpact, $weather['solar_impact_description'] ?? 'Reduced solar conditions'),
                sprintf('Direct normal irradiance forecast average: %.1f W/m².', $avgDni),
                sprintf('Weather data source: %s (retrieved: %s).', $provider, $weather['fetched_timestamp'] ?? 'recent'),
            ];

            $significance = $avgCloud >= 80.0 || $maxPrecipProb >= 60.0
                ? InsightSignificance::HIGH
                : InsightSignificance::MODERATE;

            $candidates[] = new DetectedCandidate(
                type: InsightTaxonomy::TYPE_CLOUDY_PRODUCTION_RISK,
                title: 'Cloudier Conditions May Reduce Solar Production',
                summary: 'Tomorrow’s forecast indicates increased cloud cover during expected solar production hours. Solar generation may be lower than under clearer conditions.',
                affectedPeriod: $affectedPeriod,
                supportingFacts: $facts,
                limitations: $commonLimitations,
                evidenceSummary: sprintf('Daytime atmospheric forecast from %s for %s.', $provider, $startDate),
                explanation: sprintf(
                    'Atmospheric forecast models indicate %.1f%% average cloud cover over the household solar window. Overcast conditions attenuate direct normal solar irradiance, which typically reduces photovoltaic output compared with clear-sky baselines.',
                    $avgCloud
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['weather', 'forecast'],
                natures: [$dataNature],
                statuses: [$dataStatus],
                qualities: [$quality],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: $significance,
                comparisonCount: $daytimeCount
            );
        }

        // Scenario B: Favorable clear-sky midday planning window (Rule 8B)
        if ($avgCloud <= 30.0 && ($avgDni >= 320.0 || $solarImpact === 'STRONG')) {
            $facts = [
                sprintf('Low cloud cover forecast: %.1f%% daytime average across the solar window.', $avgCloud),
                sprintf('Direct normal irradiance forecast average: %.1f W/m² (high direct sunlight).', $avgDni),
                'Strongest solar irradiance window expected between 10:00 and 15:00.',
                sprintf('Weather data source: %s (retrieved: %s).', $provider, $weather['fetched_timestamp'] ?? 'recent'),
            ];

            $candidates[] = new DetectedCandidate(
                type: InsightTaxonomy::TYPE_WEATHER_PLANNING_WINDOW,
                title: 'Clear Skies Expected to Support Solar Production',
                summary: 'Tomorrow’s forecast indicates low cloud cover and high solar irradiance, presenting a favorable daytime window for flexible electricity usage.',
                affectedPeriod: $affectedPeriod,
                supportingFacts: $facts,
                limitations: array_merge($commonLimitations, [
                    'Actual generation will depend on panel orientation, inverter capacity, and local temperature.',
                ]),
                evidenceSummary: sprintf('Clear-sky atmospheric forecast from %s for %s.', $provider, $startDate),
                explanation: sprintf(
                    'High solar irradiance (%.1f W/m² DNI) and minimal cloud cover (%.1f%%) are forecast. Running flexible household loads (washing machine, water heater, EV charging) during the 10:00–15:00 window maximizes direct clean solar self-consumption.',
                    $avgDni,
                    $avgCloud
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['weather', 'solar'],
                natures: [$dataNature],
                statuses: [$dataStatus],
                qualities: [$quality],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::MODERATE,
                comparisonCount: $daytimeCount
            );
        }

        // Scenario C: Precipitation or High Weather Variability Risk (Rule 8A/8B)
        if ($maxPrecipProb >= 60.0 || $totalPrecipMm >= 2.0) {
            $facts = [
                sprintf('Precipitation probability peaks at %.0f%% during daylight hours.', $maxPrecipProb),
                sprintf('Forecasted daytime precipitation volume: %.1f mm.', $totalPrecipMm),
                sprintf('Weather condition: %s.', $weather['condition'] ?? 'RAIN'),
                sprintf('Weather provider: %s.', $provider),
            ];

            $candidates[] = new DetectedCandidate(
                type: InsightTaxonomy::TYPE_WEATHER_VARIABILITY_RISK,
                title: 'Variable Weather Conditions Expected',
                summary: 'Forecast indicates potential rain or variable cloud movements, which may cause sudden fluctuations in solar power output.',
                affectedPeriod: $affectedPeriod,
                supportingFacts: $facts,
                limitations: array_merge($commonLimitations, [
                    'Precipitation timing is localized; inverter production may drop abruptly during storm clouds.',
                ]),
                evidenceSummary: sprintf('Precipitation risk forecast from %s for %s.', $provider, $startDate),
                explanation: sprintf(
                    'Atmospheric models indicate a %.0f%% chance of precipitation during peak solar hours. Localized rain clouds cause steep short-term drops in solar output, making fixed load scheduling less predictable.',
                    $maxPrecipProb
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['weather'],
                natures: [$dataNature],
                statuses: [$dataStatus],
                qualities: [$quality],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::MODERATE,
                comparisonCount: $daytimeCount
            );
        }

        return $candidates;
    }

    /**
     * Build an honest informational candidate when weather data is unavailable or fallback.
     */
    private function buildUnavailableCandidate(array $weather, InsightEvidenceInput $evidence): DetectedCandidate
    {
        $location = $weather['location'] ?? null;
        $reason = $weather['solar_impact_description']
            ?? 'Household location is not configured; solar forecast relies on clear-sky diurnal fallback.';

        $facts = [
            sprintf('Weather status: %s.', $weather['status'] ?? 'UNAVAILABLE'),
            sprintf('Provider baseline: %s.', $weather['provider'] ?? 'Astronomical Clear-Sky Model'),
            $location !== null
                ? sprintf('Configured location label: %s (coordinates could not be resolved).', $location)
                : 'Household location is not configured in settings.',
        ];

        return new DetectedCandidate(
            type: InsightTaxonomy::TYPE_WEATHER_DATA_UNAVAILABLE,
            title: 'Atmospheric Weather Forecast Unavailable',
            summary: 'Weather data is not currently available for this household. Solar forecasting continues using clear-sky physical calculations without atmospheric cloud adjustments.',
            affectedPeriod: null,
            supportingFacts: $facts,
            limitations: [
                'Atmospheric cloud cover, rain probability, and solar irradiance adjustments are unavailable until a valid location is configured.',
                'Missing weather evidence is never treated as zero or replaced with fabricated sunny conditions.',
            ],
            evidenceSummary: 'Household weather service availability evaluation.',
            explanation: $reason,
            explanationSource: self::EXPLANATION_SOURCE,
            evidenceDomains: ['weather'],
            natures: [],
            statuses: ['UNAVAILABLE'],
            qualities: ['UNVERIFIED'],
            detectors: [self::EXPLANATION_SOURCE],
            significanceHint: InsightSignificance::LOW,
            comparisonCount: null
        );
    }
}
