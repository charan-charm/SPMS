<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use DateTimeImmutable;
use PDO;

/**
 * SPMS O4 — Household Solar Connection Repository (docs/10 D-059, D-061).
 *
 * Persists and queries household solar credentials and integration statuses.
 */
final class SolarConnectionRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHouseholdId(string $householdId): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM household_solar_connections WHERE household_id = :household_id LIMIT 1'
        );
        $statement->execute(['household_id' => $householdId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Create or update the solar connection for a household.
     */
    public function upsert(
        string $id,
        string $householdId,
        string $provider,
        string $stationId,
        ?string $inverterSn,
        string $accountIdentifier,
        string $encryptedCredentials,
        string $connectionStatus,
        ?string $lastErrorCode = null,
        ?string $lastErrorMessage = null
    ): void {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $statement = $this->connection()->prepare(<<<'SQL'
            INSERT INTO household_solar_connections (
                id, household_id, provider, station_id, inverter_sn,
                account_identifier, encrypted_credentials, connection_status,
                last_error_code, last_error_message, created_at, updated_at
            ) VALUES (
                :id, :household_id, :provider, :station_id, :inverter_sn,
                :account_identifier, :encrypted_credentials, :connection_status,
                :last_error_code, :last_error_message, :created_at, :updated_at
            ) ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                station_id = VALUES(station_id),
                inverter_sn = VALUES(inverter_sn),
                account_identifier = VALUES(account_identifier),
                encrypted_credentials = VALUES(encrypted_credentials),
                connection_status = VALUES(connection_status),
                last_error_code = VALUES(last_error_code),
                last_error_message = VALUES(last_error_message),
                updated_at = VALUES(updated_at)
        SQL);

        $statement->execute([
            'id' => $id,
            'household_id' => $householdId,
            'provider' => $provider,
            'station_id' => $stationId,
            'inverter_sn' => $inverterSn,
            'account_identifier' => $accountIdentifier,
            'encrypted_credentials' => $encryptedCredentials,
            'connection_status' => $connectionStatus,
            'last_error_code' => $lastErrorCode,
            'last_error_message' => $lastErrorMessage,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateStatus(
        string $householdId,
        string $connectionStatus,
        ?DateTimeImmutable $lastSyncAt = null,
        ?string $lastErrorCode = null,
        ?string $lastErrorMessage = null
    ): void {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $syncAtStr = $lastSyncAt?->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            UPDATE household_solar_connections
            SET connection_status = :connection_status,
                last_error_code = :last_error_code,
                last_error_message = :last_error_message,
                updated_at = :updated_at
        SQL;

        $params = [
            'household_id' => $householdId,
            'connection_status' => $connectionStatus,
            'last_error_code' => $lastErrorCode,
            'last_error_message' => $lastErrorMessage,
            'updated_at' => $now,
        ];

        if ($syncAtStr !== null) {
            $sql .= ', last_successful_sync_at = :last_sync_at';
            $params['last_sync_at'] = $syncAtStr;
        }

        $sql .= ' WHERE household_id = :household_id';

        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);
    }

    public function deleteByHouseholdId(string $householdId): bool
    {
        $statement = $this->connection()->prepare(
            'DELETE FROM household_solar_connections WHERE household_id = :household_id'
        );
        $statement->execute(['household_id' => $householdId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Retrieve all active connections across households for the CLI collector worker.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllActiveConnections(): array
    {
        $statement = $this->connection()->query(
            "SELECT * FROM household_solar_connections WHERE connection_status IN ('CONNECTED', 'OFFLINE', 'UNKNOWN', 'UNREACHABLE') ORDER BY updated_at ASC"
        );

        $results = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($results) ? $results : [];
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
