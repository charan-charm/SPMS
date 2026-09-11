<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * F6 — Cost pattern detector.
 *
 * Reads F5 estimated variable cost results exactly as the locked cost
 * service produced them (docs/10 D-042). Every cost insight is an
 * estimate about a future variable cost — never a confirmed or verified
 * bill. INSUFFICIENT_EVIDENCE results produce no cost insight.
 */
final class CostPatternDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'COST_PATTERN_DETECTOR_V1';
    public const RELATIVE_THRESHOLD = 0.25;
    public const ABSOLUTE_THRESHOLD = 50.0;
    public const ABSOLUTE_ONLY_THRESHOLD = 100.0;
    public const HIGH_RELATIVE_THRESHOLD = 0.50;
    public const HIGH_ABSOLUTE_THRESHOLD = 200.0;
    public const MIN_PATTERN_RESULTS = 3;

    public function detect(InsightEvidenceInput $evidence): array
    {
        $estimated = array_values(array_filter(
            $evidence->costResults(),
            static fn (array $payload): bool => $payload['result']['state'] === 'ESTIMATED'
                && $payload['result']['estimated_variable_cost'] !== null
        ));

        if ($estimated === []) {
            return [];
        }

        $currencies = array_values(array_unique(array_map(
            static fn (array $payload): string => (string) $payload['result']['currency'],
            $estimated
        )));

        if (count($currencies) !== 1 || $currencies[0] === '') {
            return [];
        }

        $currency = $currencies[0];

        return array_merge(
            $this->costChange($estimated, $currency),
            $this->costImpactPattern($estimated, $currency)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $estimated
     * @return array<int, DetectedCandidate>
     */
    private function costChange(array $estimated, string $currency): array
    {
        $comparison = TwoPeriodComparison::evaluate(
            array_map(
                static fn (array $payload): array => $payload + ['value_kwh' => $payload['result']['estimated_variable_cost']],
                $estimated
            ),
            'value_kwh'
        );

        if ($comparison === null) {
            return [];
        }

        $delta = $comparison['delta'];
        $relative = $comparison['relative'];
        $absoluteDelta = abs($delta);

        $type = null;
        $hint = null;

        if ($relative !== null && abs($relative) >= self::RELATIVE_THRESHOLD && $absoluteDelta >= self::ABSOLUTE_THRESHOLD) {
            $type = $delta > 0 ? InsightTaxonomy::TYPE_COST_INCREASE : InsightTaxonomy::TYPE_COST_DECREASE;
            $hint = abs($relative) >= self::HIGH_RELATIVE_THRESHOLD && $absoluteDelta >= self::HIGH_ABSOLUTE_THRESHOLD
                ? InsightSignificance::HIGH
                : InsightSignificance::MODERATE;
        } elseif ($absoluteDelta >= self::ABSOLUTE_ONLY_THRESHOLD) {
            $type = InsightTaxonomy::TYPE_COST_CHANGE;
            $hint = InsightSignificance::LOW;
        }

        if ($type === null || $hint === null) {
            return [];
        }

        $previous = $comparison['previous'];
        $current = $comparison['current'];

        $facts = [
            sprintf(
                'Previous period (%s): estimated variable cost %s, pricing basis %s.',
                EvidenceFormat::periodLabel($previous['period']),
                EvidenceFormat::money($comparison['previousValue'], $currency),
                $previous['result']['pricing_basis']
            ),
            sprintf(
                'Current period (%s): estimated variable cost %s, pricing basis %s.',
                EvidenceFormat::periodLabel($current['period']),
                EvidenceFormat::money($comparison['currentValue'], $currency),
                $current['result']['pricing_basis']
            ),
            sprintf('Change: %s (%s).', EvidenceFormat::money($delta, $currency), $relative === null ? 'relative change not defined from a zero base' : EvidenceFormat::percent($relative)),
            sprintf('Applied v1 thresholds: %s relative or %s absolute.', EvidenceFormat::percent(self::RELATIVE_THRESHOLD), EvidenceFormat::money(self::ABSOLUTE_THRESHOLD, $currency)),
        ];

        $direction = match ($type) {
            InsightTaxonomy::TYPE_COST_INCREASE => 'increased',
            InsightTaxonomy::TYPE_COST_DECREASE => 'decreased',
            default => 'changed',
        };

        return [
            new DetectedCandidate(
                type: $type,
                title: 'Estimated variable cost ' . $direction,
                summary: sprintf(
                    'The estimated variable cost moved from %s to %s between the two most recent observed periods (%s).',
                    EvidenceFormat::money($comparison['previousValue'], $currency),
                    EvidenceFormat::money($comparison['currentValue'], $currency),
                    EvidenceFormat::periodLabel($current['period'])
                ),
                affectedPeriod: $current['period'],
                supportingFacts: $facts,
                limitations: [
                    'Both amounts are future estimates produced by the cost pipeline; neither is a verified historical bill.',
                    'Pricing bases are declared per request and are disclosed as unverified in this version.',
                ],
                evidenceSummary: 'The two most recent cost results with an estimated variable cost.',
                explanation: sprintf(
                    'The two most recent estimated variable costs differ by %s. These are estimates computed by the cost pipeline from declared pricing evidence — never a confirmed future electricity bill.',
                    EvidenceFormat::money($delta, $currency)
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['cost'],
                natures: [$previous['result']['data_nature'], $current['result']['data_nature']],
                statuses: [$previous['result']['data_status'], $current['result']['data_status']],
                qualities: [],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: $hint,
                comparisonCount: 2
            )
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $estimated
     * @return array<int, DetectedCandidate>
     */
    private function costImpactPattern(array $estimated, string $currency): array
    {
        if (count($estimated) < self::MIN_PATTERN_RESULTS) {
            return [];
        }

        $amounts = array_map(static fn (array $payload): float => (float) $payload['result']['estimated_variable_cost'], $estimated);
        $nonZero = array_values(array_filter($amounts, static fn (float $amount): bool => $amount > 0.0));

        if (count($nonZero) < self::MIN_PATTERN_RESULTS) {
            return [];
        }

        $first = $estimated[0];
        $last = $estimated[count($estimated) - 1];
        $mean = array_sum($nonZero) / count($nonZero);

        $facts = [
            sprintf('%d of the %d observed cost results carry a non-zero estimated variable cost.', count($nonZero), count($estimated)),
            sprintf('Mean non-zero estimate: %s.', EvidenceFormat::money($mean, $currency)),
            sprintf('Currency is consistent (%s) across the compared results.', $currency),
        ];

        return [
            new DetectedCandidate(
                type: InsightTaxonomy::TYPE_COST_IMPACT_PATTERN,
                title: 'Recurring estimated cost impact',
                summary: sprintf(
                    'The estimated variable cost stayed non-zero across %d observed periods (mean %s).',
                    count($nonZero),
                    EvidenceFormat::money($mean, $currency)
                ),
                affectedPeriod: [
                    'start' => $first['period']['start'] ?? null,
                    'end' => $last['period']['end'] ?? null,
                    'resolution' => $first['period']['resolution'] ?? $last['period']['resolution'] ?? null,
                ],
                supportingFacts: $facts,
                limitations: [
                    'All amounts are future estimates from declared pricing evidence — never confirmed bills, never guaranteed savings.',
                ],
                evidenceSummary: sprintf('The %d most recent cost results with an estimated variable cost.', count($estimated)),
                explanation: sprintf(
                    'Across the observed periods the estimated variable cost repeatedly stayed above zero, so the potential grid-dependent energy has a recurring estimated cost impact of about %s. This is an estimate, not a bill.',
                    EvidenceFormat::money($mean, $currency)
                ),
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['cost'],
                natures: array_map(static fn (array $payload): ?string => $payload['result']['data_nature'], $estimated),
                statuses: array_map(static fn (array $payload): ?string => $payload['result']['data_status'], $estimated),
                qualities: [],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::MODERATE,
                comparisonCount: count($estimated)
            )
        ];
    }
}
