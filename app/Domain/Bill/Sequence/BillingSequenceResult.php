<?php

declare(strict_types=1);

namespace App\Domain\Bill\Sequence;

/**
 * Result DTO for chronological billing period validation.
 */
final class BillingSequenceResult
{
    /**
     * @param array{start: string, end: string, label: string}|null $missingPeriod
     * @param array{start: string, end: string}|null $latestVerifiedPeriod
     */
    public function __construct(
        private readonly string $status,
        private readonly bool $canVerify,
        private readonly bool $requiresAcknowledgment,
        private readonly ?array $missingPeriod,
        private readonly string $message,
        private readonly ?array $latestVerifiedPeriod = null
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function canVerify(): bool
    {
        return $this->canVerify;
    }

    public function requiresAcknowledgment(): bool
    {
        return $this->requiresAcknowledgment;
    }

    /**
     * @return array{start: string, end: string, label: string}|null
     */
    public function missingPeriod(): ?array
    {
        return $this->missingPeriod;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array{start: string, end: string}|null
     */
    public function latestVerifiedPeriod(): ?array
    {
        return $this->latestVerifiedPeriod;
    }

    /**
     * @return array{
     *     status: string,
     *     can_verify: bool,
     *     requires_acknowledgment: bool,
     *     missing_period: array{start: string, end: string, label: string}|null,
     *     message: string,
     *     latest_verified_period: array{start: string, end: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'can_verify' => $this->canVerify,
            'requires_acknowledgment' => $this->requiresAcknowledgment,
            'missing_period' => $this->missingPeriod,
            'message' => $this->message,
            'latest_verified_period' => $this->latestVerifiedPeriod,
        ];
    }
}
