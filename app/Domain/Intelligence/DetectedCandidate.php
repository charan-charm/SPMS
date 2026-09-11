<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — A deterministic detector finding (docs/10 D-044/D-045).
 *
 * Candidates carry no significance or confidence: those are assigned by the
 * dedicated models in the engine. Candidates do carry the semantic evidence
 * they relied on (natures/statuses/qualities) so degradation can be
 * propagated honestly and never upgraded.
 */
final class DetectedCandidate
{
    /**
     * @param array{start: ?string, end: ?string, resolution: ?string}|null $affectedPeriod
     * @param array<int, string> $supportingFacts
     * @param array<int, string> $limitations
     * @param array<int, string> $evidenceDomains
     * @param array<int, string|null> $natures
     * @param array<int, string|null> $statuses
     * @param array<int, string|null> $qualities
     * @param array<int, string> $detectors
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?array $affectedPeriod,
        public readonly array $supportingFacts,
        public readonly array $limitations,
        public readonly ?string $evidenceSummary,
        public readonly string $explanation,
        public readonly string $explanationSource,
        public readonly array $evidenceDomains,
        public readonly array $natures,
        public readonly array $statuses,
        public readonly array $qualities,
        public readonly array $detectors,
        public readonly string $significanceHint = InsightSignificance::LOW,
        public readonly ?int $comparisonCount = null
    ) {
        if (!InsightTaxonomy::isValidType($type)) {
            throw new \InvalidArgumentException('Unknown insight type: ' . $type);
        }

        if (!InsightSignificance::isValid($significanceHint)) {
            throw new \InvalidArgumentException('Invalid significance hint: ' . $significanceHint);
        }

        if ($this->title === '' || $this->summary === '' || $this->explanation === '' || $this->explanationSource === '') {
            throw new \InvalidArgumentException('A detected candidate requires title, summary, explanation and explanation_source.');
        }

        $isLimitation = $type === InsightTaxonomy::TYPE_DATA_LIMITATION;
        if (!$isLimitation && $this->evidenceDomains === []) {
            throw new \InvalidArgumentException('A non-limitation candidate must declare the evidence domains it used.');
        }
        if ($isLimitation && $this->affectedPeriod !== null) {
            throw new \InvalidArgumentException('A data limitation insight is not period-scoped.');
        }
    }

    /** @return string */
    public function category(): string
    {
        return (string) InsightTaxonomy::categoryForType($this->type);
    }

    /**
     * Period part of the dedup identity (docs/10 D-046). The engine
     * combines it with household and type; category is not part of the key
     * because every type belongs to exactly one category.
     *
     * @return string
     */
    public function periodKey(): string
    {
        return implode('|', [
            $this->affectedPeriod['start'] ?? '',
            $this->affectedPeriod['end'] ?? '',
        ]);
    }
}
