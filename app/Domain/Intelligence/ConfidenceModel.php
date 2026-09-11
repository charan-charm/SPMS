<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Deterministic, independent insight confidence model (docs/10 D-045).
 *
 * Insight confidence is F6's own judgement about how well the evidence the
 * candidate actually used supports the finding. It is never copied from an
 * upstream F4/F5 confidence value and never fabricated as a numeric score.
 *
 * Base level comes from evidence breadth (how many distinct evidence
 * domains carry usable records for this candidate) and observation depth;
 * honesty ceilings then apply, never upgrades:
 * - data nature: SIMULATED → LOW, ESTIMATED/PREDICTED → MODERATE,
 *   no analytical value → VERY_LOW
 * - quality: POOR → VERY_LOW, UNVERIFIED → LOW, PARTIAL → MODERATE
 * - data status: UNAVAILABLE (and defensively ERROR) → INSUFFICIENT,
 *   STALE → MODERATE, DEGRADED → MODERATE
 * - depth: a comparison of fewer than three records → MODERATE
 */
final class ConfidenceModel
{
    public function assess(DetectedCandidate $candidate, InsightEvidenceInput $evidence): string
    {
        if ($candidate->type === InsightTaxonomy::TYPE_DATA_LIMITATION) {
            // A limitation insight states what is missing; there is no
            // analytical evidence behind it by definition.
            return InsightConfidence::INSUFFICIENT;
        }

        $breadth = $this->usableDomainCount($candidate->evidenceDomains, $evidence);

        // Base level: independent of any upstream confidence value. A
        // single usable domain starts at LOW; corroborating domains and
        // repeated observations (depth >= 3) raise it, honesty ceilings
        // below can still lower it.
        if ($breadth === 0) {
            $rank = InsightConfidence::rank(InsightConfidence::INSUFFICIENT);
        } else {
            $rank = InsightConfidence::rank(InsightConfidence::LOW);

            if ($breadth >= 2) {
                $rank = max($rank, InsightConfidence::rank(InsightConfidence::MODERATE));
            }

            $deepRecurrence = $candidate->comparisonCount !== null && $candidate->comparisonCount >= 3;
            if ($breadth >= 3 || ($deepRecurrence && $breadth >= 1)) {
                $rank = max($rank, InsightConfidence::rank(InsightConfidence::HIGH));
            }
        }

        $natures = $candidate->natures;
        $qualities = $candidate->qualities;
        $statuses = $candidate->statuses;

        if ($candidate->evidenceDomains !== []) {
            $overallQuality = $evidence->qualityContext()['overall_quality'] ?? null;
            if ($overallQuality !== null) {
                $qualities[] = $overallQuality;
            }
        }

        $worstNature = EvidenceSemantics::worstNature($natures);
        if ($worstNature === 'SIMULATED') {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::LOW));
        } elseif ($worstNature === 'ESTIMATED' || $worstNature === 'PREDICTED') {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::MODERATE));
        } elseif ($worstNature === null) {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::VERY_LOW));
        }

        $worstQuality = EvidenceSemantics::worstQuality($qualities);
        if ($worstQuality === 'POOR') {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::VERY_LOW));
        } elseif ($worstQuality === 'UNVERIFIED') {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::LOW));
        } elseif ($worstQuality === 'PARTIAL') {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::MODERATE));
        }

        // Status ceilings (docs/10 D-045): degradation of freshness or
        // integrity lowers confidence, never raises it. ERROR cannot pass
        // validated evidence input; the check is defensive only.
        if (in_array('ERROR', $statuses, true) || in_array('UNAVAILABLE', $statuses, true)) {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::INSUFFICIENT));
        } elseif (in_array('STALE', $statuses, true) || in_array('DEGRADED', $statuses, true)) {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::MODERATE));
        }

        if ($candidate->comparisonCount !== null && $candidate->comparisonCount < 3) {
            $rank = min($rank, InsightConfidence::rank(InsightConfidence::MODERATE));
        }

        return InsightConfidence::fromRank($rank);
    }

    /**
     * Count the candidate's evidence domains that carry at least one usable
     * record. Domains the candidate did not use do not increase confidence.
     *
     * @param array<int, string> $domains
     */
    private function usableDomainCount(array $domains, InsightEvidenceInput $evidence): int
    {
        $count = 0;
        foreach ($domains as $domain) {
            $hasUsable = match ($domain) {
                'solar' => $evidence->solarPeriods() !== [],
                'consumption' => $evidence->consumptionPeriods() !== [],
                'forecast' => $evidence->forecastPeriods() !== [],
                'balance' => $this->hasClassifiedBalance($evidence),
                'cost' => $this->hasEstimatedCost($evidence),
                default => false,
            };

            if ($hasUsable) {
                $count++;
            }
        }

        return $count;
    }

    private function hasClassifiedBalance(InsightEvidenceInput $evidence): bool
    {
        foreach ($evidence->balanceResults() as $result) {
            if ($result['result']['state'] === 'CLASSIFIED') {
                return true;
            }
        }

        return false;
    }

    private function hasEstimatedCost(InsightEvidenceInput $evidence): bool
    {
        foreach ($evidence->costResults() as $result) {
            if ($result['result']['state'] === 'ESTIMATED') {
                return true;
            }
        }

        return false;
    }
}
