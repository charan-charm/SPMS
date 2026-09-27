<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * SPMS F9 — Household Notification Entity.
 *
 * Represents an event-driven notification belonging to a household,
 * with severity, domain classification, unread tracking, and lineage to related resources.
 */
final class Notification
{
    public const TYPE_SOLAR = 'SOLAR';
    public const TYPE_BILL = 'BILL';
    public const TYPE_RECOMMENDATION = 'RECOMMENDATION';
    public const TYPE_INSIGHT = 'INSIGHT';
    public const TYPE_DATA_QUALITY = 'DATA_QUALITY';
    public const TYPE_SYSTEM = 'SYSTEM';

    public const SEVERITY_INFO = 'INFO';
    public const SEVERITY_SUCCESS = 'SUCCESS';
    public const SEVERITY_WARNING = 'WARNING';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public function __construct(
        private readonly string $id,
        private readonly string $householdId,
        private readonly string $type,
        private readonly string $severity,
        private readonly string $title,
        private readonly string $message,
        private bool $isRead,
        private ?DateTimeImmutable $readAt,
        private readonly ?string $relatedResourceType,
        private readonly ?string $relatedResourceId,
        private readonly ?string $eventKey,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    public static function create(
        string $id,
        string $householdId,
        string $type,
        string $severity,
        string $title,
        string $message,
        ?string $relatedResourceType = null,
        ?string $relatedResourceId = null,
        ?string $eventKey = null,
        ?DateTimeImmutable $now = null
    ): self {
        $timestamp = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self(
            id: $id,
            householdId: $householdId,
            type: $type,
            severity: $severity,
            title: $title,
            message: $message,
            isRead: false,
            readAt: null,
            relatedResourceType: $relatedResourceType,
            relatedResourceId: $relatedResourceId,
            eventKey: $eventKey,
            createdAt: $timestamp,
            updatedAt: $timestamp
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

    public function severity(): string
    {
        return $this->severity;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function readAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function relatedResourceType(): ?string
    {
        return $this->relatedResourceType;
    }

    public function relatedResourceId(): ?string
    {
        return $this->relatedResourceId;
    }

    public function eventKey(): ?string
    {
        return $this->eventKey;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markAsRead(?DateTimeImmutable $now = null): void
    {
        $timestamp = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->isRead = true;
        $this->readAt = $timestamp;
        $this->updatedAt = $timestamp;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'is_read' => $this->isRead,
            'read_at' => $this->readAt?->format('Y-m-d H:i:s'),
            'related_resource_type' => $this->relatedResourceType,
            'related_resource_id' => $this->relatedResourceId,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
