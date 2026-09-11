<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Deterministic significance model (docs/10 D-044).
 *
 * The model is an evidence-weighted v1 heuristic, not scientifically
 * calibrated measurement. It combines the detector's magnitude-based hint
 * with honesty ceilings so that weak evidence can never present itself as
 * highly significant:
 *
 * - DATA_LIMITATION insights are always LOW.
 * - Any evidence that is not ACTUAL (predicted/estimated/simulated, or no
 *   analytical value at all) caps significance at MODERATE.
 * - INSUFFICIENT confidence caps significance at LOW.
 */
final class SignificanceModel
{
    public function assess(DetectedCandidate $candidate, string $confidence): string
    {
        if ($candidate->type === InsightTaxonomy::TYPE_DATA_LIMITATION) {
            return InsightSignificance::LOW;
        }

        $rank = InsightSignificance::rank($candidate->significanceHint);

        $worstNature = EvidenceSemantics::worstNature($candidate->natures);
        if ($worstNature === null || $worstNature !== 'ACTUAL') {
            $rank = min($rank, InsightSignificance::rank(InsightSignificance::MODERATE));
        }

        if ($confidence === InsightConfidence::INSUFFICIENT) {
            $rank = min($rank, InsightSignificance::rank(InsightSignificance::LOW));
        }

        return InsightSignificance::fromRank($rank);
    }
}
