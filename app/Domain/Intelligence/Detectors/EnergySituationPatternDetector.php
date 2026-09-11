<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * F6 — Energy situation pattern detector.
 *
 * Reads F4 classification results exactly as the locked balance service
 * produced them (docs/10 D-044, D-040). F6 never re-derives a balance:
 * recurring surplus/deficit patterns and supply/demand mismatch are
 * patterns of the authoritative classifications, not recomputations.
 */
final class EnergySituationPatternDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'ENERGY_SITUATION_PATTERN_DETECTOR_V1';
    public const MIN_RESULTS = 2;
    private const DAYTIME_START_HOUR = 10;
    private const DAYTIME_END_HOUR = 16;
    private const EVENING_START_HOUR = 17;
    private const EVENING_END_HOUR = 22;

    public function detect(InsightEvidenceInput $evidence): array
    {
        $classified = array_values(array_filter(
            $evidence->balanceResults(),
            static fn (array $payload): bool => $payload['result']['state'] === 'CLASSIFIED'
        ));

        if (count($classified) < self::MIN_RESULTS) {
            return [];
        }

        $classifications = array_map(static fn (array $payload): string => (string) $payload['result']['classification'], $classified);
        $unique = array_values(array_unique($classifications));

        $candidates = [];

        if ($unique === ['POTENTIAL_SURPLUS']) {
            $candidates[] = $this->recurringCandidate($classified, InsightTaxonomy::TYPE_RECURRING_POTENTIAL_SURPLUS);
        } elseif ($unique === ['POTENTIAL_DEFICIT']) {
            $candidates[] = $this->recurringCandidate($classified, InsightTaxonomy::TYPE_RECURRING_POTENTIAL_DEFICIT);
        } elseif (in_array('POTENTIAL_SURPLUS', $unique, true) && in_array('POTENTIAL_DEFICIT', $unique, true)) {
            $candidates[] = $this->mismatchCandidate($classified);

            $daytimeEvening = $this->daytimeEveningCandidate($classified);
            if ($daytimeEvening !== null) {
                $candidates[] = $daytimeEvening;
            }
        }

        return $candidates;
    }

    /**
     * @param array<int, array<string, mixed>> $classified
     */
    private function recurringCandidate(array $classified, string $type): DetectedCandidate
    {
        $isDeficit = $type === InsightTaxonomy::TYPE_RECURRING_POTENTIAL_DEFICIT;
        $label = $isDeficit ? 'potential deficit' : 'potential surplus';

        $first = $classified[0];
        $last = $classified[count($classified) - 1];

        $facts = [
            sprintf('All %d classified energy position results are %s.', count($classified), $label),
        ];
        foreach ($classified as $payload) {
            $facts[] = sprintf(
                'Period %s: %s (balance %s, %s/%s).',
                EvidenceFormat::periodLabel($payload['period']),
                (string) $payload['result']['classification'],
                $payload['result']['balance_kwh'] === null ? 'not reported' : EvidenceFormat::kwh((float) $payload['result']['balance_kwh']),
                $payload['result']['data_nature'] ?? 'no nature reported',
                $payload['result']['data_status'] ?? 'no status reported'
            );
        }

        return new DetectedCandidate(
            type: $type,
            title: $isDeficit ? 'Recurring potential energy deficit' : 'Recurring potential energy surplus',
            summary: sprintf(
                'The energy position was classified as %s in every one of the %d observed classified results.',
                $label,
                count($classified)
            ),
            affectedPeriod: [
                'start' => $first['period']['start'] ?? null,
                'end' => $last['period']['end'] ?? null,
                'resolution' => $this->commonResolution($classified),
            ],
            supportingFacts: $facts,
            limitations: [
                $isDeficit
                    ? 'A potential deficit is not a measured grid import (docs/06 §13); it is the F4 classification of reported evidence.'
                    : 'A potential surplus is not a measured grid export (docs/06 §13); it is the F4 classification of reported evidence.',
            ],
            evidenceSummary: sprintf('The %d most recent classified energy position results from the balance pipeline.', count($classified)),
            explanation: sprintf(
                'The classifications are taken as produced by the energy balance pipeline; no balance is recomputed here. The %s pattern is recurring across the observed results and remains potential, not measured.',
                $label
            ),
            explanationSource: self::EXPLANATION_SOURCE,
            evidenceDomains: ['balance'],
            natures: array_map(static fn (array $payload): ?string => $payload['result']['data_nature'], $classified),
            statuses: array_map(static fn (array $payload): ?string => $payload['result']['data_status'], $classified),
            qualities: array_map(static fn (array $payload): ?string => $payload['result']['quality'], $classified),
            detectors: [self::EXPLANATION_SOURCE],
            significanceHint: InsightSignificance::MODERATE,
            comparisonCount: count($classified)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $classified
     */
    private function mismatchCandidate(array $classified): DetectedCandidate
    {
        $first = $classified[0];
        $last = $classified[count($classified) - 1];

        $facts = [
            sprintf('Observed classified results contain both potential surplus and potential deficit across %d results.', count($classified)),
        ];
        foreach ($classified as $payload) {
            $facts[] = sprintf(
                'Period %s: %s (balance %s).',
                EvidenceFormat::periodLabel($payload['period']),
                (string) $payload['result']['classification'],
                $payload['result']['balance_kwh'] === null ? 'not reported' : EvidenceFormat::kwh((float) $payload['result']['balance_kwh'])
            );
        }

        return new DetectedCandidate(
            type: InsightTaxonomy::TYPE_SOLAR_DEMAND_MISMATCH,
            title: 'Solar supply and household demand do not align',
            summary: 'Observed periods show both potential surplus and potential deficit, so solar supply and household demand fall at different times.',
            affectedPeriod: [
                'start' => $first['period']['start'] ?? null,
                'end' => $last['period']['end'] ?? null,
                'resolution' => $this->commonResolution($classified),
            ],
            supportingFacts: $facts,
            limitations: [
                'Both sides of the mismatch are F4 classifications of reported evidence — potential, never measured import/export.',
            ],
            evidenceSummary: sprintf('The %d most recent classified energy position results from the balance pipeline.', count($classified)),
            explanation: 'When classified results alternate between potential surplus and potential deficit, solar supply and household demand are not aligned in the observed periods. Classifications are taken from the balance pipeline without recomputation.',
            explanationSource: self::EXPLANATION_SOURCE,
            evidenceDomains: ['balance'],
            natures: array_map(static fn (array $payload): ?string => $payload['result']['data_nature'], $classified),
            statuses: array_map(static fn (array $payload): ?string => $payload['result']['data_status'], $classified),
            qualities: array_map(static fn (array $payload): ?string => $payload['result']['quality'], $classified),
            detectors: [self::EXPLANATION_SOURCE],
            significanceHint: InsightSignificance::MODERATE,
            comparisonCount: count($classified)
        );
    }

    /**
     * Fires only when the declared period times support it: a potential
     * surplus result starting in the midday window and a potential deficit
     * result starting in the evening window. Without declared times, no
     * daytime/evening claim is made.
     *
     * @param array<int, array<string, mixed>> $classified
     */
    private function daytimeEveningCandidate(array $classified): ?DetectedCandidate
    {
        $surplus = null;
        $deficit = null;

        foreach ($classified as $payload) {
            $hour = self::startHour($payload['period']['start'] ?? null);
            if ($hour === null) {
                continue;
            }

            if ($payload['result']['classification'] === 'POTENTIAL_SURPLUS'
                && $surplus === null
                && $hour >= self::DAYTIME_START_HOUR && $hour < self::DAYTIME_END_HOUR) {
                $surplus = $payload;
            }

            if ($payload['result']['classification'] === 'POTENTIAL_DEFICIT'
                && $deficit === null
                && $hour >= self::EVENING_START_HOUR && $hour < self::EVENING_END_HOUR) {
                $deficit = $payload;
            }
        }

        if ($surplus === null || $deficit === null) {
            return null;
        }

        return new DetectedCandidate(
            type: InsightTaxonomy::TYPE_DAYTIME_SURPLUS_EVENING_DEFICIT,
            title: 'Daytime surplus with evening deficit pattern',
            summary: 'Observed periods with declared times show potential surplus around midday and potential deficit in the evening.',
            affectedPeriod: [
                'start' => $surplus['period']['start'] ?? null,
                'end' => $deficit['period']['end'] ?? $deficit['period']['start'] ?? null,
                'resolution' => $surplus['period']['resolution'] ?? $deficit['period']['resolution'] ?? null,
            ],
            supportingFacts: [
                sprintf('Midday-window result (%s): POTENTIAL_SURPLUS.', EvidenceFormat::periodLabel($surplus['period'])),
                sprintf('Evening-window result (%s): POTENTIAL_DEFICIT.', EvidenceFormat::periodLabel($deficit['period'])),
                'Declared time windows applied: surplus start hour 10–15, deficit start hour 17–21 (v1 heuristic).',
            ],
            limitations: [
                'The daytime/evening shape is derived only from the declared period times in the evidence; results without declared times are not interpreted.',
            ],
            evidenceSummary: 'Classified energy position results with declared period times.',
            explanation: 'A midday potential surplus together with an evening potential deficit suggests the household could benefit from aligning demand with solar-rich hours. Both sides remain potential classifications, not measured grid flows.',
            explanationSource: self::EXPLANATION_SOURCE,
            evidenceDomains: ['balance'],
            natures: [$surplus['result']['data_nature'], $deficit['result']['data_nature']],
            statuses: [$surplus['result']['data_status'], $deficit['result']['data_status']],
            qualities: [$surplus['result']['quality'], $deficit['result']['quality']],
            detectors: [self::EXPLANATION_SOURCE],
            significanceHint: InsightSignificance::MODERATE,
            comparisonCount: count($classified)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $classified
     */
    private function commonResolution(array $classified): ?string
    {
        $resolutions = array_map(static fn (array $payload): ?string => $payload['period']['resolution'] ?? null, $classified);

        return count(array_unique($resolutions)) === 1 ? $resolutions[0] : null;
    }

    private static function startHour(?string $start): ?int
    {
        if ($start === null || !str_contains($start, 'T')) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s|', $start)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i|', $start);

        return $date instanceof \DateTimeImmutable ? (int) $date->format('G') : null;
    }
}
