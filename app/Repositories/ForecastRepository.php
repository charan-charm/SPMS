<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use DateTimeImmutable;
use PDO;

/**
 * F3 — Forecast Repository (docs/01 §14, docs/05 §12).
 *
 * Persists and retrieves household forecast snapshots.
 * Enforces strict household_id scoping on all reads and writes.
 */
final class ForecastRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Save a generated forecast record.
     *
     * @param array<string, mixed> $data
     */
    public function save(array $data): void
    {
        $statement = $this->connection()->prepare(<<<'SQL'
            INSERT INTO household_forecasts (
                id, household_id, forecast_type, horizon,
                forecast_start, forecast_end, resolution,
                predicted_value, uncertainty_lower, uncertainty_upper,
                confidence, data_nature, data_quality,
                points_json, limitations_json, provenance_json,
                created_at
            ) VALUES (
                :id, :household_id, :forecast_type, :horizon,
                :forecast_start, :forecast_end, :resolution,
                :predicted_value, :uncertainty_lower, :uncertainty_upper,
                :confidence, :data_nature, :data_quality,
                :points_json, :limitations_json, :provenance_json,
                :created_at
            )
        SQL);

        $statement->execute([
            'id' => $data['id'],
            'household_id' => $data['household_id'],
            'forecast_type' => $data['forecast_type'],
            'horizon' => $data['horizon'],
            'forecast_start' => (new DateTimeImmutable($data['forecast_start']))->format('Y-m-d H:i:s'),
            'forecast_end' => (new DateTimeImmutable($data['forecast_end']))->format('Y-m-d H:i:s'),
            'resolution' => $data['resolution'],
            'predicted_value' => number_format((float) $data['predicted_value'], 3, '.', ''),
            'uncertainty_lower' => isset($data['uncertainty_lower']) && $data['uncertainty_lower'] !== null
                ? number_format((float) $data['uncertainty_lower'], 3, '.', '')
                : null,
            'uncertainty_upper' => isset($data['uncertainty_upper']) && $data['uncertainty_upper'] !== null
                ? number_format((float) $data['uncertainty_upper'], 3, '.', '')
                : null,
            'confidence' => (string) $data['confidence'],
            'data_nature' => (string) $data['data_nature'],
            'data_quality' => (string) $data['data_quality'],
            'points_json' => (string) $data['points_json'],
            'limitations_json' => isset($data['limitations_json']) ? (string) $data['limitations_json'] : null,
            'provenance_json' => (string) $data['provenance_json'],
            'created_at' => $data['created_at'] ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Retrieve the most recent forecast for a household, type, and horizon.
     *
     * @return array<string, mixed>|null
     */
    public function findLatest(string $householdId, string $forecastType, string $horizon): ?array
    {
        $statement = $this->connection()->prepare(<<<'SQL'
            SELECT * FROM household_forecasts
            WHERE household_id = :household_id
              AND forecast_type = :forecast_type
              AND horizon = :horizon
            ORDER BY created_at DESC
            LIMIT 1
        SQL);

        $statement->execute([
            'household_id' => $householdId,
            'forecast_type' => $forecastType,
            'horizon' => $horizon,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Delete expired forecasts for cleanup.
     */
    public function deleteExpired(string $householdId, DateTimeImmutable $before): int
    {
        $statement = $this->connection()->prepare(<<<'SQL'
            DELETE FROM household_forecasts
            WHERE household_id = :household_id
              AND created_at < :before_time
        SQL);

        $statement->execute([
            'household_id' => $householdId,
            'before_time' => $before->format('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount();
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
