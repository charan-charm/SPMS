<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Repositories\NotificationRepository;
use DateTimeImmutable;
use DateTimeZone;
use Random\RandomException;

/**
 * SPMS F9 — Notification Service.
 *
 * Coordinates event-driven household notification creation, deduplication,
 * and read-state management.
 */
final class NotificationService
{
    private NotificationRepository $repository;

    public function __construct(?NotificationRepository $repository = null)
    {
        $this->repository = $repository ?? new NotificationRepository();
    }

    /**
     * Dispatch a notification to a household with deterministic deduplication.
     */
    public function notify(
        string $householdId,
        string $type,
        string $severity,
        string $title,
        string $message,
        ?string $relatedResourceType = null,
        ?string $relatedResourceId = null,
        ?string $eventKey = null,
        ?DateTimeImmutable $now = null
    ): ?Notification {
        if ($eventKey !== null && $this->repository->hasEventKey($householdId, $eventKey)) {
            // Already notified for this exact event
            return null;
        }

        $id = self::generateUuid();
        $notification = Notification::create(
            id: $id,
            householdId: $householdId,
            type: $type,
            severity: $severity,
            title: $title,
            message: $message,
            relatedResourceType: $relatedResourceType,
            relatedResourceId: $relatedResourceId,
            eventKey: $eventKey,
            now: $now
        );

        $inserted = $this->repository->insert($notification);
        if (!$inserted) {
            return null;
        }

        return $notification;
    }

    /**
     * Fetch paginated notifications with counts for a household.
     *
     * @return array{notifications: list<array<string, mixed>>, unread_count: int, total_count: int}
     */
    public function getNotifications(
        string $householdId,
        int $limit = 50,
        int $offset = 0,
        ?bool $unreadOnly = null
    ): array {
        $notifications = $this->repository->listForHousehold(
            householdId: $householdId,
            limit: $limit,
            offset: $offset,
            unreadOnly: $unreadOnly
        );

        return [
            'notifications' => array_map(static fn (Notification $n) => $n->toArray(), $notifications),
            'unread_count' => $this->repository->countUnread($householdId),
            'total_count' => $this->repository->countTotal($householdId),
        ];
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(string $id, string $householdId, ?DateTimeImmutable $now = null): bool
    {
        $timestamp = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->repository->markAsRead($id, $householdId, $timestamp);
    }

    /**
     * Mark all notifications as read for a household.
     */
    public function markAllAsRead(string $householdId, ?DateTimeImmutable $now = null): int
    {
        $timestamp = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->repository->markAllAsRead($householdId, $timestamp);
    }

    private static function generateUuid(): string
    {
        try {
            $data = random_bytes(16);
        } catch (RandomException) {
            $data = openssl_random_pseudo_bytes(16);
        }
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
