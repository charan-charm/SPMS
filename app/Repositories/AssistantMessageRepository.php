<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Repository persisting and querying household-scoped conversation messages.
 */
final class AssistantMessageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * Save an assistant or user message.
     */
    public function save(
        string $householdId,
        string $accountId,
        string $role,
        string $content,
        ?array $contextDomains = null,
        ?string $provider = null,
        ?string $model = null,
        bool $fallbackUsed = false
    ): string {
        $id = self::uuid();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO assistant_messages (
                id, household_id, account_id, role, content,
                context_domains_json, provider, model, fallback_used, created_at
            ) VALUES (
                :id, :household_id, :account_id, :role, :content,
                :context_domains_json, :provider, :model, :fallback_used, :created_at
            )
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':household_id' => $householdId,
            ':account_id' => $accountId,
            ':role' => $role,
            ':content' => $content,
            ':context_domains_json' => $contextDomains !== null ? json_encode($contextDomains, JSON_THROW_ON_ERROR) : null,
            ':provider' => $provider,
            ':model' => $model,
            ':fallback_used' => $fallbackUsed ? 1 : 0,
            ':created_at' => $now,
        ]);

        return $id;
    }

    /**
     * List recent messages for a household (newest first, limited to $limit).
     *
     * @return list<array<string, mixed>>
     */
    public function listRecent(string $householdId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT id, household_id, account_id, role, content,
                   context_domains_json, provider, model, fallback_used, created_at
            FROM assistant_messages
            WHERE household_id = :household_id
            ORDER BY created_at ASC
            LIMIT 50
        SQL);

        $stmt->execute([':household_id' => $householdId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $messages = [];
        foreach ($rows as $row) {
            $domains = null;
            if (!empty($row['context_domains_json'])) {
                try {
                    $domains = json_decode((string) $row['context_domains_json'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $domains = null;
                }
            }

            $messages[] = [
                'id' => (string) $row['id'],
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
                'context_domains' => $domains,
                'provider' => $row['provider'],
                'model' => $row['model'],
                'fallback_used' => (bool) $row['fallback_used'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        // Return latest $limit items in ascending chronological order
        if (count($messages) > $limit) {
            $messages = array_slice($messages, -$limit);
        }

        return $messages;
    }

    /**
     * Delete all conversation history for a household.
     */
    public function clear(string $householdId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM assistant_messages WHERE household_id = :household_id');
        $stmt->execute([':household_id' => $householdId]);

        return $stmt->rowCount();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
