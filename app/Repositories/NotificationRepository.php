<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Notification\Notification;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * SPMS F9 — Notification Repository.
 *
 * Handles persistence, queries, duplicate prevention, and read-state updates
 * for household notifications with strict household boundary enforcement.
 */
final class NotificationRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Insert notification with idempotency. If an event_key collision occurs, returns false.
     */
    public function insert(Notification $notification): bool
    {
        $sql = <<<'SQL'
            INSERT IGNORE INTO notifications (
                id, household_id, type, severity, title, message,
                is_read, read_at, related_resource_type, related_resource_id,
                event_key, created_at, updated_at
            ) VALUES (
                :id, :household_id, :type, :severity, :title, :message,
                :is_read, :read_at, :related_resource_type, :related_resource_id,
                :event_key, :created_at, :updated_at
            )
        SQL;

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute([
            'id' => $notification->id(),
            'household_id' => $notification->householdId(),
            'type' => $notification->type(),
            'severity' => $notification->severity(),
            'title' => $notification->title(),
            'message' => $notification->message(),
            'is_read' => $notification->isRead() ? 1 : 0,
            'read_at' => $notification->readAt()?->format('Y-m-d H:i:s'),
            'related_resource_type' => $notification->relatedResourceType(),
            'related_resource_id' => $notification->relatedResourceId(),
            'event_key' => $notification->eventKey(),
            'created_at' => $notification->createdAt()->format('Y-m-d H:i:s'),
            'updated_at' => $notification->updatedAt()->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount() > 0;
    }

    public function findById(string $id, string $householdId): ?Notification
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            SELECT * FROM notifications
            WHERE id = :id AND household_id = :household_id
            LIMIT 1
        SQL);
        $stmt->execute([
            'id' => $id,
            'household_id' => $householdId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function hasEventKey(string $householdId, string $eventKey): bool
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            SELECT COUNT(*) FROM notifications
            WHERE household_id = :household_id AND event_key = :event_key
        SQL);
        $stmt->execute([
            'household_id' => $householdId,
            'event_key' => $eventKey,
        ]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * List notifications for a household, newest first.
     *
     * @return list<Notification>
     */
    public function listForHousehold(
        string $householdId,
        int $limit = 50,
        int $offset = 0,
        ?bool $unreadOnly = null
    ): array {
        $sql = 'SELECT * FROM notifications WHERE household_id = :household_id';
        $params = [
            'household_id' => $householdId,
        ];

        if ($unreadOnly === true) {
            $sql .= ' AND is_read = 0';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection()->prepare($sql);
        $stmt->bindValue(':household_id', $householdId, PDO::PARAM_STR);
        if ($unreadOnly === true) {
            // is_read is already in the SQL string
        }
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    public function countUnread(string $householdId): int
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            SELECT COUNT(*) FROM notifications
            WHERE household_id = :household_id AND is_read = 0
        SQL);
        $stmt->execute(['household_id' => $householdId]);

        return (int) $stmt->fetchColumn();
    }

    public function countTotal(string $householdId): int
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            SELECT COUNT(*) FROM notifications
            WHERE household_id = :household_id
        SQL);
        $stmt->execute(['household_id' => $householdId]);

        return (int) $stmt->fetchColumn();
    }

    public function markAsRead(string $id, string $householdId, DateTimeImmutable $readAt): bool
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            UPDATE notifications
            SET is_read = 1, read_at = :read_at, updated_at = :updated_at
            WHERE id = :id AND household_id = :household_id AND is_read = 0
        SQL);

        $nowStr = $readAt->format('Y-m-d H:i:s');
        $stmt->execute([
            'read_at' => $nowStr,
            'updated_at' => $nowStr,
            'id' => $id,
            'household_id' => $householdId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markAllAsRead(string $householdId, DateTimeImmutable $readAt): int
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            UPDATE notifications
            SET is_read = 1, read_at = :read_at, updated_at = :updated_at
            WHERE household_id = :household_id AND is_read = 0
        SQL);

        $nowStr = $readAt->format('Y-m-d H:i:s');
        $stmt->execute([
            'read_at' => $nowStr,
            'updated_at' => $nowStr,
            'household_id' => $householdId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Notification
    {
        $utc = new DateTimeZone('UTC');
        return new Notification(
            id: (string) $row['id'],
            householdId: (string) $row['household_id'],
            type: (string) $row['type'],
            severity: (string) $row['severity'],
            title: (string) $row['title'],
            message: (string) $row['message'],
            isRead: (bool) $row['is_read'],
            readAt: $row['read_at'] !== null ? new DateTimeImmutable((string) $row['read_at'], $utc) : null,
            relatedResourceType: $row['related_resource_type'] !== null ? (string) $row['related_resource_type'] : null,
            relatedResourceId: $row['related_resource_id'] !== null ? (string) $row['related_resource_id'] : null,
            eventKey: $row['event_key'] !== null ? (string) $row['event_key'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc)
        );
    }
}
