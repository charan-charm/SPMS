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

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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

                $household = (new \App\Repositories\HouseholdRepository())->findById($householdId);
                $hhTz = new DateTimeZone($household?->timezone() ?? 'Asia/Kolkata');

                // Reading taken 30 minutes ago
                $staleTime = (new DateTimeImmutable('now', $hhTz))->modify('-30 minutes');
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
                $freshTime = (new DateTimeImmutable('now', $hhTz))->modify('-2 minutes');
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

                // Stale electrical telemetry honesty: curve point lacks Vac/Iac/Fac, falls back to last valid observation
                $electricalTime = (new DateTimeImmutable('now', $hhTz))->modify('-30 minutes');
                $elecSnapshot = new NormalizedSolarSnapshot(
                    stationId: 'st-stale-check',
                    inverterSn: 'GW-INV-OLD',
                    measurementTime: $electricalTime,
                    receivedAt: $electricalTime,
                    powerW: 2200.0,
                    dailyGenerationKwh: 4.100,
                    acVoltageV: 257.50,
                    acCurrentA: 8.70,
                    acFrequencyHz: 49.96,
                    temperatureC: 66.60,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $elecId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $readingsRepo->insert($elecId, $householdId, $elecSnapshot, $batchId);

                // Subsequent curve point at current time with null electrical metrics
                $curveTime = (new DateTimeImmutable('now', $hhTz))->modify('-1 minute');
                $curveSnapshot = new NormalizedSolarSnapshot(
                    stationId: 'st-stale-check',
                    inverterSn: 'GW-INV-OLD',
                    measurementTime: $curveTime,
                    receivedAt: $curveTime,
                    powerW: 963.0,
                    dailyGenerationKwh: 4.100,
                    acVoltageV: null,
                    acCurrentA: null,
                    acFrequencyHz: null,
                    temperatureC: null,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $curveId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $readingsRepo->insert($curveId, $householdId, $curveSnapshot, $batchId);

                $latestWithFallback = $solarService->getLatestSnapshot($householdId);
                Assert::same('257.50', $latestWithFallback['ac_voltage_v'], 'Must preserve last valid AC voltage, never 0 V');
                Assert::same('8.70', $latestWithFallback['ac_current_a'], 'Must preserve last valid AC current, never 0 A');
                Assert::same('49.96', $latestWithFallback['ac_frequency_hz'], 'Must preserve last valid frequency, never 0 Hz');
                Assert::same('STALE', $latestWithFallback['electrical_data_status'], 'Old electrical observation is marked STALE');
                Assert::same($electricalTime->format('Y-m-d H:i:s'), $latestWithFallback['electrical_measurement_time'], 'Must retain exact observation timestamp');
            }),

            TestResult::check('SolarService: Timezone-aware freshness evaluation across Asia/Kolkata, UTC, boundary, future, and missing cases', function (): void {
                $readingsRepo = new SolarReadingRepository();
                $solarService = new \App\Domain\Solar\SolarService(readings: $readingsRepo);
                $db = \App\Support\Database::connection();
                $batchId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $tzKolkata = new DateTimeZone('Asia/Kolkata');

                // Case 1: Recent Asia/Kolkata wall-clock timestamp (2 minutes old) -> FRESH
                $context1 = AuthTesting::householdFixture('solar-tz1-' . bin2hex(random_bytes(4)));
                $householdId1 = $context1['household_id'];
                $nowKolkata = (new DateTimeImmutable('now', $tzKolkata))->modify('-2 minutes');
                $reading1 = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $nowKolkata,
                    receivedAt: $nowKolkata,
                    powerW: 1200.0,
                    dailyGenerationKwh: 3.5,
                    acVoltageV: 230.50,
                    acCurrentA: 5.20,
                    acFrequencyHz: 50.01,
                    temperatureC: 45.2,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId1, $reading1, $batchId);

                $latest1 = $solarService->getLatestSnapshot($householdId1);
                Assert::same('FRESH', $latest1['data_status'], 'Reading 2 minutes old in Asia/Kolkata must evaluate to FRESH');
                Assert::same('FRESH', $latest1['electrical_data_status'], 'Electrical status must also be FRESH');

                // Case 2: Recent UTC timestamp for household configured with UTC timezone -> FRESH
                $context2 = AuthTesting::householdFixture('solar-tz2-' . bin2hex(random_bytes(4)));
                $householdId2 = $context2['household_id'];
                $db->prepare('UPDATE households SET timezone = :tz WHERE id = :id')->execute(['tz' => 'UTC', 'id' => $householdId2]);
                $tzUtc = new DateTimeZone('UTC');
                $nowUtcReading = (new DateTimeImmutable('now', $tzUtc))->modify('-2 minutes');
                $reading2 = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $nowUtcReading,
                    receivedAt: $nowUtcReading,
                    powerW: 1500.0,
                    dailyGenerationKwh: 4.0,
                    acVoltageV: 235.0,
                    acCurrentA: 6.5,
                    acFrequencyHz: 50.0,
                    temperatureC: 40.0,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId2, $reading2, $batchId);

                $latest2 = $solarService->getLatestSnapshot($householdId2);
                Assert::same('FRESH', $latest2['data_status'], 'Reading 2 minutes old in UTC household contract must evaluate to FRESH');

                // Case 3: Genuinely stale timestamp (25 minutes old) -> STALE
                $context3 = AuthTesting::householdFixture('solar-tz3-' . bin2hex(random_bytes(4)));
                $householdId3 = $context3['household_id'];
                $staleTime = (new DateTimeImmutable('now', $tzKolkata))->modify('-25 minutes');
                $reading3 = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $staleTime,
                    receivedAt: $staleTime,
                    powerW: 900.0,
                    dailyGenerationKwh: 3.0,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId3, $reading3, $batchId);

                $latest3 = $solarService->getLatestSnapshot($householdId3);
                Assert::same('STALE', $latest3['data_status'], 'Reading 25 minutes old (> 900s) must evaluate to STALE');

                // Case 4: Exact freshness boundary: 900s stays FRESH; 901s becomes STALE
                $context4a = AuthTesting::householdFixture('solar-tz4a-' . bin2hex(random_bytes(4)));
                $householdId4a = $context4a['household_id'];
                $boundaryExact = (new DateTimeImmutable('now', $tzKolkata))->modify('-900 seconds');
                $reading4a = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $boundaryExact,
                    receivedAt: $boundaryExact,
                    powerW: 1000.0,
                    dailyGenerationKwh: 3.1,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId4a, $reading4a, $batchId);
                $latest4a = $solarService->getLatestSnapshot($householdId4a);
                Assert::same('FRESH', $latest4a['data_status'], 'Reading at exactly 900s boundary must evaluate to FRESH');

                $context4b = AuthTesting::householdFixture('solar-tz4b-' . bin2hex(random_bytes(4)));
                $householdId4b = $context4b['household_id'];
                $boundaryExpired = (new DateTimeImmutable('now', $tzKolkata))->modify('-901 seconds');
                $reading4b = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $boundaryExpired,
                    receivedAt: $boundaryExpired,
                    powerW: 1000.0,
                    dailyGenerationKwh: 3.1,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId4b, $reading4b, $batchId);
                $latest4b = $solarService->getLatestSnapshot($householdId4b);
                Assert::same('STALE', $latest4b['data_status'], 'Reading at 901s boundary must evaluate to STALE');

                // Case 5: Future timestamps (> now, negative age) -> STALE
                $context5 = AuthTesting::householdFixture('solar-tz5-' . bin2hex(random_bytes(4)));
                $householdId5 = $context5['household_id'];
                $futureTime = (new DateTimeImmutable('now', $tzKolkata))->modify('+10 minutes');
                $reading5 = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $futureTime,
                    receivedAt: $futureTime,
                    powerW: 2000.0,
                    dailyGenerationKwh: 5.0,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId5, $reading5, $batchId);
                $latest5 = $solarService->getLatestSnapshot($householdId5);
                Assert::same('STALE', $latest5['data_status'], 'Reading in the future (+10 min) must evaluate to STALE');

                // Case 6: Missing readings / non-existent household -> returns null (never zero)
                $nonExistentHousehold = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $emptySnapshot = $solarService->getLatestSnapshot($nonExistentHousehold);
                Assert::true($emptySnapshot === null, 'Missing readings must return null, never zero');

                // Case 7: Electrical telemetry freshness: stale electrical metrics with fresh power reading
                $context7 = AuthTesting::householdFixture('solar-tz7-' . bin2hex(random_bytes(4)));
                $householdId7 = $context7['household_id'];
                $elecTimeStale = (new DateTimeImmutable('now', $tzKolkata))->modify('-35 minutes');
                $reading7a = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $elecTimeStale,
                    receivedAt: $elecTimeStale,
                    powerW: 2100.0,
                    dailyGenerationKwh: 4.5,
                    acVoltageV: 240.0,
                    acCurrentA: 8.0,
                    acFrequencyHz: 50.0,
                    temperatureC: 50.0,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId7, $reading7a, $batchId);

                $curveTimeFresh = (new DateTimeImmutable('now', $tzKolkata))->modify('-2 minutes');
                $reading7b = new NormalizedSolarSnapshot(
                    stationId: 'st-tz-test',
                    inverterSn: 'GW-INV-TZ',
                    measurementTime: $curveTimeFresh,
                    receivedAt: $curveTimeFresh,
                    powerW: 1800.0,
                    dailyGenerationKwh: 4.8,
                    acVoltageV: null,
                    acCurrentA: null,
                    acFrequencyHz: null,
                    temperatureC: null,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId7, $reading7b, $batchId);

                $latest7 = $solarService->getLatestSnapshot($householdId7);
                Assert::same('FRESH', $latest7['data_status'], 'Curve reading 2 min old is FRESH');
                Assert::same('STALE', $latest7['electrical_data_status'], '35 min old electrical observation falls back to STALE');
                Assert::same('240.00', $latest7['ac_voltage_v'], 'Preserves valid AC voltage');
            }),

            TestResult::check('SolarService: Catch-up ordering detects prior missing dates before inserting current reading', function (): void {
                $context = AuthTesting::householdFixture('solar-catchup-' . bin2hex(random_bytes(4)));
                $householdId = $context['household_id'];
                $readingsRepo = new SolarReadingRepository();

                $tzKolkata = new DateTimeZone('Asia/Kolkata');
                $now = new DateTimeImmutable('now', $tzKolkata);

                // Insert prior reading from 3 days ago
                $threeDaysAgo = $now->modify('-3 days');
                $priorSnapshot = new NormalizedSolarSnapshot(
                    stationId: 'st-catchup-01',
                    inverterSn: 'GW-CATCH-01',
                    measurementTime: $threeDaysAgo,
                    receivedAt: $threeDaysAgo,
                    powerW: 1000.0,
                    dailyGenerationKwh: 5.0,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH'
                );
                $readingsRepo->insert(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)), $householdId, $priorSnapshot, self::uuid());

                // Prior stored reading is confirmed to be 3 days ago
                $priorStored = $readingsRepo->findLatestByHouseholdId($householdId);
                Assert::true($priorStored !== null, 'Prior reading must exist');
                Assert::same($threeDaysAgo->format('Y-m-d H:i:s'), $priorStored['measurement_time']);

                // Setup connection credentials for solar service
                $connRepo = new SolarConnectionRepository();
                $creds = new SolarCredentials('catchup@solar.example', 'secret123', 'st-catchup-01', 'GW-CATCH-01');
                $encrypted = Encryption::encrypt(json_encode($creds->toEncryptedPayload()), $householdId);
                $connRepo->upsert(
                    id: vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)),
                    householdId: $householdId,
                    provider: 'GOODWE_SEMS',
                    stationId: 'st-catchup-01',
                    inverterSn: 'GW-CATCH-01',
                    accountIdentifier: $creds->maskedAccount(),
                    encryptedCredentials: $encrypted,
                    connectionStatus: 'CONNECTED'
                );

                // Mock provider that tracks which curve dates were queried
                $queriedDates = [];
                $mockProvider = new class($queriedDates, $now) implements \App\Domain\Solar\Contracts\SolarProviderInterface {
                    public function __construct(public array &$dates, public DateTimeImmutable $now) {}
                    public function testConnection(SolarCredentials $c): \App\Domain\Solar\DTO\ConnectionTestResult {
                        return new \App\Domain\Solar\DTO\ConnectionTestResult(true, 'OK', [], null, null, new DateTimeImmutable());
                    }
                    public function discoverStations(SolarCredentials $c): array { return []; }
                    public function fetchLatestSnapshot(SolarCredentials $c): NormalizedSolarSnapshot {
                        return new NormalizedSolarSnapshot('st-catchup-01', 'GW-CATCH-01', $this->now, $this->now, 2000.0, 10.0, inverterStatus: 'NORMAL');
                    }
                    public function fetchIntradayCurve(SolarCredentials $c, DateTimeImmutable $date): array {
                        $this->dates[] = $date->format('Y-m-d');
                        return [
                            new NormalizedSolarSnapshot('st-catchup-01', 'GW-CATCH-01', $date->setTime(12, 0, 0), $date->setTime(12, 0, 0), 1500.0, 6.0, inverterStatus: 'NORMAL')
                        ];
                    }
                    public function fetchHistoricalDailyTotals(SolarCredentials $c, DateTimeImmutable $from, DateTimeImmutable $to): array {
                        return [];
                    }
                };

                $solarService = new \App\Domain\Solar\SolarService(
                    connections: $connRepo,
                    readings: $readingsRepo,
                    provider: $mockProvider
                );

                // Run syncHouseholdTelemetry
                $solarService->syncHouseholdTelemetry($householdId);

                // Verify that catch-up caught the intervening uncollected days
                $day2Ago = $now->modify('-2 days')->format('Y-m-d');
                $day1Ago = $now->modify('-1 day')->format('Y-m-d');
                Assert::true(in_array($day2Ago, $queriedDates, true), "Catch-up must query $day2Ago");
                Assert::true(in_array($day1Ago, $queriedDates, true), "Catch-up must query $day1Ago");
            }),

            TestResult::check('DEMO environment uses mock pathway with DEMO sentinel and preserves SIMULATED data nature', function (): void {
                $context = AuthTesting::householdFixture('solar-demo-' . bin2hex(random_bytes(4)));
                $householdId = $context['household_id'];
                $connRepo = new SolarConnectionRepository();
                $readingsRepo = new SolarReadingRepository();

                $connRepo->upsert(
                    id: self::uuid(),
                    householdId: $householdId,
                    provider: 'GOODWE_SEMS',
                    stationId: 'DEMO_STATION_TEST',
                    inverterSn: 'GW-DEMO-TEST',
                    accountIdentifier: 'demo.test@spms.local',
                    encryptedCredentials: 'DEMO_ENCRYPTED_SEMS_CREDENTIALS',
                    connectionStatus: 'CONNECTED'
                );

                \App\Support\Config::overrideForTesting(['SPMS_DATA_ENVIRONMENT' => 'demo']);

                try {
                    $solarService = new \App\Domain\Solar\SolarService(
                        connections: $connRepo,
                        readings: $readingsRepo
                    );

                    $snapshot = $solarService->syncHouseholdTelemetry($householdId);

                    Assert::true($snapshot !== null);
                    Assert::same('SIMULATED', $snapshot->dataNature());

                    $latest = $readingsRepo->findLatestByHouseholdId($householdId);
                    Assert::true($latest !== null);
                    Assert::same('SIMULATED', $latest['data_nature']);
                } finally {
                    \App\Support\Config::overrideForTesting(['SPMS_DATA_ENVIRONMENT' => 'test']);
                }
            }),

            TestResult::check('REAL environment strictly rejects DEMO sentinel and enforces AEAD decryption', function (): void {
                $context = AuthTesting::householdFixture('solar-real-guard-' . bin2hex(random_bytes(4)));
                $householdId = $context['household_id'];
                $connRepo = new SolarConnectionRepository();
                $readingsRepo = new SolarReadingRepository();

                $connRepo->upsert(
                    id: self::uuid(),
                    householdId: $householdId,
                    provider: 'GOODWE_SEMS',
                    stationId: 'REAL_STATION_TEST',
                    inverterSn: 'GW-REAL-TEST',
                    accountIdentifier: 'real.test@spms.local',
                    encryptedCredentials: 'DEMO_ENCRYPTED_SEMS_CREDENTIALS',
                    connectionStatus: 'CONNECTED'
                );

                \App\Support\Config::overrideForTesting(['SPMS_DATA_ENVIRONMENT' => 'real']);

                $failed = false;
                try {
                    $solarService = new \App\Domain\Solar\SolarService(
                        connections: $connRepo,
                        readings: $readingsRepo
                    );

                    $solarService->syncHouseholdTelemetry($householdId);
                } catch (\Throwable $e) {
                    $failed = true;
                    Assert::true(
                        str_contains($e->getMessage(), 'payload') || str_contains($e->getMessage(), 'decryption') || str_contains($e->getMessage(), 'Malformed'),
                        'Must reject invalid AEAD payload in real mode: ' . $e->getMessage()
                    );
                } finally {
                    \App\Support\Config::overrideForTesting(['SPMS_DATA_ENVIRONMENT' => 'test']);
                }

                Assert::true($failed, 'REAL environment must never accept unencrypted DEMO sentinel credentials');
            }),

            TestResult::check('findAllActiveConnections includes UNREACHABLE connections and excludes inactive/disconnected statuses', function (): void {
                $connRepo = new SolarConnectionRepository();

                $fixtures = [
                    'active-conn' => 'CONNECTED',
                    'offline-conn' => 'OFFLINE',
                    'unknown-conn' => 'UNKNOWN',
                    'unreach-conn' => 'UNREACHABLE',
                    'disconn-conn' => 'DISCONNECTED',
                    'disabled-conn' => 'DISABLED',
                ];

                $householdIds = [];
                foreach ($fixtures as $prefix => $status) {
                    $context = AuthTesting::householdFixture('sol-filter-' . $prefix . '-' . bin2hex(random_bytes(3)));
                    $hid = $context['household_id'];
                    $householdIds[$prefix] = $hid;

                    $creds = new SolarCredentials('test@example.com', 'pwd123', 'st-' . $prefix);
                    $encrypted = Encryption::encrypt(json_encode($creds->toEncryptedPayload()), $hid);

                    $connRepo->upsert(
                        id: self::uuid(),
                        householdId: $hid,
                        provider: 'GOODWE_SEMS',
                        stationId: 'st-' . $prefix,
                        inverterSn: 'sn-' . $prefix,
                        accountIdentifier: $creds->maskedAccount(),
                        encryptedCredentials: $encrypted,
                        connectionStatus: $status
                    );
                }

                $activeConns = $connRepo->findAllActiveConnections();
                $activeHouseholdIds = array_map(static fn (array $c) => (string) $c['household_id'], $activeConns);

                // CONNECTED, OFFLINE, UNKNOWN, and UNREACHABLE must be included
                Assert::true(in_array($householdIds['active-conn'], $activeHouseholdIds, true), 'CONNECTED must be active');
                Assert::true(in_array($householdIds['offline-conn'], $activeHouseholdIds, true), 'OFFLINE must be active');
                Assert::true(in_array($householdIds['unknown-conn'], $activeHouseholdIds, true), 'UNKNOWN must be active');
                Assert::true(in_array($householdIds['unreach-conn'], $activeHouseholdIds, true), 'UNREACHABLE must be active for retry');

                // DISCONNECTED and DISABLED must remain strictly excluded
                Assert::false(in_array($householdIds['disconn-conn'], $activeHouseholdIds, true), 'DISCONNECTED must be excluded');
                Assert::false(in_array($householdIds['disabled-conn'], $activeHouseholdIds, true), 'DISABLED must be excluded');
            }),

            TestResult::check('UNREACHABLE connection recovers to CONNECTED on successful sync and stays UNREACHABLE on failure', function (): void {
                $context = AuthTesting::householdFixture('sol-recover-' . bin2hex(random_bytes(3)));
                $householdId = $context['household_id'];
                $connRepo = new SolarConnectionRepository();
                $readingsRepo = new SolarReadingRepository();

                $creds = new SolarCredentials('recover@example.com', 'pwd123', 'st-rec-01', 'sn-rec-01');
                $encrypted = Encryption::encrypt(json_encode($creds->toEncryptedPayload()), $householdId);

                // 1. Initial UNREACHABLE state
                $connRepo->upsert(
                    id: self::uuid(),
                    householdId: $householdId,
                    provider: 'GOODWE_SEMS',
                    stationId: 'st-rec-01',
                    inverterSn: 'sn-rec-01',
                    accountIdentifier: $creds->maskedAccount(),
                    encryptedCredentials: $encrypted,
                    connectionStatus: 'UNREACHABLE',
                    lastErrorCode: 'SYNC_ERROR',
                    lastErrorMessage: 'Transient network failure'
                );

                $conn = $connRepo->findByHouseholdId($householdId);
                Assert::same('UNREACHABLE', $conn['connection_status']);

                // 2. Mock provider that throws exception on retry
                $failingProvider = new class implements \App\Domain\Solar\Contracts\SolarProviderInterface {
                    public function testConnection(SolarCredentials $c): \App\Domain\Solar\DTO\ConnectionTestResult {
                        return new \App\Domain\Solar\DTO\ConnectionTestResult(false, 'ERROR', [], 'E_CONN', 'Still unreachable', new DateTimeImmutable());
                    }
                    public function discoverStations(SolarCredentials $c): array { return []; }
                    public function fetchLatestSnapshot(SolarCredentials $c): NormalizedSolarSnapshot {
                        throw new \RuntimeException('Host unreachable (curl errno 6)');
                    }
                    public function fetchIntradayCurve(SolarCredentials $c, DateTimeImmutable $date): array { return []; }
                    public function fetchHistoricalDailyTotals(SolarCredentials $c, DateTimeImmutable $from, DateTimeImmutable $to): array { return []; }
                };

                $solarServiceFail = new \App\Domain\Solar\SolarService(
                    connections: $connRepo,
                    readings: $readingsRepo,
                    provider: $failingProvider
                );

                $caughtFail = false;
                try {
                    $solarServiceFail->syncHouseholdTelemetry($householdId);
                } catch (\Throwable $e) {
                    $caughtFail = true;
                    Assert::true(str_contains($e->getMessage(), 'Host unreachable'));
                }
                Assert::true($caughtFail, 'Failed sync must throw');

                // After failed retry, status remains UNREACHABLE and last_error_message is updated
                $connAfterFail = $connRepo->findByHouseholdId($householdId);
                Assert::same('UNREACHABLE', $connAfterFail['connection_status']);
                Assert::same('SYNC_ERROR', $connAfterFail['last_error_code']);
                Assert::true(str_contains((string) $connAfterFail['last_error_message'], 'Host unreachable'));

                // 3. Mock provider that succeeds on retry
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $succeedingProvider = new class($now) implements \App\Domain\Solar\Contracts\SolarProviderInterface {
                    public function __construct(private DateTimeImmutable $now) {}
                    public function testConnection(SolarCredentials $c): \App\Domain\Solar\DTO\ConnectionTestResult {
                        return new \App\Domain\Solar\DTO\ConnectionTestResult(true, 'OK', [], null, null, new DateTimeImmutable());
                    }
                    public function discoverStations(SolarCredentials $c): array { return []; }
                    public function fetchLatestSnapshot(SolarCredentials $c): NormalizedSolarSnapshot {
                        return new NormalizedSolarSnapshot(
                            stationId: 'st-rec-01',
                            inverterSn: 'sn-rec-01',
                            measurementTime: $this->now,
                            receivedAt: $this->now,
                            powerW: 2450.0,
                            dailyGenerationKwh: 8.5,
                            inverterStatus: 'NORMAL'
                        );
                    }
                    public function fetchIntradayCurve(SolarCredentials $c, DateTimeImmutable $date): array { return []; }
                    public function fetchHistoricalDailyTotals(SolarCredentials $c, DateTimeImmutable $from, DateTimeImmutable $to): array { return []; }
                };

                $solarServiceSuccess = new \App\Domain\Solar\SolarService(
                    connections: $connRepo,
                    readings: $readingsRepo,
                    provider: $succeedingProvider
                );

                $snapshot = $solarServiceSuccess->syncHouseholdTelemetry($householdId);
                Assert::true($snapshot !== null);
                Assert::same(2450.0, $snapshot->powerW());

                // After successful retry, connection status is restored to CONNECTED!
                $connAfterSuccess = $connRepo->findByHouseholdId($householdId);
                Assert::same('CONNECTED', $connAfterSuccess['connection_status']);
                Assert::true($connAfterSuccess['last_successful_sync_at'] !== null);
            }),
        ];
    }
}
