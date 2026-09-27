<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

use DateTimeImmutable;

/**
 * SPMS F7 — Recommendation Candidate (in-memory candidate evaluated by rules before persistence).
 */
final class RecommendationCandidate
{
    /**
     * @param string $type
     * @param string $priority HIGH | MEDIUM | LOW
     * @param string $title
     * @param string $summary
     * @param string $action
     * @param string $reason
     * @param string $timingWindow
     * @param RecommendationImpact $impact
     * @param RecommendationEvidence $evidence
     * @param string $confidence
     * @param string|null $quality
     * @param string $dataNature
     * @param list<string> $provenance
     * @param list<string> $limitations
     * @param string|null $relevantPeriodStart
     * @param string|null $relevantPeriodEnd
     * @param DateTimeImmutable $validFrom
     * @param DateTimeImmutable $validUntil
     */
    public function __construct(
        public readonly string $type,
        public readonly string $priority,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $action,
        public readonly string $reason,
        public readonly string $timingWindow,
        public readonly RecommendationImpact $impact,
        public readonly RecommendationEvidence $evidence,
        public readonly string $confidence,
        public readonly ?string $quality,
        public readonly string $dataNature,
        public readonly array $provenance,
        public readonly array $limitations,
        public readonly ?string $relevantPeriodStart,
        public readonly ?string $relevantPeriodEnd,
        public readonly DateTimeImmutable $validFrom,
        public readonly DateTimeImmutable $validUntil
    ) {
    }
}
