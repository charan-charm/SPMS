<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Solar\DTO\SolarCredentials;
use DateTimeImmutable;
use Tests\Support\Assert;
use Tests\TestResult;

final class GoodWeNormalizationTest
{
    public static function run(): array
    {
        return [
            TestResult::check('SolarCredentials produces privacy-preserving masked account identifier', function (): void {
                $emailCreds = new SolarCredentials('alice.solar@example.com', 'password123', 'station-abc');
                Assert::same('a***r@example.com', $emailCreds->maskedAccount());

                $shortEmail = new SolarCredentials('me@domain.org', 'password123', 'station-abc');
                Assert::same('m***@domain.org', $shortEmail->maskedAccount());

                $plainUsername = new SolarCredentials('solartech99', 'password123', 'station-abc');
                Assert::same('s***9', $plainUsername->maskedAccount());
            }),

            TestResult::check('SolarCredentials round-trips through encrypted payload mapping', function (): void {
                $creds = new SolarCredentials('user@test.org', 'mypassword', 'station-12345', 'GW9999SN');
                $payload = $creds->toEncryptedPayload();

                Assert::same('user@test.org', $payload['account']);
                Assert::same('mypassword', $payload['password']);
                Assert::same('station-12345', $payload['station_id']);
                Assert::same('GW9999SN', $payload['inverter_sn']);

                $restored = SolarCredentials::fromEncryptedPayload($payload);
                Assert::same($creds->account(), $restored->account());
                Assert::same($creds->password(), $restored->password());
                Assert::same($creds->stationId(), $restored->stationId());
                Assert::same($creds->inverterSn(), $restored->inverterSn());
            }),

            TestResult::check('SolarCredentials allows null stationId during discovery but strictly enforces assertPersistable', function (): void {
                $discoveryCreds = new SolarCredentials('discovery@example.com', 'mypassword');
                Assert::true($discoveryCreds->stationId() === null, 'stationId must be null during initial discovery');

                // Must fail if attempting to encrypt/persist without resolving stationId
                $failed = false;
                try {
                    $discoveryCreds->toEncryptedPayload();
                } catch (\InvalidArgumentException $e) {
                    $failed = true;
                    Assert::true(str_contains($e->getMessage(), 'Cannot persist solar connection without a resolved power station ID'));
                }
                Assert::true($failed, 'toEncryptedPayload must throw if stationId is null');

                // Resolving stationId with withStationId enables persistence
                $resolvedCreds = $discoveryCreds->withStationId('resolved-station-uuid-001');
                Assert::same('resolved-station-uuid-001', $resolvedCreds->stationId());
                $payload = $resolvedCreds->toEncryptedPayload();
                Assert::same('resolved-station-uuid-001', $payload['station_id']);
            }),

            TestResult::check('NormalizedSolarSnapshot preserves smart meter absence invariants', function (): void {
                $now = new DateTimeImmutable('now');
                $snapshot = new NormalizedSolarSnapshot(
                    stationId: 'station-100',
                    inverterSn: 'SN123456',
                    measurementTime: $now,
                    receivedAt: $now,
                    powerW: 4250.75,
                    dailyGenerationKwh: 18.230,
                    totalGenerationKwh: 5200.500,
                    pvPowerW: 4400.00,
                    acVoltageV: 234.50,
                    acCurrentA: 18.12,
                    acFrequencyHz: 50.01,
                    temperatureC: 43.20,
                    gridPowerW: null, // Strictly NULL when smart meter is absent
                    consumptionPowerW: null, // Strictly NULL when smart meter is absent
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH',
                    dataQuality: 'GOOD'
                );

                Assert::same(4250.75, $snapshot->powerW());
                Assert::same(18.230, $snapshot->dailyGenerationKwh());
                Assert::true($snapshot->gridPowerW() === null, 'Grid power must be null without smart meter');
                Assert::true($snapshot->consumptionPowerW() === null, 'Consumption power must be null without smart meter');
                Assert::same('NORMAL', $snapshot->inverterStatus());
                Assert::same('ACTUAL', $snapshot->dataNature());
            }),

            TestResult::check('NormalizedSolarSnapshot serialization contains formatted date and numbers', function (): void {
                $now = new DateTimeImmutable('2026-09-20 12:00:00+00:00');
                $snapshot = new NormalizedSolarSnapshot(
                    stationId: 'station-100',
                    inverterSn: 'SN123456',
                    measurementTime: $now,
                    receivedAt: $now,
                    powerW: 3000.0,
                    dailyGenerationKwh: 10.5,
                    inverterStatus: 'NORMAL'
                );

                $arr = $snapshot->toArray();
                Assert::same('station-100', $arr['station_id']);
                Assert::same('SN123456', $arr['inverter_sn']);
                Assert::same(3000.0, $arr['power_w']);
                Assert::same(10.5, $arr['daily_generation_kwh']);
                Assert::same('NORMAL', $arr['inverter_status']);
                Assert::same('ACTUAL', $arr['data_nature']);
            }),

            TestResult::check('GoodWe telemetry parser parses SEMS US date format without month overflow into future years', function (): void {
                $adapter = new \App\Integrations\GoodWe\GoodWeSemsPortalAdapter();
                $ref = new \ReflectionMethod($adapter, 'parseMeasurementTime');
                $fallback = new DateTimeImmutable('2026-09-28 12:00:00');

                // Standard SEMS US timestamp (m/d/Y H:i:s)
                $parsedUs = $ref->invoke($adapter, '09/21/2026 17:05:34', $fallback);
                Assert::same('2026-09-21 17:05:34', $parsedUs->format('Y-m-d H:i:s'));
                Assert::same('2026', $parsedUs->format('Y'));

                // ISO format (Y-m-d H:i:s)
                $parsedIso = $ref->invoke($adapter, '2026-09-28 04:47:58', $fallback);
                Assert::same('2026-09-28 04:47:58', $parsedIso->format('Y-m-d H:i:s'));

                // European format (d/m/Y H:i:s)
                $parsedEu = $ref->invoke($adapter, '21/09/2026 17:05:34', $fallback);
                Assert::same('2026-09-21 17:05:34', $parsedEu->format('Y-m-d H:i:s'));

                // 12-hour AM/PM format
                $parsedAmPm = $ref->invoke($adapter, '09/21/2026 05:05:34 PM', $fallback);
                Assert::same('2026-09-21 17:05:34', $parsedAmPm->format('Y-m-d H:i:s'));

                // Invalid/overflow format must NOT overflow into 2027 and must fall back safely
                $parsedOverflow = $ref->invoke($adapter, '25/25/2026 12:00:00', $fallback);
                Assert::same($fallback->format('Y-m-d H:i:s'), $parsedOverflow->format('Y-m-d H:i:s'));

                // Null or empty string must return fallback
                $parsedNull = $ref->invoke($adapter, null, $fallback);
                Assert::same($fallback->format('Y-m-d H:i:s'), $parsedNull->format('Y-m-d H:i:s'));
            }),

            TestResult::check('GoodWe factor flattener extracts code-keyed values from nested factor groups', function (): void {
                $adapter = new \App\Integrations\GoodWe\GoodWeSemsPortalAdapter();
                $ref = new \ReflectionMethod($adapter, 'flattenFactors');

                $groups = [
                    [
                        'code' => 'proPvStats',
                        'factors' => [
                            ['code' => 'proPvStatsToday', 'data' => '12.80', 'unit' => 'kWh'],
                            ['code' => 'proPvStatsTotal', 'data' => '3423.40', 'unit' => 'kWh'],
                        ],
                    ],
                    [
                        'code' => 'acParameters',
                        'factors' => [
                            ['code' => 'Vac', 'data' => '232.5', 'unit' => 'V'],
                            ['code' => 'Iac', 'data' => '1.5', 'unit' => 'A'],
                        ],
                    ],
                ];

                $flattened = $ref->invoke($adapter, $groups);
                Assert::same('12.80', $flattened['proPvStatsToday']);
                Assert::same('3423.40', $flattened['proPvStatsTotal']);
                Assert::same('232.5', $flattened['Vac']);
                Assert::same('1.5', $flattened['Iac']);
            }),
        ];
    }
}
