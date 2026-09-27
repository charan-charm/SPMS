<?php

declare(strict_types=1);

namespace App\Domain\Solar\Contracts;

use App\Domain\Solar\DTO\ConnectionTestResult;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Solar\DTO\SolarCredentials;
use DateTimeImmutable;

/**
 * SPMS O4 — Solar Provider Interface (docs/10 D-059).
 *
 * Contract isolating external solar inverter cloud APIs (e.g. GoodWe SEMS+)
 * behind a normalized domain boundary.
 */
interface SolarProviderInterface
{
    /**
     * Discover power stations associated with the provider account.
     *
     * @return list<array{station_id: string, station_name: string, capacity_kw: ?float}>
     */
    public function discoverStations(SolarCredentials $credentials): array;

    /**
     * Authenticate and probe the external station to verify credentials and connectivity.
     */
    public function testConnection(SolarCredentials $credentials): ConnectionTestResult;

    /**
     * Fetch the most recent 5-minute telemetry snapshot for the station.
     */
    public function fetchLatestSnapshot(SolarCredentials $credentials): NormalizedSolarSnapshot;

    /**
     * Fetch intraday telemetry curve (5-minute points) for a specific date.
     *
     * @return list<NormalizedSolarSnapshot>
     */
    public function fetchIntradayCurve(SolarCredentials $credentials, DateTimeImmutable $date): array;

    /**
     * Fetch daily aggregate historical generation totals for a date range.
     *
     * @return list<array{date: string, generation_kwh: float}>
     */
    public function fetchHistoricalDailyTotals(
        SolarCredentials $credentials,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array;
}
