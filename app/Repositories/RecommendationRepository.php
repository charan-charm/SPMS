<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Recommendation\Recommendation;
use App\Support\Database;
use DateTimeImmutable;
use PDO;

/**
 * SPMS F7 — Recommendation Repository (docs/05 §13-16, docs/10 D-072).
 *
 * Provides household-scoped persistence for recommendations, with lifecycle state
 * management, deduplication lookup, and expiration batch updates.
 */
final class RecommendationRepository
{
    public function __construct(
        private readonly ?PDO $pdo = null
    ) {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    public function save(Recommendation $rec): void
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            INSERT INTO recommendations (
                id, household_id, type, status, priority, title, summary, action, reason,
                timing_window, potential_impact, impact_json, evidence_json, confidence,
                quality, data_nature, provenance_json, limitations_json, relevant_period_start,
                relevant_period_end, valid_from, valid_until, created_at, updated_at, dismissed_at
            ) VALUES (
                :id, :household_id, :type, :status, :priority, :title, :summary, :action, :reason,
                :timing_window, :potential_impact, :impact_json, :evidence_json, :confidence,
                :quality, :data_nature, :provenance_json, :limitations_json, :relevant_period_start,
                :relevant_period_end, :valid_from, :valid_until, :created_at, :updated_at, :dismissed_at
            )
        SQL);

        $stmt->execute([
            ':id' => $rec->id(),
            ':household_id' => $rec->householdId(),
            ':type' => $rec->type(),
            ':status' => $rec->status(),
            ':priority' => $rec->priority(),
            ':title' => $rec->title(),
            ':summary' => $rec->summary(),
            ':action' => $rec->action(),
            ':reason' => $rec->reason(),
            ':timing_window' => $rec->timingWindow(),
            ':potential_impact' => $rec->potentialImpact(),
            ':impact_json' => $rec->impactJson() !== null ? json_encode($rec->impactJson(), JSON_THROW_ON_ERROR) : null,
            ':evidence_json' => json_encode($rec->evidenceJson(), JSON_THROW_ON_ERROR),
            ':confidence' => $rec->confidence(),
            ':quality' => $rec->quality(),
            ':data_nature' => $rec->dataNature(),
            ':provenance_json' => json_encode($rec->provenance(), JSON_THROW_ON_ERROR),
            ':limitations_json' => !empty($rec->limitations()) ? json_encode($rec->limitations(), JSON_THROW_ON_ERROR) : null,
            ':relevant_period_start' => $rec->relevantPeriodStart(),
            ':relevant_period_end' => $rec->relevantPeriodEnd(),
            ':valid_from' => $rec->validFrom()->format('Y-m-d H:i:s'),
            ':valid_until' => $rec->validUntil()->format('Y-m-d H:i:s'),
            ':created_at' => $rec->createdAt()->format('Y-m-d H:i:s'),
            ':updated_at' => $rec->updatedAt()->format('Y-m-d H:i:s'),
            ':dismissed_at' => $rec->dismissedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function update(Recommendation $rec): void
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            UPDATE recommendations SET
                status = :status,
                priority = :priority,
                title = :title,
                summary = :summary,
                action = :action,
                reason = :reason,
                timing_window = :timing_window,
                potential_impact = :potential_impact,
                impact_json = :impact_json,
                evidence_json = :evidence_json,
                confidence = :confidence,
                quality = :quality,
                provenance_json = :provenance_json,
                limitations_json = :limitations_json,
                valid_until = :valid_until,
                updated_at = :updated_at,
                dismissed_at = :dismissed_at
            WHERE id = :id AND household_id = :household_id
        SQL);

        $stmt->execute([
            ':id' => $rec->id(),
            ':household_id' => $rec->householdId(),
            ':status' => $rec->status(),
            ':priority' => $rec->priority(),
            ':title' => $rec->title(),
            ':summary' => $rec->summary(),
            ':action' => $rec->action(),
            ':reason' => $rec->reason(),
            ':timing_window' => $rec->timingWindow(),
            ':potential_impact' => $rec->potentialImpact(),
            ':impact_json' => $rec->impactJson() !== null ? json_encode($rec->impactJson(), JSON_THROW_ON_ERROR) : null,
            ':evidence_json' => json_encode($rec->evidenceJson(), JSON_THROW_ON_ERROR),
            ':confidence' => $rec->confidence(),
            ':quality' => $rec->quality(),
            ':provenance_json' => json_encode($rec->provenance(), JSON_THROW_ON_ERROR),
            ':limitations_json' => !empty($rec->limitations()) ? json_encode($rec->limitations(), JSON_THROW_ON_ERROR) : null,
            ':valid_until' => $rec->validUntil()->format('Y-m-d H:i:s'),
            ':updated_at' => $rec->updatedAt()->format('Y-m-d H:i:s'),
            ':dismissed_at' => $rec->dismissedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id, ?string $householdId = null): ?Recommendation
    {
        $sql = 'SELECT * FROM recommendations WHERE id = :id';
        $params = [':id' => $id];
        if ($householdId !== null) {
            $sql .= ' AND household_id = :household_id';
            $params[':household_id'] = $householdId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return Recommendation::fromRow($row);
    }

    /**
     * @param string|array<string, mixed> $householdIdOrFilters
     * @param array<string, mixed> $filters
     * @return list<Recommendation>
     */
    public function list(string|array $householdIdOrFilters, array $filters = []): array
    {
        if (is_array($householdIdOrFilters)) {
            $filters = $householdIdOrFilters;
            $householdId = (string) ($filters['household_id'] ?? '');
        } else {
            $householdId = $householdIdOrFilters;
        }

        $clauses = ['household_id = :household_id'];
        $params = [':household_id' => $householdId];

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'ALL') {
            $clauses[] = 'status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        if (isset($filters['type']) && $filters['type'] !== '') {
            $clauses[] = 'type = :type';
            $params[':type'] = (string) $filters['type'];
        }

        $sql = 'SELECT * FROM recommendations WHERE ' . implode(' AND ', $clauses) . '
                ORDER BY
                    CASE status
                        WHEN "ACTIVE" THEN 1
                        WHEN "DISMISSED" THEN 2
                        WHEN "EXPIRED" THEN 3
                        ELSE 4
                    END ASC,
                    CASE priority
                        WHEN "HIGH" THEN 1
                        WHEN "MEDIUM" THEN 2
                        WHEN "LOW" THEN 3
                        ELSE 4
                    END ASC,
                    created_at DESC';

        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ' . (int) $filters['limit'];
        }

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Recommendation::fromRow($row);
        }

        return $results;
    }

    public function findActiveByPeriod(
        string $householdId,
        string $type,
        ?string $start,
        ?string $end
    ): ?Recommendation {
        $sql = 'SELECT * FROM recommendations
                WHERE household_id = :household_id
                  AND type = :type
                  AND status = :status';
        $params = [
            ':household_id' => $householdId,
            ':type' => $type,
            ':status' => Recommendation::STATUS_ACTIVE,
        ];

        if ($start !== null) {
            $sql .= ' AND relevant_period_start = :start';
            $params[':start'] = $start;
        } else {
            $sql .= ' AND relevant_period_start IS NULL';
        }

        if ($end !== null) {
            $sql .= ' AND relevant_period_end = :end';
            $params[':end'] = $end;
        } else {
            $sql .= ' AND relevant_period_end IS NULL';
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return Recommendation::fromRow($row);
    }

    public function findRecentDismissed(
        string $householdId,
        string $type,
        DateTimeImmutable|int $sinceOrHours,
        ?DateTimeImmutable $asOf = null
    ): ?Recommendation {
        if (is_int($sinceOrHours)) {
            $base = $asOf ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $since = $base->modify("-{$sinceOrHours} hours");
        } else {
            $since = $sinceOrHours;
        }

        $stmt = $this->db()->prepare(<<<'SQL'
            SELECT * FROM recommendations
            WHERE household_id = :household_id
              AND type = :type
              AND status = :status
              AND dismissed_at >= :since
            ORDER BY dismissed_at DESC
            LIMIT 1
        SQL);

        $stmt->execute([
            ':household_id' => $householdId,
            ':type' => $type,
            ':status' => Recommendation::STATUS_DISMISSED,
            ':since' => $since->format('Y-m-d H:i:s'),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return Recommendation::fromRow($row);
    }

    public function expirePast(string $householdId, DateTimeImmutable $now): int
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            UPDATE recommendations
            SET status = :status_expired, updated_at = :updated_at
            WHERE household_id = :household_id
              AND status = :status_active
              AND valid_until < :now_threshold
        SQL);

        $stmt->execute([
            ':status_expired' => Recommendation::STATUS_EXPIRED,
            ':status_active' => Recommendation::STATUS_ACTIVE,
            ':updated_at' => $now->format('Y-m-d H:i:s'),
            ':now_threshold' => $now->format('Y-m-d H:i:s'),
            ':household_id' => $householdId,
        ]);

        return $stmt->rowCount();
    }

    public function dismiss(string $id, string $householdId, DateTimeImmutable $now): bool
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            UPDATE recommendations
            SET status = :status_dismissed, dismissed_at = :dismissed_at, updated_at = :updated_at
            WHERE id = :id AND household_id = :household_id AND status = :status_active
        SQL);

        $stmt->execute([
            ':status_dismissed' => Recommendation::STATUS_DISMISSED,
            ':dismissed_at' => $now->format('Y-m-d H:i:s'),
            ':updated_at' => $now->format('Y-m-d H:i:s'),
            ':id' => $id,
            ':household_id' => $householdId,
            ':status_active' => Recommendation::STATUS_ACTIVE,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function supersedeType(string $householdId, string $type, DateTimeImmutable $now): int
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            UPDATE recommendations
            SET status = :status_superseded, updated_at = :now
            WHERE household_id = :household_id
              AND type = :type
              AND status = :status_active
        SQL);

        $stmt->execute([
            ':status_superseded' => Recommendation::STATUS_SUPERSEDED,
            ':now' => $now->format('Y-m-d H:i:s'),
            ':household_id' => $householdId,
            ':type' => $type,
            ':status_active' => Recommendation::STATUS_ACTIVE,
        ]);

        return $stmt->rowCount();
    }
}
