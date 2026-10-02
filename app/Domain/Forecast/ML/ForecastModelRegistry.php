<?php

declare(strict_types=1);

namespace App\Domain\Forecast\ML;

use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * F3 ML — Forecast Model Registry (docs/01 §14, docs/02 §2, docs/10 D-040).
 *
 * Persists and retrieves model metadata, backtesting evaluations, and active selection state.
 */
final class ForecastModelRegistry
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Persist an evaluation run.
     *
     * @param array<string, mixed> $evaluation
     */
    public function saveEvaluation(array $evaluation): void
    {
        $id = $evaluation['id'] ?? self::uuid();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $this->connection()->prepare(<<<'SQL'
            INSERT INTO forecast_model_evaluations (
                id, household_id, model_type, model_name, model_version,
                forecast_horizon, training_start, training_end,
                evaluation_start, evaluation_end, training_samples,
                evaluation_samples, mae, rmse, mape, baseline_mae, baseline_rmse,
                status, fallback_reason, feature_set, metrics_json, created_at
            ) VALUES (
                :id, :household_id, :model_type, :model_name, :model_version,
                :forecast_horizon, :training_start, :training_end,
                :evaluation_start, :evaluation_end, :training_samples,
                :evaluation_samples, :mae, :rmse, :mape, :baseline_mae, :baseline_rmse,
                :status, :fallback_reason, :feature_set, :metrics_json, :created_at
            )
        SQL);

        $stmt->execute([
            'id' => $id,
            'household_id' => $evaluation['household_id'],
            'model_type' => $evaluation['model_type'],
            'model_name' => $evaluation['model_name'],
            'model_version' => $evaluation['model_version'],
            'forecast_horizon' => $evaluation['forecast_horizon'],
            'training_start' => $evaluation['training_start'] ?? null,
            'training_end' => $evaluation['training_end'] ?? null,
            'evaluation_start' => $evaluation['evaluation_start'] ?? null,
            'evaluation_end' => $evaluation['evaluation_end'] ?? null,
            'training_samples' => (int) ($evaluation['training_samples'] ?? 0),
            'evaluation_samples' => (int) ($evaluation['evaluation_samples'] ?? 0),
            'mae' => isset($evaluation['mae']) && $evaluation['mae'] !== null ? number_format((float) $evaluation['mae'], 4, '.', '') : null,
            'rmse' => isset($evaluation['rmse']) && $evaluation['rmse'] !== null ? number_format((float) $evaluation['rmse'], 4, '.', '') : null,
            'mape' => isset($evaluation['mape']) && $evaluation['mape'] !== null ? number_format((float) $evaluation['mape'], 4, '.', '') : null,
            'baseline_mae' => isset($evaluation['baseline_mae']) && $evaluation['baseline_mae'] !== null ? number_format((float) $evaluation['baseline_mae'], 4, '.', '') : null,
            'baseline_rmse' => isset($evaluation['baseline_rmse']) && $evaluation['baseline_rmse'] !== null ? number_format((float) $evaluation['baseline_rmse'], 4, '.', '') : null,
            'status' => $evaluation['status'],
            'fallback_reason' => $evaluation['fallback_reason'] ?? null,
            'feature_set' => $evaluation['feature_set'],
            'metrics_json' => isset($evaluation['metrics_json']) && is_array($evaluation['metrics_json'])
                ? json_encode($evaluation['metrics_json'], JSON_THROW_ON_ERROR)
                : ($evaluation['metrics_json'] ?? null),
            'created_at' => $evaluation['created_at'] ?? $now,
        ]);
    }

    /**
     * Find latest active evaluation for a household, model type, and horizon.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestActive(string $householdId, string $modelType, string $horizon): ?array
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            SELECT * FROM forecast_model_evaluations
            WHERE household_id = :household_id
              AND model_type = :model_type
              AND forecast_horizon = :horizon
              AND status = 'ACTIVE'
            ORDER BY created_at DESC
            LIMIT 1
        SQL);

        $stmt->execute([
            'household_id' => $householdId,
            'model_type' => $modelType,
            'horizon' => $horizon,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Find latest evaluation of any status for diagnostics and provenance.
     *
     * @return array<string, mixed>|null
     */
    public function findLatest(string $householdId, string $modelType, string $horizon): ?array
    {
        $stmt = $this->connection()->prepare(<<<'SQL'
            SELECT * FROM forecast_model_evaluations
            WHERE household_id = :household_id
              AND model_type = :model_type
              AND forecast_horizon = :horizon
            ORDER BY created_at DESC
            LIMIT 1
        SQL);

        $stmt->execute([
            'household_id' => $householdId,
            'model_type' => $modelType,
            'horizon' => $horizon,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }
}
