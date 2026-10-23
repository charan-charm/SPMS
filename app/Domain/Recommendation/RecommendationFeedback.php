<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SPMS F7 — Recommendation User Feedback (Usefulness, follow-through, subjective reasons).
 *
 * Subjective user feedback is kept strictly separate from objective model accuracy and measurable outcomes.
 */
final class RecommendationFeedback
{
    public const USEFULNESS_USEFUL = 'USEFUL';
    public const USEFULNESS_NOT_USEFUL = 'NOT_USEFUL';

    public const FOLLOWED_YES = 'YES';
    public const FOLLOWED_PARTLY = 'PARTLY';
    public const FOLLOWED_NOT_YET = 'NOT_YET';

    public const REASON_NOT_RELEVANT = 'NOT_RELEVANT';
    public const REASON_WRONG_TIMING = 'WRONG_TIMING';
    public const REASON_COULD_NOT_FOLLOW = 'COULD_NOT_FOLLOW';
    public const REASON_ALREADY_DOING = 'ALREADY_DOING';
    public const REASON_OTHER = 'OTHER';

    public function __construct(
        private readonly string $id,
        private readonly string $recommendationId,
        private readonly string $householdId,
        private readonly string $accountId,
        private string $usefulness,
        private ?string $followedStatus = null,
        private ?string $reason = null,
        private ?string $optionalComment = null,
        private readonly DateTimeImmutable $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC')),
        private DateTimeImmutable $updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'))
    ) {
    }

    public static function create(
        string $id,
        string $recommendationId,
        string $householdId,
        string $accountId,
        string $usefulness,
        ?string $followedStatus = null,
        ?string $reason = null,
        ?string $optionalComment = null,
        ?DateTimeImmutable $now = null
    ): self {
        $currentTime = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self(
            id: $id,
            recommendationId: $recommendationId,
            householdId: $householdId,
            accountId: $accountId,
            usefulness: $usefulness,
            followedStatus: $followedStatus,
            reason: $reason,
            optionalComment: $optionalComment !== null ? mb_substr(trim($optionalComment), 0, 500) : null,
            createdAt: $currentTime,
            updatedAt: $currentTime
        );
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            recommendationId: (string) $row['recommendation_id'],
            householdId: (string) $row['household_id'],
            accountId: (string) $row['account_id'],
            usefulness: (string) $row['usefulness'],
            followedStatus: isset($row['followed_status']) && $row['followed_status'] !== null ? (string) $row['followed_status'] : null,
            reason: isset($row['reason']) && $row['reason'] !== null ? (string) $row['reason'] : null,
            optionalComment: isset($row['optional_comment']) && $row['optional_comment'] !== null ? (string) $row['optional_comment'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC'))
        );
    }

    public function updateFeedback(
        string $usefulness,
        ?string $followedStatus = null,
        ?string $reason = null,
        ?string $optionalComment = null,
        ?DateTimeImmutable $now = null
    ): void {
        $this->usefulness = $usefulness;
        if ($followedStatus !== null) {
            $this->followedStatus = $followedStatus;
        }
        if ($reason !== null) {
            $this->reason = $reason;
        }
        if ($optionalComment !== null) {
            $this->optionalComment = mb_substr(trim($optionalComment), 0, 500);
        }
        $this->updatedAt = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
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

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function usefulness(): string
    {
        return $this->usefulness;
    }

    public function followedStatus(): ?string
    {
        return $this->followedStatus;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function optionalComment(): ?string
    {
        return $this->optionalComment;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'recommendation_id' => $this->recommendationId,
            'household_id' => $this->householdId,
            'usefulness' => $this->usefulness,
            'followed_status' => $this->followedStatus,
            'reason' => $this->reason,
            'optional_comment' => $this->optionalComment,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
