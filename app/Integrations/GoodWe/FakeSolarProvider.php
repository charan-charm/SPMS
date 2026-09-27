<?php

declare(strict_types=1);

namespace App\Integrations\GoodWe;

use App\Domain\Solar\Contracts\SolarProviderInterface;
use App\Domain\Solar\DTO\ConnectionTestResult;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Solar\DTO\SolarCredentials;
use DateTimeImmutable;
use RuntimeException;

/**
 * SPMS O4 — Fake Solar Provider for Testing & Verification.
 *
 * Provides deterministic, network-free solar telemetry responses for automated tests.
 * Guaranteed never to make real network requests.
 */
final class FakeSolarProvider implements SolarProviderInterface
{
    private ?NormalizedSolarSnapshot $nextSnapshot = null;
    private ?ConnectionTestResult $nextTestResult = null;
    private ?string $forcedErrorCode = null;

    public function setNextSnapshot(?NormalizedSolarSnapshot $snapshot): void
    {
        $this->nextSnapshot = $snapshot;
    }

    public function setNextTestResult(?ConnectionTestResult $result): void
    {
        $this->nextTestResult = $result;
    }

    public function setForcedErrorCode(?string $code): void
    {
        $this->forcedErrorCode = $code;
    }

    /**
     * @return list<array{station_id: string, station_name: string, capacity_kw: ?float}>
     */
    public function discoverStations(SolarCredentials $credentials): array
    {
        if ($credentials->account() === 'zero_stations@example.com') {
            return [];
        }

        if ($credentials->account() === 'multi_stations@example.com') {
            return [
                [
                    'station_id' => 'st-alpha-1',
                    'station_name' => 'Farm House PV',
                    'capacity_kw' => 5.0,
                ],
                [
                    'station_id' => 'st-beta-2',
                    'station_name' => 'City Home Rooftop',
                    'capacity_kw' => 3.5,
                ],
            ];
        }

        return [
            [
                'station_id' => 'goodwe-station-98765',
                'station_name' => 'Residential Rooftop PV Station',
                'capacity_kw' => 6.0,
            ],
        ];
    }

    public function testConnection(SolarCredentials $credentials): ConnectionTestResult
    {
        if ($this->nextTestResult !== null) {
            return $this->nextTestResult;
        }

        if ($this->forcedErrorCode !== null) {
            return new ConnectionTestResult(
                success: false,
                status: 'AUTH_FAILED',
                errorCode: $this->forcedErrorCode,
                errorMessage: 'Forced test error: ' . $this->forcedErrorCode,
                testedAt: new DateTimeImmutable('now')
            );
        }

        if ($credentials->account() === 'invalid@example.com' || $credentials->password() === 'wrong_password') {
            return new ConnectionTestResult(
                success: false,
                status: 'AUTH_FAILED',
                errorCode: 'INVALID_CREDENTIALS',
                errorMessage: 'Account or password incorrect.',
                testedAt: new DateTimeImmutable('now')
            );
        }

        $stationId = $credentials->stationId();
        $discoveredStations = [];

        if ($stationId === null || $stationId === '') {
            $discoveredStations = $this->discoverStations($credentials);
            if (empty($discoveredStations)) {
                return new ConnectionTestResult(
                    success: false,
                    status: 'NO_STATIONS_FOUND',
                    stations: [],
                    errorCode: 'NO_STATIONS_FOUND',
                    errorMessage: 'No power stations found for this SEMS+ account.',
                    testedAt: new DateTimeImmutable('now')
                );
            }

            if (count($discoveredStations) > 1) {
                return new ConnectionTestResult(
                    success: false,
                    status: 'MULTIPLE_STATIONS',
                    stations: $discoveredStations,
                    errorCode: 'MULTIPLE_STATIONS',
                    errorMessage: 'Multiple power stations found. Please select which station to connect.',
                    testedAt: new DateTimeImmutable('now')
                );
            }

            $stationId = $discoveredStations[0]['station_id'];
        } else {
            $discoveredStations = [
                [
                    'station_id' => $stationId,
                    'station_name' => 'Specified Station',
                    'capacity_kw' => 6.0,
                ],
            ];
        }

        if ($stationId === 'INVALID_STATION_ID') {
            return new ConnectionTestResult(
                success: false,
                status: 'STATION_NOT_FOUND',
                stationId: $stationId,
                stations: $discoveredStations,
                errorCode: 'STATION_NOT_FOUND',
                errorMessage: 'Power station not found for this account.',
                testedAt: new DateTimeImmutable('now')
            );
        }

        if ($credentials->account() === 'offline@example.com') {
            return new ConnectionTestResult(
                success: true,
                status: 'OFFLINE',
                stationId: $stationId,
                stationName: 'Household PV Station (Night/Offline)',
                inverterModel: 'GW6000-DNS-G3',
                inverterSn: $credentials->inverterSn() ?? '96000DNS22300188',
                ratedPowerKw: 6.0,
                stations: $discoveredStations,
                testedAt: new DateTimeImmutable('now')
            );
        }

        $name = $discoveredStations[0]['station_name'] ?? 'Residential Rooftop PV Station';

        return new ConnectionTestResult(
            success: true,
            status: 'CONNECTED',
            stationId: $stationId,
            stationName: $name,
            inverterModel: 'GW6000-DNS-G3',
            inverterSn: $credentials->inverterSn() ?? '96000DNS22300188',
            ratedPowerKw: 6.0,
            stations: $discoveredStations,
            testedAt: new DateTimeImmutable('now')
        );
    }

    public function fetchLatestSnapshot(SolarCredentials $credentials): NormalizedSolarSnapshot
    {
        if ($this->nextSnapshot !== null) {
            return $this->nextSnapshot;
        }

        if ($credentials->account() === 'invalid@example.com') {
            throw new RuntimeException('Authentication failed with GoodWe SEMS+ portal.');
        }

        $now = new DateTimeImmutable('now');
        $stationId = $credentials->stationId() ?? 'goodwe-station-98765';

        if ($credentials->account() === 'offline@example.com') {
            return new NormalizedSolarSnapshot(
                stationId: $stationId,
                inverterSn: $credentials->inverterSn() ?? '96000DNS22300188',
                measurementTime: $now,
                receivedAt: $now,
                powerW: 0.0,
                dailyGenerationKwh: 12.450,
                totalGenerationKwh: 1845.600,
                pvPowerW: 0.0,
                acVoltageV: 231.50,
                acCurrentA: 0.0,
                acFrequencyHz: 50.00,
                temperatureC: 28.00,
                gridPowerW: null,
                consumptionPowerW: null,
                inverterStatus: 'OFFLINE',
                dataNature: 'ACTUAL',
                dataStatus: 'FRESH',
                dataQuality: 'GOOD'
            );
        }

        return new NormalizedSolarSnapshot(
            stationId: $stationId,
            inverterSn: $credentials->inverterSn() ?? '96000DNS22300188',
            measurementTime: $now,
            receivedAt: $now,
            powerW: 3450.75,
            dailyGenerationKwh: 14.820,
            totalGenerationKwh: 1845.600,
            pvPowerW: 3600.20,
            acVoltageV: 232.40,
            acCurrentA: 14.85,
            acFrequencyHz: 50.02,
            temperatureC: 41.50,
            gridPowerW: null, // Smart meter absent invariant
            consumptionPowerW: null, // Smart meter absent invariant
            inverterStatus: 'NORMAL',
            dataNature: 'ACTUAL',
            dataStatus: 'FRESH',
            dataQuality: 'GOOD'
        );
    }

    public function fetchIntradayCurve(SolarCredentials $credentials, DateTimeImmutable $date): array
    {
        $sn = $credentials->inverterSn() ?? '96000DNS22300188';
        $stationId = $credentials->stationId() ?? 'goodwe-station-98765';
        $snapshots = [];
        $baseDate = $date->setTime(6, 0, 0);

        // Generate sample points from 06:00 to 18:00 at 30-min intervals
        for ($i = 0; $i <= 24; ++$i) {
            $pointTime = $baseDate->modify("+{$i} minutes * 30");
            $hour = (int) $pointTime->format('G') + ((int) $pointTime->format('i') / 60);

            // Bell curve peaking at noon (12:00) with peak ~4200W
            $power = 0.0;
            if ($hour >= 6 && $hour <= 18) {
                $power = round(max(0.0, 4200 * sin(($hour - 6) / 12 * M_PI)), 2);
            }

            $snapshots[] = new NormalizedSolarSnapshot(
                stationId: $stationId,
                inverterSn: $sn,
                measurementTime: $pointTime,
                receivedAt: $pointTime->modify('+2 minutes'),
                powerW: $power,
                dailyGenerationKwh: round($power * 0.5 / 1000, 3),
                totalGenerationKwh: 1845.600,
                inverterStatus: $power > 0 ? 'NORMAL' : 'OFFLINE',
                dataNature: 'ACTUAL',
                dataStatus: 'FRESH',
                dataQuality: 'GOOD'
            );
        }

        return $snapshots;
    }

    public function fetchHistoricalDailyTotals(
        SolarCredentials $credentials,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $results = [];
        $current = $from;
        while ($current <= $to) {
            $results[] = [
                'date' => $current->format('Y-m-d'),
                'generation_kwh' => 16.500,
            ];
            $current = $current->modify('+1 day');
        }

        return $results;
    }
}
