<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Recommendation\RecommendationFeedback;
use App\Domain\Recommendation\RecommendationOutcome;
use App\Support\Database;
use DateTimeImmutable;
use PDO;

final class RecommendationFeedbackRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ??= Database::connection();
    }

    public function saveFeedback(RecommendationFeedback $feedback): void
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            INSERT INTO recommendation_feedback (
                id,
                recommendation_id,
                household_id,
                account_id,
                usefulness,
                followed_status,
                reason,
                optional_comment,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :recommendation_id,
                :household_id,
                :account_id,
                :usefulness,
                :followed_status,
                :reason,
                :optional_comment,
                :created_at,
                :updated_at
            )
            ON DUPLICATE KEY UPDATE
                usefulness = VALUES(usefulness),
                followed_status = VALUES(followed_status),
                reason = VALUES(reason),
                optional_comment = VALUES(optional_comment),
                updated_at = VALUES(updated_at)
        SQL);

        $stmt->execute([
            ':id' => $feedback->id(),
            ':recommendation_id' => $feedback->recommendationId(),
            ':household_id' => $feedback->householdId(),
            ':account_id' => $feedback->accountId(),
            ':usefulness' => $feedback->usefulness(),
            ':followed_status' => $feedback->followedStatus(),
            ':reason' => $feedback->reason(),
            ':optional_comment' => $feedback->optionalComment(),
            ':created_at' => $feedback->createdAt()->format('Y-m-d H:i:s'),
            ':updated_at' => $feedback->updatedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByRecommendationAndAccount(string $recId, string $accountId): ?RecommendationFeedback
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            SELECT * FROM recommendation_feedback
            WHERE recommendation_id = :recommendation_id
              AND account_id = :account_id
            LIMIT 1
        SQL);

        $stmt->execute([
            ':recommendation_id' => $recId,
            ':account_id' => $accountId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RecommendationFeedback::fromRow($row) : null;
    }

    public function findByRecommendation(string $recId): ?RecommendationFeedback
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            SELECT * FROM recommendation_feedback
            WHERE recommendation_id = :recommendation_id
            ORDER BY updated_at DESC
            LIMIT 1
        SQL);

        $stmt->execute([':recommendation_id' => $recId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RecommendationFeedback::fromRow($row) : null;
    }

    /**
     * @param list<string> $recommendationIds
     * @return array<string, RecommendationFeedback> Keyed by recommendation_id
     */
    public function findForRecommendations(array $recommendationIds, ?string $accountId = null): array
    {
        if (empty($recommendationIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($recommendationIds), '?'));
        $sql = "SELECT * FROM recommendation_feedback WHERE recommendation_id IN ($placeholders)";
        $params = $recommendationIds;

        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }

        $sql .= ' ORDER BY updated_at DESC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $recId = (string) $row['recommendation_id'];
            if (!isset($map[$recId])) {
                $map[$recId] = RecommendationFeedback::fromRow($row);
            }
        }

        return $map;
    }

    public function saveOutcome(RecommendationOutcome $outcome): void
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            INSERT INTO recommendation_outcomes (
                id,
                recommendation_id,
                household_id,
                outcome_type,
                target_period_start,
                target_period_end,
                predicted_value,
                actual_value,
                evaluation_status,
                success_flag,
                details_json,
                created_at,
                evaluated_at
            ) VALUES (
                :id,
                :recommendation_id,
                :household_id,
                :outcome_type,
                :target_period_start,
                :target_period_end,
                :predicted_value,
                :actual_value,
                :evaluation_status,
                :success_flag,
                :details_json,
                :created_at,
                :evaluated_at
            )
            ON DUPLICATE KEY UPDATE
                actual_value = VALUES(actual_value),
                evaluation_status = VALUES(evaluation_status),
                success_flag = VALUES(success_flag),
                details_json = VALUES(details_json),
                evaluated_at = VALUES(evaluated_at)
        SQL);

        $stmt->execute([
            ':id' => $outcome->id(),
            ':recommendation_id' => $outcome->recommendationId(),
            ':household_id' => $outcome->householdId(),
            ':outcome_type' => $outcome->outcomeType(),
            ':target_period_start' => $outcome->targetPeriodStart()?->format('Y-m-d H:i:s'),
            ':target_period_end' => $outcome->targetPeriodEnd()?->format('Y-m-d H:i:s'),
            ':predicted_value' => $outcome->predictedValue(),
            ':actual_value' => $outcome->actualValue(),
            ':evaluation_status' => $outcome->evaluationStatus(),
            ':success_flag' => $outcome->isSuccess() !== null ? ($outcome->isSuccess() ? 1 : 0) : null,
            ':details_json' => $outcome->details() !== null ? json_encode($outcome->details(), JSON_THROW_ON_ERROR) : null,
            ':created_at' => $outcome->createdAt()->format('Y-m-d H:i:s'),
            ':evaluated_at' => $outcome->evaluatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<RecommendationOutcome>
     */
    public function findOutcomesByRecommendation(string $recId): array
    {
        $stmt = $this->db()->prepare(<<<'SQL'
            SELECT * FROM recommendation_outcomes
            WHERE recommendation_id = :recommendation_id
            ORDER BY created_at ASC
        SQL);

        $stmt->execute([':recommendation_id' => $recId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): RecommendationOutcome => RecommendationOutcome::fromRow($row),
            $rows
        );
    }
}
