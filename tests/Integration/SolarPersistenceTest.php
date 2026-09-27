<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Solar\DTO\SolarCredentials;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Security\Encryption;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class SolarPersistenceTest
{
    private static function pdo(): PDO
    {
        return Database::connection();
    }

    public static function run(): array
    {
        AuthTesting::configure();

        return [
            TestResult::check('Solar connection persists and updates with unique household constraint', function (): void {
                $context = AuthTesting::householdFixture('solar-conn-' . bin2hex(random_bytes(4)));
                $householdId = $context['household_id'];
                $repo = new SolarConnectionRepository();

                $rawId = bin2hex(random_bytes(16));
                $connId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($rawId, 4));

                $creds = new SolarCredentials('alice@solar.example', 'secret123', 'station-001', 'GW6000SN');
                $encrypted = Encryption::encrypt(json_encode($creds->toEncryptedPayload()), $householdId);

                $repo->upsert(
                    id: $connId,
                    householdId: $householdId,
                    provider: 'GOODWE_SEMS',
                    stationId: 'station-001',
                    inverterSn: 'GW6000SN',
                    accountIdentifier: $creds->maskedAccount(),
                    encryptedCredentials: $encrypted,
                    connectionStatus: 'CONNECTED'
                );

                $stored = $repo->findByHouseholdId($householdId);
                Assert::true($stored !== null, 'Connection record was not found');
                Assert::same('GOODWE_SEMS', $stored['provider']);
                Assert::same('station-001', $stored['station_id']);
                Assert::same('GW6000SN', $stored['inverter_sn']);
                Assert::same('a***e@solar.example', $stored['account_identifier']);
                Assert::same('CONNECTED', $stored['connection_status']);

                // Updating status works
                $syncTime = new DateTimeImmutable('2026-09-20 12:00:00', new DateTimeZone('UTC'));
                $repo->updateStatus($householdId, 'OFFLINE', $syncTime, 'OFFLINE_NIGHT', 'Inverter asleep at night');

                $updated = $repo->findByHouseholdId($householdId);
                Assert::same('OFFLINE', $updated['connection_status']);
                Assert::same('OFFLINE_NIGHT', $updated['last_error_code']);
                Assert::same('Inverter asleep at night', $updated['last_error_message']);
                Assert::same('2026-09-20 12:00:00', $updated['last_successful_sync_at']);
            }),

            TestResult::check('Solar credentials encrypted for one household cannot be decrypted by another', function (): void {
                $contextA = AuthTesting::householdFixture('solar-a-' . bin2hex(random_bytes(4)));
                $contextB = AuthTesting::householdFixture('solar-b-' . bin2hex(random_bytes(4)));

                $householdA = $contextA['household_id'];
                $householdB = $contextB['household_id'];

                $secret = json_encode(['account' => 'test@solar.org', 'password' => 'super_secret', 'station_id' => 'st1']);
                $encryptedForA = Encryption::encrypt($secret, $householdA);

                // Attempting to decrypt with householdB's ID must fail closed
                Assert::throws(AppException::class, function () use ($encryptedForA, $householdB): void {
                    Encryption::decrypt($encryptedForA, $householdB);
                });

                // Decrypting with correct householdA succeeds
                $restored = Encryption::decrypt($encryptedForA, $householdA);
                Assert::same($secret, $restored);
            }),

            TestResult::check('Solar readings persist with exact DECIMAL precision and smart meter nullability', function (): void {
                $context = AuthTesting::householdFixture('solar-read-' . bin2hex(random_bytes(4)));
                $householdId = $context['household_id'];
                $readingsRepo = new SolarReadingRepository();

                $time = new DateTimeImmutable('2026-09-20 14:30:00', new DateTimeZone('UTC'));
                $now = new DateTimeImmutable('2026-09-20 14:32:00', new DateTimeZone('UTC'));

                $snapshot = new NormalizedSolarSnapshot(
                    stationId: 'st-high-prec',
                    inverterSn: 'GW-INV-888',
                    measurementTime: $time,
                    receivedAt: $now,
                    powerW: 3450.75,
                    dailyGenerationKwh: 18.235,
                    totalGenerationKwh: 4500.125,
                    pvPowerW: 3600.50,
                    acVoltageV: 232.40,
                    acCurrentA: 14.85,
                    acFrequencyHz: 50.02,
                    temperatureC: 41.50,
                    gridPowerW: null, // Smart meter absent
                    consumptionPowerW: null, // Smart meter absent
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH',
                    dataQuality: 'GOOD'
                );

                $rawId = bin2hex(random_bytes(16));
                $readingId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($rawId, 4));
                $syncBatchId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));

                $readingsRepo->insert($readingId, $householdId, $snapshot, $syncBatchId);

                $latest = $readingsRepo->findLatestByHouseholdId($householdId);
                Assert::true($latest !== null, 'Reading was not persisted');
                Assert::same('3450.75', $latest['power_w']);
                Assert::same('18.235', $latest['daily_generation_kwh']);
                Assert::same('4500.125', $latest['total_generation_kwh']);
                Assert::same('3600.50', $latest['pv_power_w']);
                Assert::same('232.40', $latest['ac_voltage_v']);
                Assert::same('14.85', $latest['ac_current_a']);
                Assert::same('50.02', $latest['ac_frequency_hz']);
                Assert::same('41.50', $latest['temperature_c']);
                Assert::true($latest['grid_power_w'] === null, 'grid_power_w must be null');
                Assert::true($latest['consumption_power_w'] === null, 'consumption_power_w must be null');
                Assert::same('NORMAL', $latest['inverter_status']);
                Assert::same('ACTUAL', $latest['data_nature']);
                Assert::same('FRESH', $latest['data_status']);
            }),

            TestResult::check('Solar reading insertion is idempotent on (household_id, station_id, measurement_time)', function (): void {
                $context = AuthTesting::householdFixture('solar-idem-' . bin2hex(random_bytes(4)));
                $householdId = $context['household_id'];
                $readingsRepo = new SolarReadingRepository();

                $time = new DateTimeImmutable('2026-09-20 15:00:00', new DateTimeZone('UTC'));
                $now = new DateTimeImmutable('2026-09-20 15:01:00', new DateTimeZone('UTC'));

                $snapshot1 = new NormalizedSolarSnapshot(
                    stationId: 'st-idem',
                    inverterSn: 'GW-INV-999',
                    measurementTime: $time,
                    receivedAt: $now,
                    powerW: 2500.00,
                    dailyGenerationKwh: 12.000,
                    inverterStatus: 'NORMAL'
                );

                $readingId1 = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $batchId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $readingsRepo->insert($readingId1, $householdId, $snapshot1, $batchId);

                Assert::same(1, $readingsRepo->countByHouseholdId($householdId));

                // Re-inserting the same interval with updated data updates the row without creating a duplicate
                $snapshot2 = new NormalizedSolarSnapshot(
                    stationId: 'st-idem',
                    inverterSn: 'GW-INV-999',
                    measurementTime: $time,
                    receivedAt: $now->modify('+1 minute'),
                    powerW: 2600.00, // corrected power
                    dailyGenerationKwh: 12.050,
                    inverterStatus: 'NORMAL'
                );

                $readingId2 = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $readingsRepo->insert($readingId2, $householdId, $snapshot2, $batchId);

                Assert::same(1, $readingsRepo->countByHouseholdId($householdId));

                $latest = $readingsRepo->findLatestByHouseholdId($householdId);
                Assert::same('2600.00', $latest['power_w']);
                Assert::same('12.050', $latest['daily_generation_kwh']);
            }),

            TestResult::check('SolarService evaluates read-time freshness marking readings older than 15 minutes as STALE', function (): void {
                $context = AuthTesting::householdFixture('solar-stale-' . bin2hex(random_bytes(4)));
                $householdId = $context['household_id'];
                $readingsRepo = new SolarReadingRepository();
                $solarService = new \App\Domain\Solar\SolarService(readings: $readingsRepo);

                // Reading taken 30 minutes ago
                $staleTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-30 minutes');
                $snapshot = new NormalizedSolarSnapshot(
                    stationId: 'st-stale-check',
                    inverterSn: 'GW-INV-OLD',
                    measurementTime: $staleTime,
                    receivedAt: $staleTime,
                    powerW: 0.0,
                    dailyGenerationKwh: 7.100,
                    inverterStatus: 'OFFLINE',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );

                $readingId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $batchId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $readingsRepo->insert($readingId, $householdId, $snapshot, $batchId);

                $latestSnapshot = $solarService->getLatestSnapshot($householdId);
                Assert::true($latestSnapshot !== null, 'Latest snapshot must not be null');
                Assert::same('STALE', $latestSnapshot['data_status'], 'Old reading must dynamically age to STALE per D-060 §5/§8');

                // Newer reading taken 2 minutes ago stays FRESH
                $freshTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 minutes');
                $freshSnapshot = new NormalizedSolarSnapshot(
                    stationId: 'st-stale-check',
                    inverterSn: 'GW-INV-OLD',
                    measurementTime: $freshTime,
                    receivedAt: $freshTime,
                    powerW: 1500.0,
                    dailyGenerationKwh: 8.500,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );

                $readingId2 = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $readingsRepo->insert($readingId2, $householdId, $freshSnapshot, $batchId);

                $latestFresh = $solarService->getLatestSnapshot($householdId);
                Assert::same('FRESH', $latestFresh['data_status'], 'Recent reading (< 15 min) remains FRESH');
            }),
        ];
    }
}
