<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SPMS F7 — Recommendation Objective Measurable Outcome.
 *
 * Tracks empirical validation signals (e.g. actual solar generation during recommended window,
 * inverter recovery post-limitation, demand baseline changes) completely separate from subjective user feedback.
 */
final class RecommendationOutcome
{
    public const OUTCOME_SOLAR_WINDOW_GENERATION = 'SOLAR_WINDOW_GENERATION';
    public const OUTCOME_INVERTER_RECOVERY = 'INVERTER_RECOVERY';
    public const OUTCOME_DEMAND_REDUCTION = 'DEMAND_REDUCTION';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_EVALUATED = 'EVALUATED';
    public const STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    public function __construct(
        private readonly string $id,
        private readonly string $recommendationId,
        private readonly string $householdId,
        private readonly string $outcomeType,
        private readonly ?DateTimeImmutable $targetPeriodStart = null,
        private readonly ?DateTimeImmutable $targetPeriodEnd = null,
        private ?float $predictedValue = null,
        private ?float $actualValue = null,
        private string $evaluationStatus = self::STATUS_PENDING,
        private ?bool $successFlag = null,
        private ?array $details = null,
        private readonly DateTimeImmutable $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC')),
        private ?DateTimeImmutable $evaluatedAt = null
    ) {
    }

    public static function create(
        string $id,
        string $recommendationId,
        string $householdId,
        string $outcomeType,
        ?DateTimeImmutable $targetPeriodStart = null,
        ?DateTimeImmutable $targetPeriodEnd = null,
        ?float $predictedValue = null,
        ?array $details = null,
        ?DateTimeImmutable $now = null
    ): self {
        $currentTime = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self(
            id: $id,
            recommendationId: $recommendationId,
            householdId: $householdId,
            outcomeType: $outcomeType,
            targetPeriodStart: $targetPeriodStart,
            targetPeriodEnd: $targetPeriodEnd,
            predictedValue: $predictedValue,
            actualValue: null,
            evaluationStatus: self::STATUS_PENDING,
            successFlag: null,
            details: $details,
            createdAt: $currentTime,
            evaluatedAt: null
        );
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            recommendationId: (string) $row['recommendation_id'],
            householdId: (string) $row['household_id'],
            outcomeType: (string) $row['outcome_type'],
            targetPeriodStart: isset($row['target_period_start']) && $row['target_period_start'] !== null
                ? new DateTimeImmutable((string) $row['target_period_start'], new DateTimeZone('UTC'))
                : null,
            targetPeriodEnd: isset($row['target_period_end']) && $row['target_period_end'] !== null
                ? new DateTimeImmutable((string) $row['target_period_end'], new DateTimeZone('UTC'))
                : null,
            predictedValue: isset($row['predicted_value']) && $row['predicted_value'] !== null ? (float) $row['predicted_value'] : null,
            actualValue: isset($row['actual_value']) && $row['actual_value'] !== null ? (float) $row['actual_value'] : null,
            evaluationStatus: (string) ($row['evaluation_status'] ?? self::STATUS_PENDING),
            successFlag: isset($row['success_flag']) && $row['success_flag'] !== null ? (bool) $row['success_flag'] : null,
            details: isset($row['details_json']) && $row['details_json'] !== null ? json_decode((string) $row['details_json'], true) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            evaluatedAt: isset($row['evaluated_at']) && $row['evaluated_at'] !== null
                ? new DateTimeImmutable((string) $row['evaluated_at'], new DateTimeZone('UTC'))
                : null
        );
    }

    public function recordEvaluation(?float $actualValue, ?bool $successFlag, ?array $details = null, ?DateTimeImmutable $now = null): void
    {
        $this->actualValue = $actualValue;
        $this->successFlag = $successFlag;
        if ($details !== null) {
            $this->details = $details;
        }
        $this->evaluationStatus = self::STATUS_EVALUATED;
        $this->evaluatedAt = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function recommendationId(): string
    {
        return $this->recommendationId;
    }

    public function householdId(): string
    {
        return $this->householdId;
    }

    public function outcomeType(): string
    {
        return $this->outcomeType;
    }

    public function targetPeriodStart(): ?DateTimeImmutable
    {
        return $this->targetPeriodStart;
    }

    public function targetPeriodEnd(): ?DateTimeImmutable
    {
        return $this->targetPeriodEnd;
    }

    public function predictedValue(): ?float
    {
        return $this->predictedValue;
    }

    public function actualValue(): ?float
    {
        return $this->actualValue;
    }

    public function evaluationStatus(): string
    {
        return $this->evaluationStatus;
    }

    public function isSuccess(): ?bool
    {
        return $this->successFlag;
    }

    public function details(): ?array
    {
        return $this->details;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function evaluatedAt(): ?DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'recommendation_id' => $this->recommendationId,
            'household_id' => $this->householdId,
            'outcome_type' => $this->outcomeType,
            'target_period_start' => $this->targetPeriodStart?->format(DateTimeImmutable::ATOM),
            'target_period_end' => $this->targetPeriodEnd?->format(DateTimeImmutable::ATOM),
            'predicted_value' => $this->predictedValue,
            'actual_value' => $this->actualValue,
            'evaluation_status' => $this->evaluationStatus,
            'success_flag' => $this->successFlag,
            'details' => $this->details,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'evaluated_at' => $this->evaluatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
