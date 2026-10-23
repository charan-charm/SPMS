<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\InsightLifecycle;
use App\Support\Database;
use PDO;
use PDOException;
use Throwable;

/**
 * F6 — Insight persistence (docs/10 D-046, docs/05 §15/§16/§18).
 *
 * The repository owns the dedup + lifecycle write path. Deduplication is
 * protected by a database unique key over (household, type, affected
 * period) via NULL-safe generated columns, complemented by an explicit
 * lookup inside a transaction (docs/05 §15). Household ownership is
 * enforced by scoping every read to the validated filters provided by the
 * API layer — no raw filter values ever reach the query.
 *
 * Deliberately not final: the deterministic duplicate-key convergence
 * test subclasses this class to simulate a lost first-observation race
 * without a real second process. No production code extends it.
 */
class InsightRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert a newly detected insight or record a re-observation of an
     * existing one (ladder + counters + refreshed evidence), atomically.
     *
     * Concurrency-safe: when two observations race to first-detect the
     * same identity, the losing INSERT hits the unique dedup key and is
     * recovered by re-reading the winning row and recording this
     * observation as a repeat — identical lifecycle, fingerprint and
     * counter semantics, never a failed request.
     */
    public function upsert(Insight $candidate, \DateTimeImmutable $now): Insight
    {
        $pdo = $this->connection();

        $pdo->beginTransaction();
        try {
            $existing = $this->findByDedupKey($candidate, $pdo);

            if ($existing === null) {
                $insight = $candidate->withId(self::uuid());
                try {
                    $this->insert($insight, $pdo);
                    $pdo->commit();

                    return $insight;
                } catch (PDOException $e) {
                    if (!$this->isDuplicateKeyViolation($e)) {
                        throw $e;
                    }

                    // A concurrent observation won the unique-key race.
                    // Re-read the winning row and fall through to the
                    // re-observation merge below.
                    $existing = $this->findByDedupKey($candidate, $pdo);
                    if ($existing === null) {
                        throw $e;
                    }
                }
            }

            $merged = $this->mergeObservation($existing, $candidate, $now, $pdo);
            $pdo->commit();

            return $merged;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function mergeObservation(Insight $existing, Insight $candidate, \DateTimeImmutable $now, PDO $pdo): Insight
    {
        $evidenceChanged = $existing->evidenceFingerprint() !== $candidate->evidenceFingerprint();
        $nextStatus = InsightLifecycle::nextOnObservation($existing->status(), $evidenceChanged);
        $merged = $existing->withObservationMerged($candidate, $nextStatus, $now);

        $this->update($merged, $pdo);

        return $merged;
    }

    /**
     * MySQL duplicate-entry violation (SQLSTATE 23000, driver error 1062)
     * against the dedup unique key.
     */
    private function isDuplicateKeyViolation(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
    }

    /**
     * Validated, allowlisted listing. All values arrive pre-validated from
     * the API layer; the repository only ever binds parameters.
     *
     * @param array<string, mixed> $filters
     * @return array<int, Insight>
     */
    public function list(array $filters): array
    {
        $pdo = $this->connection();

        $conditions = [];
        $params = [];

        // W3 household scoping (docs/10 D-050, D-057): a null value means
        // "system scope only" (the caller has no household yet); the
        // optional system-scope inclusion lets an owned household also
        // see NULL-scope (undetermined/system) insights. Direct internal
        // callers that pass neither convention keep the exact pre-W3
        // equality semantics.
        if (array_key_exists('household_id', $filters)) {
            $household = $filters['household_id'];

            if ($household === null) {
                $conditions[] = 'household_id IS NULL';
            } elseif (!empty($filters['system_scoped_included'])) {
                $conditions[] = '(household_id = :household_id OR household_id IS NULL)';
                $params['household_id'] = $household;
            } else {
                $conditions[] = 'household_id = :household_id';
                $params['household_id'] = $household;
            }
        }

        if (isset($filters['category'])) {
            $conditions[] = 'category = :category';
            $params['category'] = $filters['category'];
        }

        if (isset($filters['type'])) {
            $conditions[] = 'type = :type';
            $params['type'] = $filters['type'];
        }

        if (isset($filters['significance'])) {
            $conditions[] = 'significance = :significance';
            $params['significance'] = $filters['significance'];
        }

        if (isset($filters['confidence'])) {
            $conditions[] = 'confidence = :confidence';
            $params['confidence'] = $filters['confidence'];
        }

        if (isset($filters['status'])) {
            $conditions[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        // Period filter semantics: insights without an affected period are
        // not period-scoped and are excluded when a period filter is given.
        if (isset($filters['period_start']) || isset($filters['period_end'])) {
            if (isset($filters['period_start'])) {
                $conditions[] = 'affected_period_start IS NOT NULL AND COALESCE(affected_period_end, affected_period_start) >= :period_start';
                $params['period_start'] = $filters['period_start'];
            }
            if (isset($filters['period_end'])) {
                $conditions[] = 'affected_period_start IS NOT NULL AND affected_period_start <= :period_end';
                $params['period_end'] = $filters['period_end'];
            }
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sql = sprintf(
            'SELECT * FROM insights %s ORDER BY CASE significance WHEN \'HIGH\' THEN 3 WHEN \'MODERATE\' THEN 2 ELSE 1 END DESC, last_observed_at DESC, type ASC LIMIT %d OFFSET %d',
            $where,
            (int) ($filters['limit'] ?? 50),
            (int) ($filters['offset'] ?? 0)
        );

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): Insight => Insight::fromStorageRow($row), $rows);
    }

    public function findById(string $id): ?Insight
    {
        $pdo = $this->connection();

        $statement = $pdo->prepare('SELECT * FROM insights WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Insight::fromStorageRow($row);
    }

    /**
     * Protected (not private) so the deterministic duplicate-key
     * convergence test can simulate a lost race without a real second
     * process; production behavior is unchanged.
     */
    protected function findByDedupKey(Insight $candidate, PDO $pdo): ?Insight
    {
        $statement = $pdo->prepare(
            'SELECT * FROM insights WHERE dedup_household = :household_key AND type = :type AND dedup_period = :period_key'
        );
        $statement->execute([
            'household_key' => $candidate->householdId() ?? '',
            'type' => $candidate->type(),
            'period_key' => self::periodKey($candidate),
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Insight::fromStorageRow($row);
    }

    private function insert(Insight $insight, PDO $pdo): void
    {
        $row = $insight->toStorageRow();

        $statement = $pdo->prepare(
            'INSERT INTO insights
                (id, household_id, category, type, title, summary, significance, confidence, status,
                 affected_period_start, affected_period_end, affected_period_resolution,
                 detected_at, first_detected_at, last_observed_at, updated_at, observation_count,
                 data_nature, data_status, quality, explanation, explanation_source,
                 evidence_summary, supporting_facts, limitations, provenance)
             VALUES
                (:id, :household_id, :category, :type, :title, :summary, :significance, :confidence, :status,
                 :affected_period_start, :affected_period_end, :affected_period_resolution,
                 :detected_at, :first_detected_at, :last_observed_at, :updated_at, :observation_count,
                 :data_nature, :data_status, :quality, :explanation, :explanation_source,
                 :evidence_summary, :supporting_facts, :limitations, :provenance)'
        );

        $statement->execute($row);
    }

    private function update(Insight $insight, PDO $pdo): void
    {
        $row = $insight->toStorageRow();
        // household_id is part of the dedup identity and is never updated.
        unset($row['household_id']);

        $statement = $pdo->prepare(
            'UPDATE insights SET
                category = :category,
                type = :type,
                title = :title,
                summary = :summary,
                significance = :significance,
                confidence = :confidence,
                status = :status,
                affected_period_start = :affected_period_start,
                affected_period_end = :affected_period_end,
                affected_period_resolution = :affected_period_resolution,
                detected_at = :detected_at,
                first_detected_at = :first_detected_at,
                last_observed_at = :last_observed_at,
                updated_at = :updated_at,
                observation_count = :observation_count,
                data_nature = :data_nature,
                data_status = :data_status,
                quality = :quality,
                explanation = :explanation,
                explanation_source = :explanation_source,
                evidence_summary = :evidence_summary,
                supporting_facts = :supporting_facts,
                limitations = :limitations,
                provenance = :provenance
             WHERE id = :id'
        );

        $statement->execute($row);
    }

    /**
     * Expire past active insights whose applicable period has elapsed.
     */
    public function expirePast(string $householdId, \DateTimeImmutable $now): int
    {
        $pdo = $this->connection();
        $dateStr = $now->format('Y-m-d');

        $stmt = $pdo->prepare(
            'UPDATE insights SET status = :expired_status, updated_at = :now
             WHERE household_id = :household_id
               AND status IN (:s1, :s2, :s3, :s4)
               AND affected_period_end IS NOT NULL
               AND affected_period_end < :today'
        );

        $stmt->execute([
            'expired_status' => InsightLifecycle::EXPIRED,
            'now' => $now->format('Y-m-d H:i:s'),
            'household_id' => $householdId,
            's1' => InsightLifecycle::DETECTED,
            's2' => InsightLifecycle::VALIDATED,
            's3' => InsightLifecycle::ACTIVE,
            's4' => InsightLifecycle::UPDATED,
            'today' => $dateStr,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Transition active insights of a given type to RESOLVED when the condition clears.
     */
    public function resolveCondition(string $householdId, string $type, \DateTimeImmutable $now): int
    {
        $pdo = $this->connection();

        $stmt = $pdo->prepare(
            'UPDATE insights SET status = :resolved_status, updated_at = :now
             WHERE household_id = :household_id
               AND type = :type
               AND status IN (:s1, :s2, :s3, :s4)'
        );

        $stmt->execute([
            'resolved_status' => InsightLifecycle::RESOLVED,
            'now' => $now->format('Y-m-d H:i:s'),
            'household_id' => $householdId,
            'type' => $type,
            's1' => InsightLifecycle::DETECTED,
            's2' => InsightLifecycle::VALIDATED,
            's3' => InsightLifecycle::ACTIVE,
            's4' => InsightLifecycle::UPDATED,
        ]);

        return $stmt->rowCount();
    }

    private static function periodKey(Insight $insight): string
    {
        $period = $insight->affectedPeriod();

        return implode('|', [
            $period['start'] ?? '',
            $period['end'] ?? '',
        ]);
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
