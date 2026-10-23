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
use App\Support\Database;
use App\Support\DataEnvironments;
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
    private ?SolarProviderInterface $explicitProvider;
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
        $this->explicitProvider = $provider;

        if ($provider !== null) {
            $this->assertProviderProfileConsistency($provider);
        }
    }

    /**
     * Resolve the authoritative solar provider aligned with the active database profile.
     *
     * Invariants (docs/10 D-059, D-071):
     *  - An active 'real' database profile CAN NEVER select or use FakeSolarProvider.
     *  - An active 'demo' profile uses FakeSolarProvider for safe simulation.
     *  - An active 'test' profile honors test configuration or injected provider.
     *  - Ambiguous or unknown database profiles fail closed.
     */
    public function resolveProvider(): SolarProviderInterface
    {
        $profile = Database::currentProfile();

        if (!in_array($profile, [DataEnvironments::REAL, DataEnvironments::DEMO, DataEnvironments::TEST], true)) {
            throw new RuntimeException("Ambiguous or invalid database profile '{$profile}'. Fail closed.");
        }

        if ($profile === DataEnvironments::REAL) {
            if ($this->explicitProvider !== null) {
                if ($this->explicitProvider instanceof FakeSolarProvider) {
                    throw new RuntimeException("FakeSolarProvider is strictly prohibited when active database profile is 'real'.");
                }
                return $this->explicitProvider;
            }

            // Real profile ALWAYS selects GoodWeSemsPortalAdapter; config overlays cannot switch it to fake
            return new GoodWeSemsPortalAdapter();
        }

        if ($profile === DataEnvironments::DEMO) {
            if ($this->explicitProvider !== null) {
                return $this->explicitProvider;
            }
            return new FakeSolarProvider();
        }

        // TEST profile follows existing test configuration
        if ($this->explicitProvider !== null) {
            return $this->explicitProvider;
        }

        $driver = (string) Config::get('SOLAR_PROVIDER', 'goodwe');
        $dataEnv = (string) Config::get('SPMS_DATA_ENVIRONMENT', 'test');
        if ($driver === 'fake' || $dataEnv === 'demo') {
            return new FakeSolarProvider();
        }

        return new GoodWeSemsPortalAdapter();
    }

    public function provider(): SolarProviderInterface
    {
        return $this->resolveProvider();
    }

    private function assertProviderProfileConsistency(SolarProviderInterface $provider): void
    {
        $profile = Database::currentProfile();
        if ($profile === DataEnvironments::REAL && $provider instanceof FakeSolarProvider) {
            throw new RuntimeException("FakeSolarProvider is strictly prohibited when active database profile is 'real'.");
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
        return $this->resolveProvider()->discoverStations($credentials);
    }

    /**
     * Test connection credentials without persisting them.
     */
    public function testConnection(SolarCredentials $credentials): ConnectionTestResult
    {
        return $this->resolveProvider()->testConnection($credentials);
    }

    /**
     * Test credentials, resolve station/inverter identity, encrypt with AES-256-GCM AEAD
     * (household-bound AAD), and persist connection record.
     *
     * @throws RuntimeException if test probe fails
     */
    public function saveConnection(string $householdId, SolarCredentials $credentials): ConnectionTestResult
    {
        $testResult = $this->resolveProvider()->testConnection($credentials);

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
     * Ingest latest telemetry point and intraday historical curve for a household.
     */
    public function syncHouseholdTelemetry(
        string $householdId,
        ?DateTimeImmutable $date = null,
        bool $catchUpMissingDays = true
    ): ?NormalizedSolarSnapshot {
        $conn = $this->connections->findByHouseholdId($householdId);
        if ($conn === null) {
            throw new RuntimeException("No solar connection configured for household {$householdId}.");
        }

        $activeProfile = Database::currentProfile();
        if (!in_array($activeProfile, [DataEnvironments::REAL, DataEnvironments::DEMO, DataEnvironments::TEST], true)) {
            throw new RuntimeException("Ambiguous or invalid database profile '{$activeProfile}'. Fail closed.");
        }

        $isDemo = ($activeProfile === DataEnvironments::DEMO)
            || ($activeProfile === DataEnvironments::TEST && Config::get('SPMS_DATA_ENVIRONMENT') === 'demo');

        $encryptedRaw = (string) $conn['encrypted_credentials'];
        $isDemoSentinel = ($encryptedRaw === 'DEMO_ENCRYPTED_SEMS_CREDENTIALS' || str_starts_with($encryptedRaw, 'DEMO_'));

        if ($activeProfile === DataEnvironments::REAL && $isDemoSentinel) {
            throw new RuntimeException('Demo credentials sentinel cannot be used in real profile.');
        }

        if ($isDemo && $isDemoSentinel) {
            // Established mock/simulated provider pathway for DEMO environment
            $credentials = new SolarCredentials(
                account: (string) ($conn['account_identifier'] ?? 'demo@spms.local'),
                password: 'demo_simulated_password',
                stationId: (string) ($conn['station_id'] ?? 'DEMO_STATION'),
                inverterSn: $conn['inverter_sn'] !== null ? (string) $conn['inverter_sn'] : null
            );
        } else {
            // Decrypt credentials bound to this householdId (REAL environment strictly enforces AES-256-GCM)
            $decryptedJson = Encryption::decryptString($encryptedRaw, $householdId);
            $payload = json_decode($decryptedJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('Corrupt decrypted solar credentials payload.');
            }

            $credentials = SolarCredentials::fromEncryptedPayload($payload);
        }

        try {
            $rawSnapshot = $this->resolveProvider()->fetchLatestSnapshot($credentials);

            if ($activeProfile === DataEnvironments::REAL && $rawSnapshot->dataNature() === 'SIMULATED') {
                throw new RuntimeException('Simulated telemetry snapshot cannot be persisted in real database profile.');
            }

            // In DEMO mode, enforce simulated data nature to preserve data provenance and never fabricate ACTUAL readings
            if ($isDemo) {
                $snapshot = new NormalizedSolarSnapshot(
                    stationId: $rawSnapshot->stationId(),
                    inverterSn: $rawSnapshot->inverterSn(),
                    measurementTime: $rawSnapshot->measurementTime(),
                    receivedAt: $rawSnapshot->receivedAt(),
                    powerW: $rawSnapshot->powerW(),
                    dailyGenerationKwh: $rawSnapshot->dailyGenerationKwh(),
                    totalGenerationKwh: $rawSnapshot->totalGenerationKwh(),
                    pvPowerW: $rawSnapshot->pvPowerW(),
                    acVoltageV: $rawSnapshot->acVoltageV(),
                    acCurrentA: $rawSnapshot->acCurrentA(),
                    acFrequencyHz: $rawSnapshot->acFrequencyHz(),
                    temperatureC: $rawSnapshot->temperatureC(),
                    gridPowerW: $rawSnapshot->gridPowerW(),
                    consumptionPowerW: $rawSnapshot->consumptionPowerW(),
                    inverterStatus: $rawSnapshot->inverterStatus(),
                    dataNature: 'SIMULATED',
                    dataStatus: $rawSnapshot->dataStatus(),
                    dataQuality: $rawSnapshot->dataQuality()
                );
            } else {
                $snapshot = $rawSnapshot;
            }

            // Capture the latest stored reading BEFORE inserting the new snapshot to detect historical gaps
            $priorStoredReading = $this->readings->findLatestByHouseholdId($householdId);

            $readingId = $this->generateUuid();
            $syncBatchId = $this->generateUuid();

            $this->readings->insert(
                id: $readingId,
                householdId: $householdId,
                snapshot: $snapshot,
                syncBatchId: $syncBatchId
            );

            // Catch-up check: look for uncollected days between the prior stored reading and today (REAL mode only)
            if (!$isDemo && $catchUpMissingDays && $date === null) {
                try {
                    $household = (new \App\Repositories\HouseholdRepository())->findById($householdId);
                    $tzString = $household?->timezone() ?? 'Asia/Kolkata';
                    $tz = new \DateTimeZone($tzString);
                    $todayDt = (new DateTimeImmutable('now'))->setTimezone($tz);
                    $todayYmd = $todayDt->format('Y-m-d');

                    if ($priorStoredReading !== null && isset($priorStoredReading['measurement_time'])) {
                        $lastStoredDate = (new DateTimeImmutable((string) $priorStoredReading['measurement_time'], $tz))->format('Y-m-d');
                        if ($lastStoredDate < $todayYmd) {
                            $checkStart = (new DateTimeImmutable($lastStoredDate, $tz))->modify('+1 day');
                            // Cap catch-up range to max 7 days to avoid unbounded sync latency
                            $maxCatchUpStart = $todayDt->modify('-7 days');
                            if ($checkStart < $maxCatchUpStart) {
                                $checkStart = $maxCatchUpStart;
                            }
                            $curr = $checkStart;
                            while ($curr->format('Y-m-d') < $todayYmd) {
                                $missingPoints = $this->resolveProvider()->fetchIntradayCurve($credentials, $curr);
                                if (!empty($missingPoints)) {
                                    $this->readings->insertMany($householdId, $missingPoints, $syncBatchId);
                                }
                                $curr = $curr->modify('+1 day');
                            }
                        }
                    }
                } catch (Throwable) {
                    // Non-blocking: catch-up error must not prevent current day sync
                }
            }

            // Ingest and persist full intraday historical curve points for the date (REAL mode only)
            if (!$isDemo) {
                $targetDate = $date ?? new DateTimeImmutable('now');
                try {
                    $curvePoints = $this->resolveProvider()->fetchIntradayCurve($credentials, $targetDate);
                    if (!empty($curvePoints)) {
                        $this->readings->insertMany($householdId, $curvePoints, $syncBatchId);
                    }
                } catch (Throwable) {
                    // Non-blocking: curve fetch error must not abort successful live snapshot
                }
            }

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
     * Automatic visit synchronization with concurrency locking and staleness throttle.
     *
     * @return array{synced: bool, status: string, message?: string, age_seconds?: int, snapshot?: ?array, latest?: ?array, error?: string}
     */
    public function syncIfStale(string $householdId, int $maxAgeSeconds = 300): array
    {
        $conn = $this->connections->findByHouseholdId($householdId);
        if ($conn === null) {
            return [
                'synced' => false,
                'status' => 'NOT_CONFIGURED',
                'message' => 'No solar connection configured for this household.',
            ];
        }

        $lastSyncAt = $conn['last_successful_sync_at'] ?? null;
        $now = new DateTimeImmutable('now');

        if ($lastSyncAt !== null) {
            try {
                $lastSyncTime = new DateTimeImmutable((string) $lastSyncAt);
                $ageSeconds = abs($now->getTimestamp() - $lastSyncTime->getTimestamp());
                if ($ageSeconds < $maxAgeSeconds) {
                    return [
                        'synced' => false,
                        'status' => 'FRESH',
                        'age_seconds' => $ageSeconds,
                        'last_sync_at' => (string) $lastSyncAt,
                        'message' => 'Solar telemetry was recently refreshed.',
                        'latest' => $this->getLatestSnapshot($householdId),
                    ];
                }
            } catch (Throwable) {}
        }

        // Concurrency lock using MySQL GET_LOCK to prevent concurrent provider hammering
        $db = \App\Support\Database::connection();
        $lockKey = 'spms_solar_sync_' . substr(hash('sha256', $householdId), 0, 24);
        $acquired = false;

        try {
            $stmt = $db->prepare('SELECT GET_LOCK(:lock_key, 0) as acquired');
            $stmt->execute(['lock_key' => $lockKey]);
            $acquired = (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            $acquired = true; // Fallback if GET_LOCK unsupported
        }

        if (!$acquired) {
            return [
                'synced' => false,
                'status' => 'IN_PROGRESS',
                'message' => 'Solar synchronization is already in progress for this household.',
                'latest' => $this->getLatestSnapshot($householdId),
            ];
        }

        try {
            $snapshot = $this->syncHouseholdTelemetry($householdId, null, true);
            $latest = $this->getLatestSnapshot($householdId);

            return [
                'synced' => true,
                'status' => 'SUCCESS',
                'snapshot' => $snapshot?->toArray(),
                'latest' => $latest,
                'synced_at' => $now->format('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            return [
                'synced' => false,
                'status' => 'ERROR',
                'error' => $e->getMessage(),
                'latest' => $this->getLatestSnapshot($householdId),
            ];
        } finally {
            if ($acquired) {
                try {
                    $relStmt = $db->prepare('SELECT RELEASE_LOCK(:lock_key)');
                    $relStmt->execute(['lock_key' => $lockKey]);
                } catch (Throwable) {}
            }
        }
    }

    /**
     * Backfill historical GoodWe telemetry for past calendar days.
     *
     * @param string $householdId
     * @param int $targetDays Number of past calendar days to backfill (default 35)
     * @param bool $forceRefresh
     * @return array{
     *     status: string,
     *     household_id: string,
     *     target_days: int,
     *     days_ingested: int,
     *     days_skipped: int,
     *     readings_inserted: int,
     *     earliest_date: ?string,
     *     latest_date: ?string,
     *     daily_summary: list<array<string, mixed>>
     * }
     */
    /**
     * Backfill historical telemetry across an explicit date range [startDate, endDate] inclusive.
     *
     * @return array{
     *     status: string,
     *     household_id: string,
     *     start_date: string,
     *     end_date: string,
     *     days_ingested: int,
     *     days_skipped: int,
     *     readings_inserted: int,
     *     earliest_date: ?string,
     *     latest_date: ?string,
     *     daily_summary: list<array<string, mixed>>
     * }
     */
    public function backfillHistoricalDateRange(
        string $householdId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        bool $forceRefresh = false
    ): array {
        $conn = $this->connections->findByHouseholdId($householdId);
        if ($conn === null) {
            throw new RuntimeException("No solar connection configured for household {$householdId}.");
        }

        $decryptedJson = Encryption::decryptString((string) $conn['encrypted_credentials'], $householdId);
        $payload = json_decode($decryptedJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Corrupt decrypted solar credentials payload.');
        }

        $credentials = SolarCredentials::fromEncryptedPayload($payload);
        $household = (new \App\Repositories\HouseholdRepository())->findById($householdId);
        $tzString = $household?->timezone() ?? 'Asia/Kolkata';
        $tz = new \DateTimeZone($tzString);
        $now = (new DateTimeImmutable('now'))->setTimezone($tz);
        $todayStr = $now->format('Y-m-d');

        // Fetch existing counts per day from database to skip already complete days
        $db = \App\Support\Database::connection();
        $stmt = $db->prepare('SELECT DATE(measurement_time) as d, COUNT(*) as cnt FROM solar_readings WHERE household_id = :hid GROUP BY DATE(measurement_time)');
        $stmt->execute(['hid' => $householdId]);
        $existingByDate = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];

        $daysIngested = 0;
        $daysSkipped = 0;
        $totalReadingsInserted = 0;
        $dailySummary = [];
        $earliestDate = null;
        $latestDate = null;

        // Iterate day-by-day from startDate to endDate
        $current = $startDate->setTime(0, 0, 0);
        $end = $endDate->setTime(0, 0, 0);

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');

            if ($earliestDate === null || $dateStr < $earliestDate) {
                $earliestDate = $dateStr;
            }
            if ($latestDate === null || $dateStr > $latestDate) {
                $latestDate = $dateStr;
            }

            $existingCount = (int) ($existingByDate[$dateStr] ?? 0);
            $isToday = ($dateStr === $todayStr);

            // Skip if day already has full telemetry, unless forceRefresh is true or it's today (today can have new points)
            // Note: 2026-01-15 is commissioning day with 29 points.
            $isCompleteDay = ($existingCount >= 100 || ($dateStr === '2026-01-15' && $existingCount >= 25));

            if (!$forceRefresh && !$isToday && $isCompleteDay) {
                $daysSkipped++;
                $dailySummary[] = [
                    'date' => $dateStr,
                    'status' => 'SKIPPED_ALREADY_EXISTS',
                    'reading_count' => $existingCount,
                ];
                $current = $current->modify('+1 day');
                continue;
            }

            try {
                $points = $this->resolveProvider()->fetchIntradayCurve($credentials, $current);
                $count = count($points);
                if ($count > 0) {
                    $syncBatchId = $this->generateUuid();
                    $inserted = $this->readings->insertMany($householdId, $points, $syncBatchId);
                    $totalReadingsInserted += $inserted;
                    $daysIngested++;

                    $powers = array_map(static fn ($p): float => $p->powerW(), $points);
                    $maxPower = max($powers);
                    $lastPoint = end($points);
                    $dailyKwh = $lastPoint !== false ? $lastPoint->dailyGenerationKwh() : 0.0;

                    $dailySummary[] = [
                        'date' => $dateStr,
                        'status' => 'INGESTED',
                        'reading_count' => $count,
                        'max_power_w' => $maxPower,
                        'daily_kwh' => $dailyKwh,
                    ];
                } else {
                    $dailySummary[] = [
                        'date' => $dateStr,
                        'status' => 'NO_PROVIDER_DATA',
                        'reading_count' => 0,
                    ];
                }

                // Respect API limits with polite delay
                usleep(150000); // 150ms
            } catch (Throwable $e) {
                $dailySummary[] = [
                    'date' => $dateStr,
                    'status' => 'ERROR',
                    'error' => $e->getMessage(),
                ];
            }

            $current = $current->modify('+1 day');
        }

        return [
            'status' => 'SUCCESS',
            'household_id' => $householdId,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_ingested' => $daysIngested,
            'days_skipped' => $daysSkipped,
            'readings_inserted' => $totalReadingsInserted,
            'earliest_date' => $earliestDate,
            'latest_date' => $latestDate,
            'daily_summary' => $dailySummary,
        ];
    }

    /**
     * Ingest historical telemetry points for the past N days.
     *
     * @return array<string, mixed>
     */
    public function backfillHistoricalTelemetry(
        string $householdId,
        int $targetDays = 35,
        bool $forceRefresh = false
    ): array {
        $household = (new \App\Repositories\HouseholdRepository())->findById($householdId);
        $tzString = $household?->timezone() ?? 'Asia/Kolkata';
        $tz = new \DateTimeZone($tzString);
        $now = (new DateTimeImmutable('now'))->setTimezone($tz);

        $startDate = $now->modify("-{$targetDays} days");
        $endDate = $now->modify('-1 days');

        $result = $this->backfillHistoricalDateRange($householdId, $startDate, $endDate, $forceRefresh);
        $result['target_days'] = $targetDays;

        return $result;
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

        $household = (new \App\Repositories\HouseholdRepository())->findById($householdId);
        $tzString = $household?->timezone() ?? 'Asia/Kolkata';
        $householdTz = new \DateTimeZone($tzString);
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Read-time freshness evaluation (docs/10 D-060 §5, §8):
        // Instantaneous readings become STALE beyond 3× poll interval (15 min = 900s).
        // Timestamps are interpreted strictly according to the canonical storage contract:
        // If an explicit timezone is present in the string (e.g. ISO-8601 with Z or offset), use it;
        // otherwise interpret as local wall-clock in the household's configured timezone.
        // Never guess between timezones via min(ageTz, ageUtc).
        // Readings in the future (< 0) or older than 900s (> 900s) evaluate to STALE.
        if (isset($latest['measurement_time']) && is_string($latest['measurement_time'])) {
            try {
                $hasExplicitTz = str_contains($latest['measurement_time'], '+')
                    || str_ends_with($latest['measurement_time'], 'Z')
                    || (bool) preg_match('/-\d{2}:\d{2}$/', $latest['measurement_time']);

                if ($hasExplicitTz) {
                    $measurementTime = new DateTimeImmutable($latest['measurement_time']);
                } else {
                    $measurementTime = new DateTimeImmutable($latest['measurement_time'], $householdTz);
                }

                $measUtc = $measurementTime->setTimezone(new \DateTimeZone('UTC'));
                $ageSeconds = $now->getTimestamp() - $measUtc->getTimestamp();

                // Readings in the future (< 0) or beyond 900s (> 900s) are STALE
                if (($ageSeconds < 0 || $ageSeconds > 900) && ($latest['data_status'] ?? '') === 'FRESH') {
                    $latest['data_status'] = 'STALE';
                }
            } catch (Throwable) {
                // If timestamp parsing fails, preserve stored status
            }
        }

        // Electrical metrics (Vac, Iac, Fac, temperature):
        // Intraday curve sync points may lack electrical parameters.
        // Fall back to the last valid electrical observation so stale metrics are honestly preserved
        // with their exact observation timestamp and STALE status rather than coerced to zero or lost.
        if ($latest['ac_voltage_v'] === null && $latest['ac_current_a'] === null && $latest['ac_frequency_hz'] === null) {
            $latestElectrical = $this->readings->findLatestElectricalByHouseholdId($householdId);
            if ($latestElectrical !== null) {
                $latest['ac_voltage_v'] = $latestElectrical['ac_voltage_v'];
                $latest['ac_current_a'] = $latestElectrical['ac_current_a'];
                $latest['ac_frequency_hz'] = $latestElectrical['ac_frequency_hz'];
                if ($latest['temperature_c'] === null) {
                    $latest['temperature_c'] = $latestElectrical['temperature_c'];
                }
                $latest['electrical_measurement_time'] = $latestElectrical['measurement_time'];
                try {
                    $elecHasExplicitTz = str_contains($latestElectrical['measurement_time'], '+')
                        || str_ends_with($latestElectrical['measurement_time'], 'Z')
                        || (bool) preg_match('/-\d{2}:\d{2}$/', $latestElectrical['measurement_time']);

                    if ($elecHasExplicitTz) {
                        $elecTime = new DateTimeImmutable($latestElectrical['measurement_time']);
                    } else {
                        $elecTime = new DateTimeImmutable($latestElectrical['measurement_time'], $householdTz);
                    }

                    $elecUtc = $elecTime->setTimezone(new \DateTimeZone('UTC'));
                    $elecAge = $now->getTimestamp() - $elecUtc->getTimestamp();

                    $latest['electrical_data_status'] = ($elecAge < 0 || $elecAge > 900)
                        ? 'STALE'
                        : ($latestElectrical['data_status'] ?? 'FRESH');
                } catch (Throwable) {
                    $latest['electrical_data_status'] = $latestElectrical['data_status'] ?? 'STALE';
                }
            } else {
                $latest['electrical_measurement_time'] = null;
                $latest['electrical_data_status'] = 'UNAVAILABLE';
            }
        } else {
            $latest['electrical_measurement_time'] = $latest['measurement_time'];
            $latest['electrical_data_status'] = $latest['data_status'] ?? 'FRESH';
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
     * Retrieve real daily GoodWe telemetry summaries for a household.
     *
     * @return list<array<string, mixed>>
     */
    public function getDailySummaries(string $householdId): array
    {
        $rawDays = $this->readings->findDailySummariesByHouseholdId($householdId);
        if (empty($rawDays)) {
            return [];
        }

        $household = (new \App\Repositories\HouseholdRepository())->findById($householdId);
        $tzString = $household?->timezone() ?? 'Asia/Kolkata';
        $tz = new \DateTimeZone($tzString);
        $now = (new DateTimeImmutable('now'))->setTimezone($tz);
        $todayStr = $now->format('Y-m-d');

        $latestSnapshot = $this->getLatestSnapshot($householdId);
        $isLatestStale = ($latestSnapshot['data_status'] ?? '') === 'STALE';
        $latestPowerW = (float) ($latestSnapshot['power_w'] ?? 0.0);

        $results = [];
        foreach ($rawDays as $row) {
            $dateStr = (string) $row['cal_date'];
            $dt = new DateTimeImmutable($dateStr, $tz);
            $isToday = ($dateStr === $todayStr);

            $count = (int) $row['readings_count'];
            $maxW = (float) $row['max_power_w'];
            $dailyKwh = (float) $row['max_daily_kwh'];

            if ($isToday) {
                if ($isLatestStale) {
                    $status = 'STALE';
                    $statusLabel = 'Today / In Progress (Stale)';
                } elseif ($latestPowerW > 0) {
                    $status = 'LIVE';
                    $statusLabel = 'Today / Live';
                } else {
                    $status = 'NO_GENERATION';
                    $statusLabel = 'Today / Standby';
                }
            } else {
                $status = 'COMPLETE';
                $statusLabel = 'Complete';
            }

            $results[] = [
                'date' => $dateStr,
                'date_label' => $dt->format('d M Y'),
                'is_today' => $isToday,
                'generation_kwh' => round($dailyKwh, 2),
                'peak_power_w' => round($maxW, 1),
                'peak_power_kw' => round($maxW / 1000.0, 2),
                'observation_count' => $count,
                'sampling_interval_min' => 5,
                'first_reading' => (string) ($row['first_reading'] ?? ''),
                'last_reading' => (string) ($row['last_reading'] ?? ''),
                'source' => 'GoodWe SEMS',
                'status' => $status,
                'status_label' => $statusLabel,
            ];
        }

        return $results;
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
