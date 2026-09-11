<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * F6 — Recurring solar pattern detector.
 *
 * Reports a stable solar production band when at least three observed
 * periods stay within a tight spread of their mean. Thresholds are
 * disclosed v1 heuristics (docs/10 D-044).
 */
final class SolarPatternDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'SOLAR_PATTERN_DETECTOR_V1';
    public const MIN_PERIODS = 3;
    public const BAND_RELATIVE = 0.20;
    public const MIN_MEAN_KWH = 1.0;

    public function detect(InsightEvidenceInput $evidence): array
    {
        $periods = $evidence->solarPeriods();
        if (count($periods) < self::MIN_PERIODS) {
            return [];
        }

        $values = array_map(static fn (array $record): float => (float) $record['value_kwh'], $periods);
        $mean = array_sum($values) / count($values);
        $spread = max($values) - min($values);

        if ($mean < self::MIN_MEAN_KWH || ($mean > 0.0 && $spread > self::BAND_RELATIVE * $mean)) {
            return [];
        }

        $first = $periods[0];
        $last = $periods[count($periods) - 1];

        $resolutions = array_map(static fn (array $record): ?string => $record['period']['resolution'] ?? null, $periods);
        $resolution = count(array_unique($resolutions)) === 1 ? $resolutions[0] : null;

        $facts = [
            sprintf('Compared %d solar periods from %s to %s.', count($periods), EvidenceFormat::periodLabel($first['period']), EvidenceFormat::periodLabel($last['period'])),
            sprintf('Mean generation: %s. Spread: %s (%s of the mean).', EvidenceFormat::kwh($mean), EvidenceFormat::kwh($spread), $mean > 0.0 ? EvidenceFormat::percent($spread / $mean) : 'n/a'),
            sprintf('Applied v1 thresholds: at least %d periods, spread within %s of the mean, mean at least %s.', self::MIN_PERIODS, EvidenceFormat::percent(self::BAND_RELATIVE), EvidenceFormat::kwh(self::MIN_MEAN_KWH)),
        ];

        $perPeriodFacts = [];
        foreach ($periods as $record) {
            $perPeriodFacts[] = sprintf(
                'Period %s: %s (%s/%s).',
                EvidenceFormat::periodLabel($record['period']),
                EvidenceFormat::kwh((float) $record['value_kwh']),
                $record['data_nature'],
                $record['data_status']
            );
        }

        return [
            new DetectedCandidate(
                type: InsightTaxonomy::TYPE_RECURRING_SOLAR_PATTERN,
                title: 'Recurring stable solar pattern',
                summary: sprintf(
                    'Solar generation stayed within a stable band around %s across %d observed periods.',
                    EvidenceFormat::kwh($mean),
                    count($periods)
                ),
                affectedPeriod: [
                    'start' => $first['period']['start'] ?? null,
                    'end' => $last['period']['end'] ?? null,
                    'resolution' => $resolution,
                ],
                supportingFacts: array_merge($facts, $perPeriodFacts),
                limitations: [
                    'A stable band is an observation about the reported values only; it does not predict future production.',
                ],
                evidenceSummary: sprintf('The %d most recent solar generation period records.', count($periods)),
                explanation: sprintf(
                    'Across the %d observed solar periods the spread between the highest and lowest reported values stayed within %s of the mean. The pattern is derived from reported values and keeps their reported data nature.',
                    count($periods),
                    EvidenceFormat::percent(self::BAND_RELATIVE)
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['solar'],
                natures: array_map(static fn (array $record): ?string => $record['data_nature'], $periods),
                statuses: array_map(static fn (array $record): ?string => $record['data_status'], $periods),
                qualities: array_map(static fn (array $record): ?string => $record['quality'], $periods),
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::MODERATE,
                comparisonCount: count($periods)
            )
        ];
    }
}
