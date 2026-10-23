<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Support\Database;
use App\Support\DataEnvironments;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * SPMS O4 — Solar Inverter Reading Persistence (docs/10 D-059, D-060, D-061).
 *
 * Persists and queries high-precision solar telemetry readings.
 * Ensures idempotent ingestion via ON DUPLICATE KEY UPDATE on (household_id, station_id, measurement_time).
 */
final class SolarReadingRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Store a normalized telemetry snapshot.
     */
    public function insert(
        string $id,
        string $householdId,
        NormalizedSolarSnapshot $snapshot,
        string $syncBatchId
    ): void {
        $profile = Database::currentProfile();
        if ($profile === DataEnvironments::REAL && $snapshot->dataNature() === 'SIMULATED') {
            throw new RuntimeException('Simulated telemetry records cannot be persisted in the real database profile.');
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $statement = $this->connection()->prepare(<<<'SQL'
            INSERT INTO solar_readings (
                id, household_id, station_id, inverter_sn,
                measurement_time, received_at, power_w,
                daily_generation_kwh, total_generation_kwh,
                pv_power_w, ac_voltage_v, ac_current_a, ac_frequency_hz,
                temperature_c, grid_power_w, consumption_power_w,
                inverter_status, data_nature, data_status, data_quality,
                sync_batch_id, created_at
            ) VALUES (
                :id, :household_id, :station_id, :inverter_sn,
                :measurement_time, :received_at, :power_w,
                :daily_generation_kwh, :total_generation_kwh,
                :pv_power_w, :ac_voltage_v, :ac_current_a, :ac_frequency_hz,
                :temperature_c, :grid_power_w, :consumption_power_w,
                :inverter_status, :data_nature, :data_status, :data_quality,
                :sync_batch_id, :created_at
            ) ON DUPLICATE KEY UPDATE
                received_at = VALUES(received_at),
                power_w = VALUES(power_w),
                daily_generation_kwh = VALUES(daily_generation_kwh),
                total_generation_kwh = COALESCE(VALUES(total_generation_kwh), total_generation_kwh),
                pv_power_w = VALUES(pv_power_w),
                ac_voltage_v = VALUES(ac_voltage_v),
                ac_current_a = VALUES(ac_current_a),
                ac_frequency_hz = VALUES(ac_frequency_hz),
                temperature_c = VALUES(temperature_c),
                grid_power_w = VALUES(grid_power_w),
                consumption_power_w = VALUES(consumption_power_w),
                inverter_status = VALUES(inverter_status),
                data_nature = VALUES(data_nature),
                data_status = VALUES(data_status),
                data_quality = VALUES(data_quality),
                sync_batch_id = VALUES(sync_batch_id)
        SQL);

        $statement->execute([
            'id' => $id,
            'household_id' => $householdId,
            'station_id' => $snapshot->stationId(),
            'inverter_sn' => $snapshot->inverterSn(),
            'measurement_time' => $snapshot->measurementTime()->format('Y-m-d H:i:s'),
            'received_at' => $snapshot->receivedAt()->format('Y-m-d H:i:s'),
            'power_w' => number_format($snapshot->powerW(), 2, '.', ''),
            'daily_generation_kwh' => number_format($snapshot->dailyGenerationKwh(), 3, '.', ''),
            'total_generation_kwh' => $snapshot->totalGenerationKwh() !== null
                ? number_format($snapshot->totalGenerationKwh(), 3, '.', '')
                : null,
            'pv_power_w' => $snapshot->pvPowerW() !== null
                ? number_format($snapshot->pvPowerW(), 2, '.', '')
                : null,
            'ac_voltage_v' => $snapshot->acVoltageV() !== null
                ? number_format($snapshot->acVoltageV(), 2, '.', '')
                : null,
            'ac_current_a' => $snapshot->acCurrentA() !== null
                ? number_format($snapshot->acCurrentA(), 2, '.', '')
                : null,
            'ac_frequency_hz' => $snapshot->acFrequencyHz() !== null
                ? number_format($snapshot->acFrequencyHz(), 2, '.', '')
                : null,
            'temperature_c' => $snapshot->temperatureC() !== null
                ? number_format($snapshot->temperatureC(), 2, '.', '')
                : null,
            'grid_power_w' => $snapshot->gridPowerW() !== null
                ? number_format($snapshot->gridPowerW(), 2, '.', '')
                : null,
            'consumption_power_w' => $snapshot->consumptionPowerW() !== null
                ? number_format($snapshot->consumptionPowerW(), 2, '.', '')
                : null,
            'inverter_status' => $snapshot->inverterStatus(),
            'data_nature' => $snapshot->dataNature(),
            'data_status' => $snapshot->dataStatus(),
            'data_quality' => $snapshot->dataQuality(),
            'sync_batch_id' => $syncBatchId,
            'created_at' => $now,
        ]);
    }

    /**
     * Store multiple normalized telemetry snapshots in a single transaction.
     *
     * @param list<NormalizedSolarSnapshot> $snapshots
     */
    public function insertMany(
        string $householdId,
        array $snapshots,
        string $syncBatchId
    ): int {
        if (empty($snapshots)) {
            return 0;
        }

        $pdo = $this->connection();
        $inTransaction = $pdo->inTransaction();
        if (!$inTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $count = 0;
            foreach ($snapshots as $snapshot) {
                if (!$snapshot instanceof NormalizedSolarSnapshot) {
                    continue;
                }
                $bytes = random_bytes(16);
                $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
                $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
                $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

                $this->insert($id, $householdId, $snapshot, $syncBatchId);
                $count++;
            }

            if (!$inTransaction) {
                $pdo->commit();
            }

            return $count;
        } catch (\Throwable $e) {
            if (!$inTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retrieve the most recent telemetry snapshot for a household.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestByHouseholdId(string $householdId): ?array
    {
        $statement = $this->connection()->prepare(<<<'SQL'
            SELECT * FROM solar_readings
            WHERE household_id = :household_id
            ORDER BY measurement_time DESC
            LIMIT 1
        SQL);
        $statement->execute(['household_id' => $householdId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Retrieve the most recent telemetry reading containing electrical parameters (Vac, Iac, Fac).
     *
     * @return array<string, mixed>|null
     */
    public function findLatestElectricalByHouseholdId(string $householdId): ?array
    {
        $statement = $this->connection()->prepare(<<<'SQL'
            SELECT * FROM solar_readings
            WHERE household_id = :household_id
              AND (ac_voltage_v IS NOT NULL OR ac_current_a IS NOT NULL OR ac_frequency_hz IS NOT NULL)
            ORDER BY measurement_time DESC
            LIMIT 1
        SQL);
        $statement->execute(['household_id' => $householdId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Retrieve telemetry history within a time window or descending limit.
     *
     * @return list<array<string, mixed>>
     */
    public function findHistoryByHouseholdId(
        string $householdId,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $limit = 100
    ): array {
        $sql = 'SELECT * FROM solar_readings WHERE household_id = :household_id';
        $params = ['household_id' => $householdId];

        if ($from !== null) {
            $sql .= ' AND measurement_time >= :from_time';
            $params['from_time'] = $from->format('Y-m-d H:i:s');
        }

        if ($to !== null) {
            $sql .= ' AND measurement_time <= :to_time';
            $params['to_time'] = $to->format('Y-m-d H:i:s');
        }

        $boundedLimit = max(1, min(1000, $limit));
        $sql .= " ORDER BY measurement_time DESC LIMIT {$boundedLimit}";

        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Count distinct calendar days with solar readings for a household.
     */
    public function countDistinctDaysByHouseholdId(string $householdId): int
    {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(DISTINCT DATE(measurement_time)) FROM solar_readings WHERE household_id = :household_id'
        );
        $statement->execute(['household_id' => $householdId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Count total solar readings for a household.
     */
    public function countByHouseholdId(string $householdId): int
    {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) FROM solar_readings WHERE household_id = :household_id'
        );
        $statement->execute(['household_id' => $householdId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Retrieve the highest power reading observed for a household across all readings.
     */
    public function findMaxPowerW(string $householdId): ?float
    {
        $statement = $this->connection()->prepare(
            'SELECT MAX(power_w) FROM solar_readings WHERE household_id = :household_id'
        );
        $statement->execute(['household_id' => $householdId]);

        $val = $statement->fetchColumn();
        if ($val === false || $val === null) {
            return null;
        }

        $floatVal = (float) $val;

        return $floatVal > 0.0 ? $floatVal : null;
    }

    /**
     * Retrieve daily solar generation summaries grouped by calendar date.
     *
     * @return list<array<string, mixed>>
     */
    public function findDailySummariesByHouseholdId(string $householdId): array
    {
        $sql = <<<'SQL'
            SELECT 
                DATE(measurement_time) as cal_date,
                COUNT(*) as readings_count,
                MIN(TIME(measurement_time)) as first_reading,
                MAX(TIME(measurement_time)) as last_reading,
                MAX(power_w) as max_power_w,
                MAX(daily_generation_kwh) as max_daily_kwh
            FROM solar_readings
            WHERE household_id = :household_id
            GROUP BY DATE(measurement_time)
            ORDER BY cal_date DESC
        SQL;

        $statement = $this->connection()->prepare($sql);
        $statement->execute(['household_id' => $householdId]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
