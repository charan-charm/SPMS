<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Insight domain model (docs/10 D-043..D-046).
 *
 * An insight is a deterministic, evidence-backed finding. All semantic
 * values (significance, confidence, quality, data nature, data status,
 * provenance) arrive from the engine — never from the frontend, never from
 * user input. All timestamps are UTC (docs/05 §13).
 */
final class Insight
{
    public const PROVENANCE_ENGINE = 'F6_DETERMINISTIC_ENGINE_V1';
    public const TAXONOMY_VERSION = 'V1';

    /**
     * @param array{start: ?string, end: ?string, resolution: ?string}|null $affectedPeriod
     * @param array<int, string> $supportingFacts
     * @param array<int, string> $limitations
     * @param array<string, mixed> $provenance
     */
    private function __construct(
        private readonly ?string $id,
        private readonly ?string $householdId,
        private readonly string $category,
        private readonly string $type,
        private readonly string $title,
        private readonly string $summary,
        private readonly string $significance,
        private readonly string $confidence,
        private readonly string $status,
        private readonly ?array $affectedPeriod,
        private readonly string $detectedAt,
        private readonly string $firstDetectedAt,
        private readonly string $lastObservedAt,
        private readonly string $updatedAt,
        private readonly int $observationCount,
        private readonly ?string $dataNature,
        private readonly string $dataStatus,
        private readonly ?string $quality,
        private readonly ?string $explanation,
        private readonly string $explanationSource,
        private readonly ?string $evidenceSummary,
        private readonly array $supportingFacts,
        private readonly array $limitations,
        private readonly array $provenance
    ) {
    }

    /**
     * Build a freshly detected insight from an evaluated candidate.
     * data_nature is null when no analytical value was involved (e.g. a
     * data limitation insight) — that is honest "not available", not zero.
     *
     * @param array{sources?: array<int, string>, pipeline?: array<int, string>} $provenanceContext
     */
    public static function detected(
        DetectedCandidate $candidate,
        string $significance,
        string $confidence,
        \DateTimeImmutable $now,
        ?string $householdId = null,
        array $provenanceContext = []
    ): self {
        if (!InsightSignificance::isValid($significance) || !InsightConfidence::isValid($confidence)) {
            throw new \InvalidArgumentException('Invalid significance or confidence level.');
        }

        $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return new self(
            id: null,
            householdId: $householdId,
            category: $candidate->category(),
            type: $candidate->type,
            title: $candidate->title,
            summary: $candidate->summary,
            significance: $significance,
            confidence: $confidence,
            status: InsightLifecycle::DETECTED,
            affectedPeriod: $candidate->affectedPeriod,
            detectedAt: $timestamp,
            firstDetectedAt: $timestamp,
            lastObservedAt: $timestamp,
            updatedAt: $timestamp,
            observationCount: 1,
            dataNature: EvidenceSemantics::worstNature($candidate->natures),
            dataStatus: (string) EvidenceSemantics::worstStatus($candidate->statuses, true),
            quality: EvidenceSemantics::worstQuality($candidate->qualities),
            explanation: $candidate->explanation,
            explanationSource: $candidate->explanationSource,
            evidenceSummary: $candidate->evidenceSummary,
            supportingFacts: $candidate->supportingFacts,
            limitations: $candidate->limitations,
            provenance: self::buildProvenance($candidate, $provenanceContext)
        );
    }

    /**
     * Re-observation of an existing insight: lifecycle advances via the
     * deterministic ladder, historical anchors (first_detected_at and the
     * observation count) are preserved, and the evidence fields are
     * replaced by the freshly evaluated candidate.
     */
    public function withObservationMerged(self $latest, string $nextStatus, \DateTimeImmutable $now): self
    {
        if (!InsightLifecycle::isValidState($nextStatus)) {
            throw new \InvalidArgumentException('Invalid lifecycle state: ' . $nextStatus);
        }

        $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return new self(
            id: $this->id,
            householdId: $this->householdId,
            category: $latest->category,
            type: $latest->type,
            title: $latest->title,
            summary: $latest->summary,
            significance: $latest->significance,
            confidence: $latest->confidence,
            status: $nextStatus,
            affectedPeriod: $latest->affectedPeriod,
            detectedAt: $timestamp,
            firstDetectedAt: $this->firstDetectedAt,
            lastObservedAt: $timestamp,
            updatedAt: $timestamp,
            observationCount: $this->observationCount + 1,
            dataNature: $latest->dataNature,
            dataStatus: $latest->dataStatus,
            quality: $latest->quality,
            explanation: $latest->explanation,
            explanationSource: $latest->explanationSource,
            evidenceSummary: $latest->evidenceSummary,
            supportingFacts: $latest->supportingFacts,
            limitations: $latest->limitations,
            provenance: $latest->provenance
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromStorageRow(array $row): self
    {
        $period = null;
        if ($row['affected_period_start'] !== null || $row['affected_period_end'] !== null) {
            $period = [
                'start' => $row['affected_period_start'],
                'end' => $row['affected_period_end'],
                'resolution' => $row['affected_period_resolution'],
            ];
        }

        return new self(
            id: (string) $row['id'],
            householdId: $row['household_id'],
            category: (string) $row['category'],
            type: (string) $row['type'],
            title: (string) $row['title'],
            summary: (string) $row['summary'],
            significance: (string) $row['significance'],
            confidence: (string) $row['confidence'],
            status: (string) $row['status'],
            affectedPeriod: $period,
            detectedAt: (string) $row['detected_at'],
            firstDetectedAt: (string) $row['first_detected_at'],
            lastObservedAt: (string) $row['last_observed_at'],
            updatedAt: (string) $row['updated_at'],
            observationCount: (int) $row['observation_count'],
            dataNature: $row['data_nature'],
            dataStatus: (string) $row['data_status'],
            quality: $row['quality'],
            explanation: $row['explanation'],
            explanationSource: (string) $row['explanation_source'],
            evidenceSummary: $row['evidence_summary'],
            supportingFacts: self::decodeJsonList($row['supporting_facts']),
            limitations: self::decodeJsonList($row['limitations']),
            provenance: self::decodeJsonObject($row['provenance'])
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function withId(string $id): self
    {
        return new self(
            id: $id,
            householdId: $this->householdId,
            category: $this->category,
            type: $this->type,
            title: $this->title,
            summary: $this->summary,
            significance: $this->significance,
            confidence: $this->confidence,
            status: $this->status,
            affectedPeriod: $this->affectedPeriod,
            detectedAt: $this->detectedAt,
            firstDetectedAt: $this->firstDetectedAt,
            lastObservedAt: $this->lastObservedAt,
            updatedAt: $this->updatedAt,
            observationCount: $this->observationCount,
            dataNature: $this->dataNature,
            dataStatus: $this->dataStatus,
            quality: $this->quality,
            explanation: $this->explanation,
            explanationSource: $this->explanationSource,
            evidenceSummary: $this->evidenceSummary,
            supportingFacts: $this->supportingFacts,
            limitations: $this->limitations,
            provenance: $this->provenance
        );
    }

    public function householdId(): ?string
    {
        return $this->householdId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function significance(): string
    {
        return $this->significance;
    }

    public function confidence(): string
    {
        return $this->confidence;
    }

    public function dataNature(): ?string
    {
        return $this->dataNature;
    }

    public function dataStatus(): string
    {
        return $this->dataStatus;
    }

    public function quality(): ?string
    {
        return $this->quality;
    }

    public function affectedPeriod(): ?array
    {
        return $this->affectedPeriod;
    }

    public function observationCount(): int
    {
        return $this->observationCount;
    }

    /**
     * Stable fingerprint of everything the lifecycle ladder treats as
     * "evidence content". A change here marks a re-observation as UPDATED.
     */
    public function evidenceFingerprint(): string
    {
        return sha1(json_encode([
            $this->title,
            $this->summary,
            $this->significance,
            $this->confidence,
            $this->affectedPeriod,
            $this->supportingFacts,
            $this->evidenceSummary,
            $this->limitations,
            $this->explanation,
            $this->dataNature,
            $this->dataStatus,
            $this->quality,
        ]) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageRow(): array
    {
        return [
            'id' => $this->id,
            'household_id' => $this->householdId,
            'category' => $this->category,
            'type' => $this->type,
            'title' => $this->title,
            'summary' => $this->summary,
            'significance' => $this->significance,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'affected_period_start' => $this->affectedPeriod['start'] ?? null,
            'affected_period_end' => $this->affectedPeriod['end'] ?? null,
            'affected_period_resolution' => $this->affectedPeriod['resolution'] ?? null,
            'detected_at' => $this->detectedAt,
            'first_detected_at' => $this->firstDetectedAt,
            'last_observed_at' => $this->lastObservedAt,
            'updated_at' => $this->updatedAt,
            'observation_count' => $this->observationCount,
            'data_nature' => $this->dataNature,
            'data_status' => $this->dataStatus,
            'quality' => $this->quality,
            'explanation' => $this->explanation,
            'explanation_source' => $this->explanationSource,
            'evidence_summary' => $this->evidenceSummary,
            'supporting_facts' => json_encode($this->supportingFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'limitations' => json_encode($this->limitations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'provenance' => json_encode($this->provenance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * API representation — snake_case, matching the established /api/v1
     * contract style. No presentation formatting is embedded.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'household_id' => $this->householdId,
            'category' => $this->category,
            'type' => $this->type,
            'title' => $this->title,
            'summary' => $this->summary,
            'significance' => $this->significance,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'affected_period' => $this->affectedPeriod,
            'detected_at' => $this->detectedAt,
            'first_detected_at' => $this->firstDetectedAt,
            'last_observed_at' => $this->lastObservedAt,
            'updated_at' => $this->updatedAt,
            'observation_count' => $this->observationCount,
            'data_nature' => $this->dataNature,
            'data_status' => $this->dataStatus,
            'quality' => $this->quality,
            'explanation' => $this->explanation,
            'explanation_source' => $this->explanationSource,
            'evidence_summary' => $this->evidenceSummary,
            'supporting_facts' => $this->supportingFacts,
            'limitations' => $this->limitations,
            'provenance' => $this->provenance,
        ];
    }

    /**
     * @param array{sources?: array<int, string>, pipeline?: array<int, string>} $provenanceContext
     * @return array<string, mixed>
     */
    private static function buildProvenance(DetectedCandidate $candidate, array $provenanceContext): array
    {
        return [
            'engine' => self::PROVENANCE_ENGINE,
            'taxonomy_version' => self::TAXONOMY_VERSION,
            'detectors' => $candidate->detectors,
            'evidence_domains' => $candidate->evidenceDomains,
            'sources' => $provenanceContext['sources'] ?? [],
            'pipeline' => $provenanceContext['pipeline'] ?? [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function decodeJsonList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
