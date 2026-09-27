<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * SPMS F7 — Canonical Persisted Recommendation Entity (docs/01 §15, docs/02 §2 F7, docs/10 D-072).
 *
 * Persists actionable advisory recommendations with full lineage, structured evidence,
 * confidence propagation, and deterministic lifecycle tracking.
 */
final class Recommendation
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_DISMISSED = 'DISMISSED';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    public const PRIORITY_HIGH = 'HIGH';
    public const PRIORITY_MEDIUM = 'MEDIUM';
    public const PRIORITY_LOW = 'LOW';

    /**
     * @param string $id
     * @param string $householdId
     * @param string $type
     * @param string $status
     * @param string $priority
     * @param string $title
     * @param string $summary
     * @param string $action
     * @param string $reason
     * @param string $timingWindow
     * @param string|null $potentialImpact
     * @param array<string, mixed>|null $impactJson
     * @param array<string, mixed> $evidenceJson
     * @param string $confidence
     * @param string|null $quality
     * @param string $dataNature
     * @param list<string> $provenance
     * @param list<string> $limitations
     * @param string|null $relevantPeriodStart
     * @param string|null $relevantPeriodEnd
     * @param DateTimeImmutable $validFrom
     * @param DateTimeImmutable $validUntil
     * @param DateTimeImmutable $createdAt
     * @param DateTimeImmutable $updatedAt
     * @param DateTimeImmutable|null $dismissedAt
     */
    public function __construct(
        private readonly string $id,
        private readonly string $householdId,
        private readonly string $type,
        private string $status,
        private string $priority,
        private string $title,
        private string $summary,
        private string $action,
        private string $reason,
        private string $timingWindow,
        private ?string $potentialImpact,
        private ?array $impactJson,
        private array $evidenceJson,
        private string $confidence,
        private ?string $quality,
        private string $dataNature,
        private array $provenance,
        private array $limitations,
        private readonly ?string $relevantPeriodStart,
        private readonly ?string $relevantPeriodEnd,
        private readonly DateTimeImmutable $validFrom,
        private DateTimeImmutable $validUntil,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $dismissedAt = null
    ) {
    }

    public static function createFromCandidate(
        string $id,
        string $householdId,
        RecommendationCandidate $candidate,
        DateTimeImmutable $now
    ): self {
        return new self(
            id: $id,
            householdId: $householdId,
            type: $candidate->type,
            status: self::STATUS_ACTIVE,
            priority: $candidate->priority,
            title: $candidate->title,
            summary: $candidate->summary,
            action: $candidate->action,
            reason: $candidate->reason,
            timingWindow: $candidate->timingWindow,
            potentialImpact: $candidate->impact->description,
            impactJson: $candidate->impact->toArray(),
            evidenceJson: $candidate->evidence->toArray(),
            confidence: $candidate->confidence,
            quality: $candidate->quality,
            dataNature: $candidate->dataNature,
            provenance: $candidate->provenance,
            limitations: $candidate->limitations,
            relevantPeriodStart: $candidate->relevantPeriodStart,
            relevantPeriodEnd: $candidate->relevantPeriodEnd,
            validFrom: $candidate->validFrom,
            validUntil: $candidate->validUntil,
            createdAt: $now,
            updatedAt: $now,
            dismissedAt: null
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function householdId(): string
    {
        return $this->householdId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function priority(): string
    {
        return $this->priority;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function timingWindow(): string
    {
        return $this->timingWindow;
    }

    public function potentialImpact(): ?string
    {
        return $this->potentialImpact;
    }

    public function impactJson(): ?array
    {
        return $this->impactJson;
    }

    public function evidenceJson(): array
    {
        return $this->evidenceJson;
    }

    public function confidence(): string
    {
        return $this->confidence;
    }

    public function quality(): ?string
    {
        return $this->quality;
    }

    public function dataNature(): string
    {
        return $this->dataNature;
    }

    /**
     * @return list<string>
     */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /**
     * @return list<string>
     */
    public function limitations(): array
    {
        return $this->limitations;
    }

    public function relevantPeriodStart(): ?string
    {
        return $this->relevantPeriodStart;
    }

    public function relevantPeriodEnd(): ?string
    {
        return $this->relevantPeriodEnd;
    }

    public function validFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validUntil(): DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function dismissedAt(): ?DateTimeImmutable
    {
        return $this->dismissedAt;
    }

    /**
     * Dismiss this recommendation.
     */
    public function dismiss(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_DISMISSED;
        $this->dismissedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Expire this recommendation.
     */
    public function expire(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_EXPIRED;
        $this->updatedAt = $now;
    }

    /**
     * Supersede this recommendation with newer contradictory evidence.
     */
    public function supersede(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SUPERSEDED;
        $this->updatedAt = $now;
    }

    /**
     * Refresh active recommendation with new candidate evidence.
     */
    public function refreshEvidence(RecommendationCandidate $candidate, DateTimeImmutable $now): void
    {
        $this->title = $candidate->title;
        $this->summary = $candidate->summary;
        $this->action = $candidate->action;
        $this->reason = $candidate->reason;
        $this->timingWindow = $candidate->timingWindow;
        $this->potentialImpact = $candidate->impact->description;
        $this->impactJson = $candidate->impact->toArray();
        $this->priority = $candidate->priority;
        $this->evidenceJson = $candidate->evidence->toArray();
        $this->confidence = $candidate->confidence;
        $this->quality = $candidate->quality;
        $this->dataNature = $candidate->dataNature;
        $this->provenance = $candidate->provenance;
        $this->limitations = $candidate->limitations;
        $this->validUntil = $candidate->validUntil;
        $this->updatedAt = $now;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'household_id' => $this->householdId,
            'type' => $this->type,
            'status' => $this->status,
            'priority' => $this->priority,
            'title' => $this->title,
            'summary' => $this->summary,
            'action' => $this->action,
            'reason' => $this->reason,
            'timing_window' => $this->timingWindow,
            'potential_impact' => $this->potentialImpact,
            'impact' => $this->impactJson,
            'evidence' => $this->evidenceJson,
            'confidence' => $this->confidence,
            'quality' => $this->quality,
            'data_nature' => $this->dataNature,
            'provenance' => $this->provenance,
            'limitations' => $this->limitations,
            'relevant_period' => [
                'start' => $this->relevantPeriodStart,
                'end' => $this->relevantPeriodEnd,
            ],
            'valid_from' => $this->validFrom->format('Y-m-d\TH:i:s\Z'),
            'valid_until' => $this->validUntil->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
            'dismissed_at' => $this->dismissedAt?->format('Y-m-d\TH:i:s\Z'),
            'is_actionable' => RecommendationTaxonomy::isActionable($this->type),
        ];
    }

    /**
     * Reconstruct entity from database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');

        return new self(
            id: (string) $row['id'],
            householdId: (string) $row['household_id'],
            type: (string) $row['type'],
            status: (string) $row['status'],
            priority: (string) $row['priority'],
            title: (string) $row['title'],
            summary: (string) $row['summary'],
            action: (string) $row['action'],
            reason: (string) $row['reason'],
            timingWindow: (string) $row['timing_window'],
            potentialImpact: isset($row['potential_impact']) ? (string) $row['potential_impact'] : null,
            impactJson: isset($row['impact_json']) && $row['impact_json'] !== null ? json_decode((string) $row['impact_json'], true) : null,
            evidenceJson: json_decode((string) $row['evidence_json'], true) ?? [],
            confidence: (string) $row['confidence'],
            quality: isset($row['quality']) ? (string) $row['quality'] : null,
            dataNature: (string) $row['data_nature'],
            provenance: json_decode((string) $row['provenance_json'], true) ?? [],
            limitations: isset($row['limitations_json']) && $row['limitations_json'] !== null ? json_decode((string) $row['limitations_json'], true) ?? [] : [],
            relevantPeriodStart: isset($row['relevant_period_start']) ? (string) $row['relevant_period_start'] : null,
            relevantPeriodEnd: isset($row['relevant_period_end']) ? (string) $row['relevant_period_end'] : null,
            validFrom: new DateTimeImmutable((string) $row['valid_from'], $utc),
            validUntil: new DateTimeImmutable((string) $row['valid_until'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
            dismissedAt: isset($row['dismissed_at']) && $row['dismissed_at'] !== null ? new DateTimeImmutable((string) $row['dismissed_at'], $utc) : null
        );
    }
}
