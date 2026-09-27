<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * F6 — Household consumption change detector.
 *
 * Mirrors the solar change detector with the same disclosed v1 thresholds
 * (docs/10 D-044).
 */
final class ConsumptionChangeDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'CONSUMPTION_CHANGE_DETECTOR_V1';
    public const RELATIVE_THRESHOLD = 0.20;
    public const ABSOLUTE_THRESHOLD = 0.5;
    public const ABSOLUTE_ONLY_THRESHOLD = 1.0;
    public const HIGH_RELATIVE_THRESHOLD = 0.50;
    public const HIGH_ABSOLUTE_THRESHOLD = 2.0;

    public function detect(InsightEvidenceInput $evidence): array
    {
        $comparison = TwoPeriodComparison::evaluate($evidence->consumptionPeriods(), 'value_kwh');
        if ($comparison === null) {
            return [];
        }

        $delta = $comparison['delta'];
        $relative = $comparison['relative'];
        $absoluteDelta = abs($delta);

        $type = null;
        $hint = null;

        if ($relative !== null && abs($relative) >= self::RELATIVE_THRESHOLD && $absoluteDelta >= self::ABSOLUTE_THRESHOLD) {
            $hint = abs($relative) >= self::HIGH_RELATIVE_THRESHOLD && $absoluteDelta >= self::HIGH_ABSOLUTE_THRESHOLD
                ? InsightSignificance::HIGH
                : InsightSignificance::MODERATE;
            $type = $delta > 0
                ? InsightTaxonomy::TYPE_CONSUMPTION_INCREASE
                : InsightTaxonomy::TYPE_CONSUMPTION_DECREASE;
        } elseif ($absoluteDelta >= self::ABSOLUTE_ONLY_THRESHOLD) {
            $type = InsightTaxonomy::TYPE_CONSUMPTION_CHANGE;
            $hint = $absoluteDelta >= self::HIGH_ABSOLUTE_THRESHOLD ? InsightSignificance::MODERATE : InsightSignificance::LOW;
        }

        if ($type === null || $hint === null) {
            return [];
        }

        $previous = $comparison['previous'];
        $current = $comparison['current'];

        $facts = [
            sprintf(
                'Previous period (%s): %s reported as %s/%s.',
                EvidenceFormat::periodLabel($previous['period']),
                EvidenceFormat::kwh($comparison['previousValue']),
                $previous['data_nature'],
                $previous['data_status']
            ),
            sprintf(
                'Current period (%s): %s reported as %s/%s.',
                EvidenceFormat::periodLabel($current['period']),
                EvidenceFormat::kwh($comparison['currentValue']),
                $current['data_nature'],
                $current['data_status']
            ),
            sprintf('Change: %s (%s).', EvidenceFormat::kwh($delta), $relative === null ? 'relative change not defined from a zero base' : EvidenceFormat::percent($relative)),
            sprintf('Applied v1 thresholds: %s relative or %s absolute.', EvidenceFormat::percent(self::RELATIVE_THRESHOLD), EvidenceFormat::kwh(self::ABSOLUTE_THRESHOLD)),
        ];

        $direction = match ($type) {
            InsightTaxonomy::TYPE_CONSUMPTION_INCREASE => 'increased',
            InsightTaxonomy::TYPE_CONSUMPTION_DECREASE => 'decreased',
            default => 'changed',
        };

        return [
            new DetectedCandidate(
                type: $type,
                title: 'Household consumption ' . $direction,
                summary: sprintf(
                    'Household consumption moved from %s to %s between the two most recent observed periods (%s).',
                    EvidenceFormat::kwh($comparison['previousValue']),
                    EvidenceFormat::kwh($comparison['currentValue']),
                    EvidenceFormat::periodLabel($current['period'])
                ),
                affectedPeriod: $current['period'],
                supportingFacts: $facts,
                limitations: [
                    'Values are carried exactly as reported by the evidence; SPMS does not verify the underlying consumption measurement in this step.',
                ],
                evidenceSummary: 'Two most recent household consumption period records.',
                explanation: sprintf(
                    'The two most recent consumption periods differ by %s. The values above keep their reported data nature and are an observed movement, not a verified explanation of behaviour.',
                    EvidenceFormat::kwh(abs($delta))
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['consumption'],
                natures: [$previous['data_nature'], $current['data_nature']],
                statuses: [$previous['data_status'], $current['data_status']],
                qualities: [$previous['quality'], $current['quality']],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: $hint,
                comparisonCount: 2
            )
        ];
    }
}
