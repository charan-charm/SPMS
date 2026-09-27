<?php

declare(strict_types=1);

namespace App\Domain\Solar;

use App\Domain\Solar\Contracts\SolarProviderInterface;
use App\Domain\Solar\DTO\ConnectionTestResult;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationService;
use App\Domain\Solar\DTO\SolarCredentials;
use App\Integrations\GoodWe\FakeSolarProvider;
use App\Integrations\GoodWe\GoodWeSemsPortalAdapter;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Config;
use App\Support\Security\Encryption;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * SPMS O4 — Solar Domain Service (docs/10 D-059, D-060, D-061).
 *
 * Orchestrates solar provider connectivity, AEAD-encrypted credential persistence,
 * telemetry ingestion, and derived readiness state.
 */
final class SolarService
{
    private SolarConnectionRepository $connections;
    private SolarReadingRepository $readings;
    private SolarProviderInterface $provider;
    private NotificationService $notifications;

    public function __construct(
        ?SolarConnectionRepository $connections = null,
        ?SolarReadingRepository $readings = null,
        ?SolarProviderInterface $provider = null,
        ?NotificationService $notifications = null
    ) {
        $this->connections = $connections ?? new SolarConnectionRepository();
        $this->readings = $readings ?? new SolarReadingRepository();
        $this->notifications = $notifications ?? new NotificationService();

        if ($provider !== null) {
            $this->provider = $provider;
        } else {
            $providerDriver = (string) Config::get('SOLAR_PROVIDER', 'goodwe');
            if ($providerDriver === 'fake') {
                $this->provider = new FakeSolarProvider();
            } else {
                $this->provider = new GoodWeSemsPortalAdapter();
            }
        }
    }

    /**
     * Get safe connection status for a household (credentials omitted/masked).
     *
     * @return array<string, mixed>
     */
    public function getConnection(string $householdId): array
    {
        $conn = $this->connections->findByHouseholdId($householdId);
        if ($conn === null) {
            return [
                'configured' => false,
                'provider' => 'GOODWE_SEMS',
                'station_id' => null,
                'inverter_sn' => null,
                'account_identifier' => null,
                'connection_status' => 'NOT_CONFIGURED',
                'last_successful_sync_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ];
        }

        return [
            'configured' => true,
            'provider' => (string) $conn['provider'],
            'station_id' => (string) $conn['station_id'],
            'inverter_sn' => $conn['inverter_sn'] !== null ? (string) $conn['inverter_sn'] : null,
            'account_identifier' => (string) $conn['account_identifier'],
            'connection_status' => (string) $conn['connection_status'],
            'last_successful_sync_at' => $conn['last_successful_sync_at'] !== null
                ? (string) $conn['last_successful_sync_at']
                : null,
            'last_error_code' => $conn['last_error_code'] !== null ? (string) $conn['last_error_code'] : null,
            'last_error_message' => $conn['last_error_message'] !== null ? (string) $conn['last_error_message'] : null,
            'updated_at' => (string) $conn['updated_at'],
        ];
    }

    /**
     * Discover power stations associated with provider credentials without persisting.
     *
     * @return list<array{station_id: string, station_name: string, capacity_kw: ?float}>
     */
    public function discoverStations(SolarCredentials $credentials): array
    {
        return $this->provider->discoverStations($credentials);
    }

    /**
     * Test connection credentials without persisting them.
     */
    public function testConnection(SolarCredentials $credentials): ConnectionTestResult
    {
        return $this->provider->testConnection($credentials);
    }

    /**
     * Test credentials, resolve station/inverter identity, encrypt with AES-256-GCM AEAD
     * (household-bound AAD), and persist connection record.
     *
     * @throws RuntimeException if test probe fails
     */
    public function saveConnection(string $householdId, SolarCredentials $credentials): ConnectionTestResult
    {
        $testResult = $this->provider->testConnection($credentials);

        if ($testResult->status() === 'MULTIPLE_STATIONS') {
            throw new RuntimeException('Multiple power stations found. Please select which station to connect.');
        }

        if (!$testResult->isSuccess()) {
            throw new RuntimeException(
                $testResult->errorMessage() ?? 'Failed to connect to solar provider with supplied credentials.'
            );
        }

        // Resolve station ID from credentials or from probe result
        $effectiveStationId = $credentials->stationId() ?? $testResult->stationId();
        if ($effectiveStationId === null || trim($effectiveStationId) === '') {
            throw new RuntimeException('Could not resolve a valid power station ID for this account.');
        }

        // If inverter SN was resolved from the station probe, record it
        $effectiveInverterSn = $credentials->inverterSn() ?? $testResult->inverterSn();

        // Build finalized credentials with resolved station ID and inverter SN
        $updatedCredentials = $credentials
            ->withStationId($effectiveStationId)
            ->withInverterSn($effectiveInverterSn);

        // Enforce strict persistence invariant
        $updatedCredentials->assertPersistable();

        // AEAD AES-256-GCM encrypt credentials, binding them cryptographically to this householdId
        $serialized = json_encode($updatedCredentials->toEncryptedPayload(), JSON_THROW_ON_ERROR);
        $encrypted = Encryption::encryptString($serialized, $householdId);

        $connId = $this->generateUuid();
        $initialStatus = $testResult->status() === 'OFFLINE' ? 'OFFLINE' : 'CONNECTED';

        $this->connections->upsert(
            id: $connId,
            householdId: $householdId,
            provider: 'GOODWE_SEMS',
            stationId: $effectiveStationId,
            inverterSn: $effectiveInverterSn,
            accountIdentifier: $updatedCredentials->maskedAccount(),
            encryptedCredentials: $encrypted,
            connectionStatus: $initialStatus
        );

        try {
            $this->notifications->notify(
                householdId: $householdId,
                type: Notification::TYPE_SOLAR,
                severity: Notification::SEVERITY_SUCCESS,
                title: 'GoodWe Solar Inverter Connected',
                message: "Successfully connected GoodWe SEMS inverter (Station ID: {$effectiveStationId}).",
                relatedResourceType: 'SOLAR',
                relatedResourceId: $effectiveStationId,
                eventKey: "solar_connected_{$householdId}_{$effectiveStationId}"
            );
        } catch (Throwable) {
            // Notification dispatch never fails connection save
        }

        // Dispatch immediate telemetry snapshot ingest
        try {
            $this->syncHouseholdTelemetry($householdId);
        } catch (Throwable) {
            // Initial sync failure does not fail connection save if probe succeeded
        }

        return $testResult;
    }

    /**
     * Disconnect solar integration for household.
     */
    public function disconnect(string $householdId): bool
    {
        return $this->connections->deleteByHouseholdId($householdId);
    }

    /**
     * Ingest latest 5-minute telemetry point for a household.
     */
    public function syncHouseholdTelemetry(string $householdId): ?NormalizedSolarSnapshot
    {
        $conn = $this->connections->findByHouseholdId($householdId);
        if ($conn === null) {
            throw new RuntimeException("No solar connection configured for household {$householdId}.");
        }

        // Decrypt credentials bound to this householdId
        $decryptedJson = Encryption::decryptString((string) $conn['encrypted_credentials'], $householdId);
        $payload = json_decode($decryptedJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Corrupt decrypted solar credentials payload.');
        }

        $credentials = SolarCredentials::fromEncryptedPayload($payload);

        try {
            $snapshot = $this->provider->fetchLatestSnapshot($credentials);

            $readingId = $this->generateUuid();
            $syncBatchId = $this->generateUuid();

            $this->readings->insert(
                id: $readingId,
                householdId: $householdId,
                snapshot: $snapshot,
                syncBatchId: $syncBatchId
            );

            $status = $snapshot->inverterStatus() === 'OFFLINE' ? 'OFFLINE' : 'CONNECTED';
            $this->connections->updateStatus(
                householdId: $householdId,
                connectionStatus: $status,
                lastSyncAt: new DateTimeImmutable('now')
            );

            return $snapshot;
        } catch (Throwable $e) {
            $this->connections->updateStatus(
                householdId: $householdId,
                connectionStatus: 'UNREACHABLE',
                lastErrorCode: 'SYNC_ERROR',
                lastErrorMessage: $e->getMessage()
            );

            try {
                $today = (new DateTimeImmutable('now'))->format('Y-m-d');
                $this->notifications->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_SOLAR,
                    severity: Notification::SEVERITY_WARNING,
                    title: 'Solar Telemetry Sync Failed',
                    message: "Telemetry synchronization with GoodWe SEMS failed: {$e->getMessage()}",
                    relatedResourceType: 'SOLAR',
                    relatedResourceId: (string) ($conn['station_id'] ?? ''),
                    eventKey: "solar_sync_failed_{$householdId}_{$today}"
                );
            } catch (Throwable) {
                // Non-blocking notification dispatch
            }

            throw $e;
        }
    }

    /**
     * Retrieve the most recent telemetry point for a household.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestSnapshot(string $householdId): ?array
    {
        $latest = $this->readings->findLatestByHouseholdId($householdId);
        if ($latest === null) {
            return null;
        }

        // Read-time freshness evaluation (docs/10 D-060 §5, §8):
        // Instantaneous readings become STALE beyond 3× poll interval (15 min = 900s).
        // Never silently upgrade stale readings to fresh.
        if (isset($latest['measurement_time']) && is_string($latest['measurement_time'])) {
            try {
                $measurementTime = new DateTimeImmutable($latest['measurement_time']);
                $now = new DateTimeImmutable('now');
                $ageSeconds = abs($now->getTimestamp() - $measurementTime->getTimestamp());
                if ($ageSeconds > 900 && ($latest['data_status'] ?? '') === 'FRESH') {
                    $latest['data_status'] = 'STALE';
                }
            } catch (Throwable) {
                // If timestamp parsing fails, preserve stored status
            }
        }

        return $latest;
    }

    /**
     * Retrieve telemetry history.
     *
     * @return list<array<string, mixed>>
     */
    public function getHistory(
        string $householdId,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $limit = 100
    ): array {
        return $this->readings->findHistoryByHouseholdId($householdId, $from, $to, $limit);
    }

    /**
     * Derive honest solar readiness state.
     */
    public function getSolarReadinessStatus(string $householdId): string
    {
        $conn = $this->connections->findByHouseholdId($householdId);
        if ($conn === null) {
            return 'NOT_CONFIGURED';
        }

        $status = (string) ($conn['connection_status'] ?? 'UNKNOWN');
        if ($status === 'CONNECTED') {
            return 'CONNECTED';
        }
        if ($status === 'OFFLINE') {
            return 'OFFLINE';
        }

        return 'ERROR';
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
