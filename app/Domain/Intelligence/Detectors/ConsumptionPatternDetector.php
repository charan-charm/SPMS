<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * F6 — Household consumption pattern detector.
 *
 * Two disclosed v1 heuristics (docs/10 D-044):
 * - RECURRING_HIGH_DEMAND: every one of at least three observed periods is
 *   at or above a fixed demand floor.
 * - UNUSUAL_CONSUMPTION_PATTERN: the most recent period deviates materially
 *   from the household's own recurring baseline (mean of the earlier
 *   periods).
 */
final class ConsumptionPatternDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'CONSUMPTION_PATTERN_DETECTOR_V1';
    public const MIN_PERIODS = 3;
    public const HIGH_DEMAND_FLOOR_KWH = 5.0;
    public const UNUSUAL_DEVIATION = 0.40;
    public const UNUSUAL_ABSOLUTE_KWH = 1.0;

    public function detect(InsightEvidenceInput $evidence): array
    {
        $periods = $evidence->consumptionPeriods();
        if (count($periods) < self::MIN_PERIODS) {
            return [];
        }

        return array_merge(
            $this->recurringHighDemand($periods),
            $this->unusualPattern($periods)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $periods
     * @return array<int, DetectedCandidate>
     */
    private function recurringHighDemand(array $periods): array
    {
        $values = array_map(static fn (array $record): float => (float) $record['value_kwh'], $periods);
        $minimum = min($values);

        if ($minimum < self::HIGH_DEMAND_FLOOR_KWH) {
            return [];
        }

        $mean = array_sum($values) / count($values);
        $first = $periods[0];
        $last = $periods[count($periods) - 1];

        $facts = [
            sprintf('All %d observed consumption periods are at or above the %s demand floor.', count($periods), EvidenceFormat::kwh(self::HIGH_DEMAND_FLOOR_KWH)),
            sprintf('Lowest observed period: %s. Mean: %s.', EvidenceFormat::kwh($minimum), EvidenceFormat::kwh($mean)),
        ];

        return [
            new DetectedCandidate(
                type: InsightTaxonomy::TYPE_RECURRING_HIGH_DEMAND,
                title: 'Recurring high household demand',
                summary: sprintf(
                    'Household consumption stayed at or above %s in every one of the %d observed periods.',
                    EvidenceFormat::kwh(self::HIGH_DEMAND_FLOOR_KWH),
                    count($periods)
                ),
                affectedPeriod: [
                    'start' => $first['period']['start'] ?? null,
                    'end' => $last['period']['end'] ?? null,
                    'resolution' => $this->commonResolution($periods),
                ],
                supportingFacts: $facts,
                limitations: [
                    'High demand is an observation about reported values; SPMS does not know which appliances caused it.',
                ],
                evidenceSummary: sprintf('The %d most recent household consumption period records.', count($periods)),
                explanation: sprintf(
                    'Every observed consumption period is at or above the disclosed %s floor. This is a recurring reported pattern, not a verified diagnosis of household behaviour.',
                    EvidenceFormat::kwh(self::HIGH_DEMAND_FLOOR_KWH)
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['consumption'],
                natures: array_map(static fn (array $record): ?string => $record['data_nature'], $periods),
                statuses: array_map(static fn (array $record): ?string => $record['data_status'], $periods),
                qualities: array_map(static fn (array $record): ?string => $record['quality'], $periods),
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::MODERATE,
                comparisonCount: count($periods)
            )
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $periods
     * @return array<int, DetectedCandidate>
     */
    private function unusualPattern(array $periods): array
    {
        $count = count($periods);
        $values = array_map(static fn (array $record): float => (float) $record['value_kwh'], $periods);

        $baselineValues = array_slice($values, 0, $count - 1);
        $baseline = array_sum($baselineValues) / count($baselineValues);
        $latest = $values[$count - 1];
        $delta = $latest - $baseline;

        if ($baseline == 0.0) {
            return [];
        }

        $deviation = abs($delta) / abs($baseline);
        if ($deviation < self::UNUSUAL_DEVIATION || abs($delta) < self::UNUSUAL_ABSOLUTE_KWH) {
            return [];
        }

        $latestRecord = $periods[$count - 1];

        $facts = [
            sprintf('Recurring baseline (mean of the first %d periods): %s.', $count - 1, EvidenceFormat::kwh($baseline)),
            sprintf('Most recent period (%s): %s.', EvidenceFormat::periodLabel($latestRecord['period']), EvidenceFormat::kwh($latest)),
            sprintf('Deviation from baseline: %s (%s).', EvidenceFormat::kwh($delta), EvidenceFormat::percent($deviation)),
            sprintf('Applied v1 thresholds: %s deviation and at least %s absolute.', EvidenceFormat::percent(self::UNUSUAL_DEVIATION), EvidenceFormat::kwh(self::UNUSUAL_ABSOLUTE_KWH)),
        ];

        return [
            new DetectedCandidate(
                type: InsightTaxonomy::TYPE_UNUSUAL_CONSUMPTION_PATTERN,
                title: 'Unusual household consumption pattern',
                summary: sprintf(
                    'The most recent consumption period (%s) deviates by %s from the household recurring baseline.',
                    EvidenceFormat::periodLabel($latestRecord['period']),
                    EvidenceFormat::percent($deviation)
                ),
                affectedPeriod: $latestRecord['period'],
                supportingFacts: $facts,
                limitations: [
                    'The baseline is derived only from the periods present in the evidence; it is not a long-run statistical model.',
                ],
                evidenceSummary: sprintf('The %d most recent household consumption period records.', $count),
                explanation: sprintf(
                    'The latest reported consumption differs from the household recurring baseline by %s. The deviation is an observation, not an explanation of its cause.',
                    EvidenceFormat::percent($deviation)
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['consumption'],
                natures: array_map(static fn (array $record): ?string => $record['data_nature'], $periods),
                statuses: array_map(static fn (array $record): ?string => $record['data_status'], $periods),
                qualities: array_map(static fn (array $record): ?string => $record['quality'], $periods),
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::MODERATE,
                comparisonCount: $count
            )
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $periods
     */
    private function commonResolution(array $periods): ?string
    {
        $resolutions = array_map(static fn (array $record): ?string => $record['period']['resolution'] ?? null, $periods);

        return count(array_unique($resolutions)) === 1 ? $resolutions[0] : null;
    }
}
