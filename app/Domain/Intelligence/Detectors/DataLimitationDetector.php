<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * F6 — Data limitation detector.
 *
 * Produces a DATA_LIMITATION insight when the evidence itself is missing,
 * unavailable, insufficient, or simulated-only, so consumers can see
 * honestly why insight coverage is limited. Missing data is never treated
 * as zero and never silently ignored (docs/01 §27, docs/10 D-044).
 */
final class DataLimitationDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'DATA_LIMITATION_DETECTOR_V1';

    public function detect(InsightEvidenceInput $evidence): array
    {
        $limitations = $this->collectLimitations($evidence);

        if ($limitations === []) {
            return [];
        }

        $statuses = $this->involvedStatuses($evidence);

        return [
            new DetectedCandidate(
                type: InsightTaxonomy::TYPE_DATA_LIMITATION,
                title: 'Limited evidence for insight generation',
                summary: 'Available evidence is incomplete, unavailable, or simulated, so insight coverage is limited and no missing information is invented.',
                affectedPeriod: null,
                supportingFacts: array_map(
                    static fn (string $limitation): string => 'Limitation: ' . $limitation,
                    $limitations
                ),
                limitations: $limitations,
                evidenceSummary: 'Limitation observations about the provided evidence sections.',
                explanation: 'SPMS states data limitations instead of filling gaps. Sections marked UNAVAILABLE stay unavailable, insufficient pricing evidence stays insufficient, and simulated inputs stay simulated — nothing is upgraded and nothing is treated as zero.',
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: [],
                natures: [],
                statuses: $statuses,
                qualities: [$evidence->qualityContext()['overall_quality'] ?? null],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::LOW,
                comparisonCount: null
            )
        ];
    }

    /**
     * @return array<int, string>
     */
    private function collectLimitations(InsightEvidenceInput $evidence): array
    {
        $limitations = [];

        foreach ($evidence->balanceResults() as $payload) {
            $result = $payload['result'];
            if ($result['state'] === 'UNAVAILABLE') {
                $limitations[] = 'Energy balance: UNAVAILABLE. '
                    . ($result['explanation'] ?? 'No classified energy position is available, so no balance-derived insight can be produced.');
            }
        }

        foreach ($evidence->costResults() as $payload) {
            $result = $payload['result'];
            if ($result['state'] === 'UNAVAILABLE') {
                $explanation = $result['explanation'] ?? null;
                if ($explanation === null || str_contains($explanation, 'not implemented yet')) {
                    $explanation = $evidence->consumptionPeriods() !== []
                        ? 'Historical verified cost compilation is not active for this period; only verified energy consumption is tracked.'
                        : 'Verified historical cost requires verified electricity bill records. Upload and verify recent bills to enable historical cost analysis.';
                }
                $limitations[] = 'Historical verified cost: UNAVAILABLE. ' . $explanation;
            } elseif ($result['state'] === 'INSUFFICIENT_EVIDENCE') {
                $limitations[] = 'Cost estimate: INSUFFICIENT_EVIDENCE. '
                    . ($result['explanation'] ?? 'No reliable pricing evidence was declared, so no cost-derived insight can be produced.');
            }
        }

        $hasValuePeriods = $evidence->solarPeriods() !== []
            || $evidence->consumptionPeriods() !== []
            || $evidence->forecastPeriods() !== [];
        $hasClassifiedBalance = false;
        foreach ($evidence->balanceResults() as $payload) {
            if ($payload['result']['state'] === 'CLASSIFIED') {
                $hasClassifiedBalance = true;
            }
        }
        $hasEstimatedCost = false;
        foreach ($evidence->costResults() as $payload) {
            if ($payload['result']['state'] === 'ESTIMATED') {
                $hasEstimatedCost = true;
            }
        }

        if (!$hasValuePeriods && !$hasClassifiedBalance && !$hasEstimatedCost) {
            $limitations[] = 'No analytical evidence is available at all: no solar, consumption or forecast periods, no classified energy position, and no estimated cost. Missing data is never treated as zero.';
        }

        if ($hasValuePeriods && $this->allValuePeriodsSimulated($evidence)) {
            $limitations[] = 'All reported energy periods are SIMULATED; there is no actual measured household or solar data, so every derived value keeps its simulated nature.';
        }

        $overallStatus = $evidence->qualityContext()['overall_status'] ?? null;
        if ($overallStatus === 'UNAVAILABLE') {
            $limitations[] = 'Overall data status is UNAVAILABLE for this evidence context.';
        } elseif ($overallStatus === 'STALE' || $overallStatus === 'DEGRADED') {
            $limitations[] = sprintf('Overall data status is %s; freshness or integrity is reduced and is not upgraded.', $overallStatus);
        }

        return $limitations;
    }

    private function allValuePeriodsSimulated(InsightEvidenceInput $evidence): bool
    {
        foreach ([$evidence->solarPeriods(), $evidence->consumptionPeriods(), $evidence->forecastPeriods()] as $records) {
            foreach ($records as $record) {
                if ($record['data_nature'] !== 'SIMULATED') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<int, string|null>
     */
    private function involvedStatuses(InsightEvidenceInput $evidence): array
    {
        $statuses = [$evidence->qualityContext()['overall_status'] ?? null];

        foreach ($evidence->balanceResults() as $payload) {
            $statuses[] = $payload['result']['data_status'];
        }
        foreach ($evidence->costResults() as $payload) {
            $statuses[] = $payload['result']['data_status'];
        }

        return $statuses;
    }
}
