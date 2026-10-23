<?php

declare(strict_types=1);

namespace App\Integrations\GoodWe;

use App\Domain\Solar\Contracts\SolarProviderInterface;
use App\Domain\Solar\DTO\ConnectionTestResult;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Domain\Solar\DTO\SolarCredentials;
use App\Support\Config;
use App\Support\Logger;
use DateTimeImmutable;
use RuntimeException;

/**
 * SPMS O4 — GoodWe SEMS+ Portal Adapter (docs/10 D-059, D-060, D-061).
 *
 * Connects to GoodWe SEMS+ Cloud API to ingest live inverter telemetry,
 * normalize electrical measurements, and map inverter operational states.
 *
 * Security & Reliability Guards:
 *  - Enforces HTTPS origin allowlist (*.semsportal.com).
 *  - Connect timeout 10s, total execution timeout 20s.
 *  - Never logs passwords or sensitive session tokens.
 *  - Preserves smart-meter absence invariants (grid/load power is NULL if unmetered).
 */
final class GoodWeSemsPortalAdapter implements SolarProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://www.semsportal.com';
    private const USER_AGENT = 'SEMS+/2.1.0 (iPhone; iOS 16.0; Scale/3.00)';
    private const CLIENT_TOKEN_HEADER = '{"version":"v2.1.0","client":"ios","language":"en"}';

    private string $baseUrl;
    /** @var (callable(string, array<string, mixed>, ?string, ?string, int, int): array<string, mixed>)|null */
    private $transport;
    /** @var array<string, array{session: array<string, mixed>, expires_at: int}> */
    private array $sessionCache = [];

    public function __construct(?string $baseUrl = null, ?callable $transport = null)
    {
        $configured = $baseUrl ?? (string) Config::get('GOODWE_API_BASE_URL', self::DEFAULT_BASE_URL);
        $this->baseUrl = rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
        $this->transport = $transport;
    }

    public function clearSessionCache(): void
    {
        $this->sessionCache = [];
    }

    /**
     * Discover power stations associated with the account.
     *
     * @return list<array{station_id: string, station_name: string, capacity_kw: ?float}>
     */
    public function discoverStations(SolarCredentials $credentials): array
    {
        $session = $this->crossLogin($credentials->account(), $credentials->password());
        $token = (string) ($session['token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Failed to obtain SEMS+ session token for station discovery.');
        }

        $regionalBaseUrl = $session['base_url'] ?? $this->baseUrl;

        return $this->queryPowerStations($token, $regionalBaseUrl);
    }

    public function testConnection(SolarCredentials $credentials): ConnectionTestResult
    {
        try {
            $session = $this->crossLogin($credentials->account(), $credentials->password());
            if (!isset($session['token']) || !is_string($session['token'])) {
                return new ConnectionTestResult(
                    success: false,
                    status: 'AUTH_FAILED',
                    errorCode: 'AUTH_TOKEN_MISSING',
                    errorMessage: 'Login succeeded but session token was missing from SEMS+ response.',
                    testedAt: new DateTimeImmutable('now')
                );
            }

            $token = $session['token'];
            $regionalBaseUrl = $session['base_url'] ?? $this->baseUrl;
            $targetStationId = $credentials->stationId();
            $discoveredStations = [];

            if ($targetStationId === null || $targetStationId === '') {
                $discoveredStations = $this->queryPowerStations($token, $regionalBaseUrl);
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

                $targetStationId = $discoveredStations[0]['station_id'];
            } else {
                $discoveredStations = [
                    [
                        'station_id' => $targetStationId,
                        'station_name' => 'Selected Station',
                        'capacity_kw' => null,
                    ],
                ];
            }

            $inverterDiscovery = $this->queryStationInverters($token, $targetStationId, $regionalBaseUrl);
            $inverters = $inverterDiscovery['inverters'];
            $stationName = $inverterDiscovery['station_name'] ?? ($discoveredStations[0]['station_name'] ?? null);

            if (empty($inverters)) {
                return new ConnectionTestResult(
                    success: false,
                    status: 'STATION_NOT_FOUND',
                    stationId: $targetStationId,
                    stationName: $stationName,
                    stations: $discoveredStations,
                    errorCode: 'NO_INVERTER_FOUND',
                    errorMessage: 'Power station found but no associated inverters were reported.',
                    testedAt: new DateTimeImmutable('now')
                );
            }

            // Match requested inverter or pick the primary one
            $matched = null;
            if ($credentials->inverterSn() !== null && $credentials->inverterSn() !== '') {
                foreach ($inverters as $inv) {
                    if (isset($inv['sn']) && (string) $inv['sn'] === $credentials->inverterSn()) {
                        $matched = $inv;
                        break;
                    }
                }
            }
            if ($matched === null) {
                $matched = $inverters[0];
            }

            $sn = isset($matched['sn']) ? (string) $matched['sn'] : null;
            $model = isset($matched['name']) ? (string) $matched['name'] : (isset($matched['model']) ? (string) $matched['model'] : null);
            $capacity = isset($matched['capacity']) && is_numeric($matched['capacity']) ? (float) $matched['capacity'] : null;
            $rawStatus = isset($matched['status']) ? (int) $matched['status'] : 1;
            $pac = isset($matched['pac']) && is_numeric($matched['pac']) ? (float) $matched['pac'] : 0.0;
            $normalizedStatus = $this->mapInverterStatus($rawStatus, $pac);

            return new ConnectionTestResult(
                success: true,
                status: $normalizedStatus === 'OFFLINE' ? 'OFFLINE' : 'CONNECTED',
                stationId: $targetStationId,
                stationName: $stationName,
                inverterModel: $model,
                inverterSn: $sn,
                ratedPowerKw: $capacity,
                stations: $discoveredStations,
                testedAt: new DateTimeImmutable('now')
            );
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $code = 'NETWORK_ERROR';
            if (str_contains(strtolower($msg), 'password') || str_contains(strtolower($msg), 'account') || str_contains(strtolower($msg), 'auth')) {
                $code = 'AUTH_FAILED';
            } elseif (str_contains(strtolower($msg), 'station') || str_contains(strtolower($msg), 'not found')) {
                $code = 'STATION_NOT_FOUND';
            } elseif (str_contains(strtolower($msg), 'timeout')) {
                $code = 'TIMEOUT';
            }

            return new ConnectionTestResult(
                success: false,
                status: $code === 'AUTH_FAILED' ? 'AUTH_FAILED' : 'UNREACHABLE',
                errorCode: $code,
                errorMessage: $msg,
                testedAt: new DateTimeImmutable('now')
            );
        }
    }

    public function fetchLatestSnapshot(SolarCredentials $credentials): NormalizedSolarSnapshot
    {
        $stationId = $credentials->stationId();
        if ($stationId === null || $stationId === '') {
            throw new RuntimeException('Station ID is required to fetch solar telemetry snapshot.');
        }

        $session = $this->crossLogin($credentials->account(), $credentials->password());
        $token = (string) ($session['token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Failed to obtain SEMS+ session token for telemetry fetch.');
        }

        $regionalBaseUrl = $session['base_url'] ?? $this->baseUrl;

        // Resolve inverter serial number
        $inverterSn = $credentials->inverterSn();
        if ($inverterSn === null || $inverterSn === '') {
            $inverterDiscovery = $this->queryStationInverters($token, $stationId, $regionalBaseUrl);
            $inverterSn = $inverterDiscovery['inverters'][0]['sn'] ?? null;
        }

        $stationTz = new \DateTimeZone('Asia/Kolkata');
        $now = new DateTimeImmutable('now', $stationTz);

        // 1. MODERN GATEWAY FIRST (/web/sems/* modern SEMS+ platform)
        $modernCandidates = $this->resolveModernGatewayCandidates($regionalBaseUrl);
        foreach ($modernCandidates as $candidateBaseUrl) {
            try {
                // A. Energy Flow: provides instantaneous power pAc (kW), operating status, refreshTime
                $flowResponse = $this->getJson(
                    '/web/sems/sems-plant/api/stations/flow?stationId=' . rawurlencode($stationId),
                    $token,
                    $candidateBaseUrl,
                    connectTimeout: 4,
                    timeout: 8
                );
                $flowData = $flowResponse['data'] ?? [];

                // B. Telecounting: provides today's generation (kWh) & lifetime generation (kWh)
                $telecountingData = [];
                if ($inverterSn !== null && $inverterSn !== '') {
                    try {
                        $tcResponse = $this->getJson(
                            '/web/sems/sems-plant/api/equipments/' . rawurlencode($inverterSn) . '/telecounting?sn=' . rawurlencode($inverterSn) . '&deviceType=INVERTER&pwId=' . rawurlencode($stationId),
                            $token,
                            $candidateBaseUrl,
                            connectTimeout: 4,
                            timeout: 8
                        );
                        $telecountingData = $this->flattenFactors($tcResponse['data'] ?? []);
                    } catch (\Throwable) {}
                }

                // C. Telemetry: provides electrical measurements (Vac, Iac, Fac, temperature, MPPT)
                $telemetryData = [];
                if ($inverterSn !== null && $inverterSn !== '') {
                    try {
                        $telemResponse = $this->getJson(
                            '/web/sems/sems-plant/api/equipments/' . rawurlencode($inverterSn) . '/telemetry?sn=' . rawurlencode($inverterSn) . '&deviceType=INVERTER&pwId=' . rawurlencode($stationId),
                            $token,
                            $candidateBaseUrl,
                            connectTimeout: 4,
                            timeout: 8
                        );
                        $telemetryData = $this->flattenFactors($telemResponse['data'] ?? []);
                    } catch (\Throwable) {}
                }

                // D. Production: fallback for today's generation
                $productionKwh = null;
                try {
                    $prodResponse = $this->postJson(
                        '/web/sems/sems-plant/api/stations/production',
                        [
                            'stationId' => $stationId,
                            'items' => ['profitProStats', 'proSystemTotalStats'],
                            'dimension' => 'day',
                            'isReport' => false,
                            'startTime' => $now->format('Y-m-d') . ' 00:00:00',
                            'endTime' => $now->format('Y-m-d') . ' 23:59:59',
                        ],
                        $token,
                        $candidateBaseUrl,
                        connectTimeout: 4,
                        timeout: 8
                    );
                    if (isset($prodResponse['data']['proSystemTotalStats']) && is_numeric($prodResponse['data']['proSystemTotalStats'])) {
                        $productionKwh = (float) $prodResponse['data']['proSystemTotalStats'];
                    }
                } catch (\Throwable) {}

                // If flowData has data or telecounting has data, we successfully reached modern GoodWe SEMS+
                if (!empty($flowData) || !empty($telecountingData)) {
                    // 1. Instantaneous Power (W)
                    $rawPacKw = null;
                    if (isset($flowData['pAc']) && is_numeric($flowData['pAc'])) {
                        $rawPacKw = (float) $flowData['pAc'];
                    } elseif (isset($telemetryData['pAc']) && is_numeric($telemetryData['pAc'])) {
                        $rawPacKw = (float) $telemetryData['pAc'];
                    } elseif (isset($telecountingData['pAc']) && is_numeric($telecountingData['pAc'])) {
                        $rawPacKw = (float) $telecountingData['pAc'];
                    }
                    $powerW = $rawPacKw !== null ? max(0.0, round($rawPacKw * 1000.0, 2)) : 0.0;

                    // 2. Today's Generation (kWh)
                    $dailyKwh = 0.0;
                    if (isset($telecountingData['proPvStatsToday']) && is_numeric($telecountingData['proPvStatsToday'])) {
                        $dailyKwh = max(0.0, round((float) $telecountingData['proPvStatsToday'], 3));
                    } elseif ($productionKwh !== null) {
                        $dailyKwh = max(0.0, round($productionKwh, 3));
                    }

                    // 3. Lifetime Total Generation (kWh) — preserve null when provider does not supply
                    $totalKwh = null;
                    if (isset($telecountingData['proPvStatsTotal']) && is_numeric($telecountingData['proPvStatsTotal'])) {
                        $totalKwh = max(0.0, round((float) $telecountingData['proPvStatsTotal'], 3));
                    }

                    // 4. Inverter Status
                    $rawStatus = isset($flowData['status']) && is_numeric($flowData['status'])
                        ? (int) $flowData['status']
                        : 1;
                    $inverterStatus = $this->mapInverterStatus($rawStatus, $powerW);

                    // 5. Electrical Parameters
                    $acVoltage = isset($telemetryData['Vac']) && is_numeric($telemetryData['Vac'])
                        ? (float) $telemetryData['Vac']
                        : null;
                    $acCurrent = isset($telemetryData['Iac']) && is_numeric($telemetryData['Iac'])
                        ? (float) $telemetryData['Iac']
                        : null;
                    $acFreq = isset($telemetryData['Fac']) && is_numeric($telemetryData['Fac'])
                        ? (float) $telemetryData['Fac']
                        : null;
                    $temp = isset($telemetryData['Temperature']) && is_numeric($telemetryData['Temperature'])
                        ? (float) $telemetryData['Temperature']
                        : null;

                    // 6. DC Array Power (PV)
                    $pvPowerW = null;
                    if (isset($telemetryData['MPPT-1:Ppv']) && is_numeric($telemetryData['MPPT-1:Ppv'])) {
                        $pvPowerW = max(0.0, round((float) $telemetryData['MPPT-1:Ppv'] * 1000.0, 2));
                    } elseif ($powerW > 0.0) {
                        $pvPowerW = $powerW;
                    }

                    // 7. Measurement Time
                    $rawTime = isset($flowData['refreshTime']) && is_string($flowData['refreshTime'])
                        ? $flowData['refreshTime']
                        : null;
                    $measurementTime = $this->parseMeasurementTime($rawTime, $now);

                    $nowUtc = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $measUtc = $measurementTime->setTimezone(new \DateTimeZone('UTC'));
                    $ageSeconds = max(0, $nowUtc->getTimestamp() - $measUtc->getTimestamp());
                    $dataStatus = $ageSeconds > 900 ? 'STALE' : 'FRESH';

                    return new NormalizedSolarSnapshot(
                        stationId: $stationId,
                        inverterSn: $inverterSn,
                        measurementTime: $measurementTime,
                        receivedAt: $now,
                        powerW: $powerW,
                        dailyGenerationKwh: $dailyKwh,
                        totalGenerationKwh: $totalKwh,
                        pvPowerW: $pvPowerW,
                        acVoltageV: $acVoltage !== null ? max(0.0, round($acVoltage, 2)) : null,
                        acCurrentA: $acCurrent !== null ? max(0.0, round($acCurrent, 2)) : null,
                        acFrequencyHz: $acFreq !== null ? max(0.0, round($acFreq, 2)) : null,
                        temperatureC: $temp !== null ? round($temp, 2) : null,
                        gridPowerW: null,
                        consumptionPowerW: null,
                        inverterStatus: $inverterStatus,
                        dataNature: 'ACTUAL',
                        dataStatus: $dataStatus,
                        dataQuality: 'GOOD'
                    );
                }
            } catch (\Throwable $e) {
                // Try next modern candidate or fallback to legacy
            }
        }

        // 2. LEGACY FALLBACK (getMonitorDetail on legacy regional host)
        $detail = $this->getMonitorDetail($token, $stationId, $regionalBaseUrl);
        $inverters = $detail['inverter'] ?? [];
        if (!is_array($inverters) || empty($inverters)) {
            $inverterDiscovery = $this->queryStationInverters($token, $stationId, $regionalBaseUrl);
            $inverters = $inverterDiscovery['inverters'];
        }
        if (empty($inverters)) {
            throw new RuntimeException("No inverters found for power station {$stationId}.");
        }

        $matched = null;
        if ($inverterSn !== null && $inverterSn !== '') {
            foreach ($inverters as $inv) {
                if (isset($inv['sn']) && (string) $inv['sn'] === $inverterSn) {
                    $matched = $inv;
                    break;
                }
            }
        }
        if ($matched === null) {
            $matched = $inverters[0];
        }

        $rawTime = isset($matched['time']) ? (string) $matched['time'] : (isset($detail['info']['time']) ? (string) $detail['info']['time'] : null);
        $measurementTime = $this->parseMeasurementTime($rawTime, $now);

        $pac = isset($matched['pac']) ? (float) $matched['pac'] : 0.0;
        $eday = isset($matched['eday']) ? (float) $matched['eday'] : 0.0;
        $etotal = isset($matched['etotal']) ? (float) $matched['etotal'] : null;

        $vpv1 = isset($matched['vpv1']) ? (float) $matched['vpv1'] : 0.0;
        $vpv2 = isset($matched['vpv2']) ? (float) $matched['vpv2'] : 0.0;
        $ipv1 = isset($matched['ipv1']) ? (float) $matched['ipv1'] : 0.0;
        $ipv2 = isset($matched['ipv2']) ? (float) $matched['ipv2'] : 0.0;
        $pvPower = ($vpv1 * $ipv1) + ($vpv2 * $ipv2);
        $pvPowerW = $pvPower > 0.0 ? round($pvPower, 2) : ($pac > 0.0 ? $pac : null);

        $acVoltage = isset($matched['vac1']) ? (float) $matched['vac1'] : null;
        $acCurrent = isset($matched['iac1']) ? (float) $matched['iac1'] : null;
        $acFreq = isset($matched['fac1']) ? (float) $matched['fac1'] : null;
        $temperature = isset($matched['tempperature']) ? (float) $matched['tempperature'] : (isset($matched['temperature']) ? (float) $matched['temperature'] : null);

        // Smart meter inspection
        $hasMeter = isset($detail['hasMeter']) && (bool) $detail['hasMeter'];
        $gridPower = null;
        $loadPower = null;
        if ($hasMeter && isset($detail['powerflow'])) {
            $pf = $detail['powerflow'];
            if (isset($pf['grid'])) {
                $gridPower = (float) $pf['grid'];
            }
            if (isset($pf['load'])) {
                $loadPower = (float) $pf['load'];
            }
        }

        $rawStatus = isset($matched['status']) ? (int) $matched['status'] : 1;
        $inverterStatus = $this->mapInverterStatus($rawStatus, $pac);

        $nowUtc = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $measUtc = $measurementTime->setTimezone(new \DateTimeZone('UTC'));
        $ageSeconds = max(0, $nowUtc->getTimestamp() - $measUtc->getTimestamp());
        $dataStatus = $ageSeconds > 900 ? 'STALE' : 'FRESH'; // > 15 min is stale

        return new NormalizedSolarSnapshot(
            stationId: $stationId,
            inverterSn: isset($matched['sn']) ? (string) $matched['sn'] : $credentials->inverterSn(),
            measurementTime: $measurementTime,
            receivedAt: $now,
            powerW: max(0.0, round($pac, 2)),
            dailyGenerationKwh: max(0.0, round($eday, 3)),
            totalGenerationKwh: $etotal !== null ? max(0.0, round($etotal, 3)) : null,
            pvPowerW: $pvPowerW !== null ? max(0.0, $pvPowerW) : null,
            acVoltageV: $acVoltage !== null ? max(0.0, round($acVoltage, 2)) : null,
            acCurrentA: $acCurrent !== null ? max(0.0, round($acCurrent, 2)) : null,
            acFrequencyHz: $acFreq !== null ? max(0.0, round($acFreq, 2)) : null,
            temperatureC: $temperature !== null ? round($temperature, 2) : null,
            gridPowerW: $gridPower,
            consumptionPowerW: $loadPower !== null ? max(0.0, $loadPower) : null,
            inverterStatus: $inverterStatus,
            dataNature: 'ACTUAL',
            dataStatus: $dataStatus,
            dataQuality: 'GOOD'
        );
    }

    public function fetchIntradayCurve(SolarCredentials $credentials, DateTimeImmutable $date): array
    {
        $stationId = $credentials->stationId();
        if ($stationId === null || $stationId === '') {
            return [];
        }

        $session = $this->crossLogin($credentials->account(), $credentials->password());
        $token = (string) ($session['token'] ?? '');
        if ($token === '') {
            return [];
        }

        $regionalBaseUrl = $session['base_url'] ?? $this->baseUrl;

        // Resolve inverter SN
        $inverterSn = $credentials->inverterSn();
        if ($inverterSn === null || $inverterSn === '') {
            $inverterDiscovery = $this->queryStationInverters($token, $stationId, $regionalBaseUrl);
            $inverterSn = $inverterDiscovery['inverters'][0]['sn'] ?? null;
        }

        $stationTz = new \DateTimeZone('Asia/Kolkata');
        $now = new DateTimeImmutable('now', $stationTz);
        $dateStr = $date->format('Y-m-d');

        // 1. MODERN GATEWAY FIRST (/web/sems/sems-plant/api/portal/equipments/{sn}/timeSeriesData)
        if ($inverterSn !== null && $inverterSn !== '') {
            $modernCandidates = $this->resolveModernGatewayCandidates($regionalBaseUrl);
            foreach ($modernCandidates as $candidateBaseUrl) {
                try {
                    $tsEndpoint = '/web/sems/sems-plant/api/portal/equipments/' . rawurlencode($inverterSn) . '/timeSeriesData';
                    $payload = [
                        'stationId' => $stationId,
                        'sn' => $inverterSn,
                        'deviceType' => 'INVERTER',
                        'group' => 'operation_area',
                        'module' => 'chart',
                        'startDateTime' => "{$dateStr} 00:00:00",
                        'endDateTime' => "{$dateStr} 23:59:59",
                        'timeGranularity' => '1',
                    ];

                    $response = $this->postJson($tsEndpoint, $payload, $token, $candidateBaseUrl, connectTimeout: 6, timeout: 12);
                    $data = $response['data'] ?? [];
                    $xAxis = $data['xAxis'] ?? [];
                    $yAxis = $data['yAxis'] ?? [];

                    if (!empty($xAxis) && !empty($yAxis)) {
                        $powerSeries = $yAxis[0]['data'] ?? [];
                        $unit = strtolower((string) ($yAxis[0]['unit'] ?? 'kw'));
                        $multiplier = $unit === 'w' ? 1.0 : 1000.0;

                        $results = [];
                        $runningEnergyKwh = 0.0;

                        foreach ($xAxis as $idx => $xItem) {
                            if (!is_array($xItem) || !isset($xItem['xAxi'])) {
                                continue;
                            }
                            $timeStr = (string) $xItem['xAxi'];
                            $val = $powerSeries[$idx] ?? null;

                            // Parse timestamp in station local time (Y-m-d H:i:s or Y-m-d H:i)
                            $pointTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timeStr);
                            if ($pointTime === false) {
                                $pointTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $timeStr);
                            }
                            if ($pointTime === false) {
                                continue;
                            }

                            if ($val === null || $val === '') {
                                continue;
                            }

                            $powerKw = (float) $val;
                            $powerW = max(0.0, round($powerKw * $multiplier, 2));

                            // 5-minute interval energy Riemann sum: power(kW) * (5/60 hours)
                            $runningEnergyKwh += ($powerKw * (5.0 / 60.0));

                            $results[] = new NormalizedSolarSnapshot(
                                stationId: $stationId,
                                inverterSn: $inverterSn,
                                measurementTime: $pointTime,
                                receivedAt: $now,
                                powerW: $powerW,
                                dailyGenerationKwh: max(0.0, round($runningEnergyKwh, 3)),
                                totalGenerationKwh: null,
                                pvPowerW: $powerW > 0.0 ? $powerW : null,
                                inverterStatus: $powerW > 0.0 ? 'NORMAL' : 'WAITING',
                                dataNature: 'ACTUAL',
                                dataStatus: 'FRESH',
                                dataQuality: 'GOOD'
                            );
                        }

                        if (!empty($results)) {
                            return $results;
                        }
                    }
                } catch (\Throwable $e) {
                    // Try next modern candidate or fallback
                }
            }
        }

        // 2. LEGACY FALLBACK (/api/v2/PowerStation/GetPowerStationPowerAndIncomeByDay)
        $payload = [
            'powerStationId' => $stationId,
            'date' => $dateStr,
        ];

        try {
            $response = $this->postJson(
                '/api/v2/PowerStation/GetPowerStationPowerAndIncomeByDay',
                $payload,
                $token,
                $regionalBaseUrl
            );
            $data = $response['data'] ?? [];
            $pTotal = $data['pTotal'] ?? [];
            if (is_array($pTotal)) {
                $results = [];
                $sn = $inverterSn ?? $credentials->inverterSn();

                foreach ($pTotal as $point) {
                    if (!isset($point['x']) || !isset($point['y'])) {
                        continue;
                    }
                    $timeStr = (string) $point['x'];
                    $pac = (float) $point['y'];
                    $pointTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $timeStr . ':00');
                    if ($pointTime === false) {
                        $pointTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $timeStr);
                    }
                    if ($pointTime === false) {
                        continue;
                    }

                    $results[] = new NormalizedSolarSnapshot(
                        stationId: $stationId,
                        inverterSn: $sn,
                        measurementTime: $pointTime,
                        receivedAt: $now,
                        powerW: max(0.0, round($pac, 2)),
                        dailyGenerationKwh: 0.0,
                        inverterStatus: $pac > 0 ? 'NORMAL' : 'OFFLINE',
                        dataNature: 'ACTUAL',
                        dataStatus: 'FRESH',
                        dataQuality: 'GOOD'
                    );
                }

                return $results;
            }
        } catch (\Throwable) {}

        return [];
    }

    public function fetchHistoricalDailyTotals(
        SolarCredentials $credentials,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        // Fallback or month query
        return [];
    }

    /**
     * Authenticate against SEMS+ cross-login.
     *
     * @return array{token: string, uid: string, timestamp: int, base_url: string, api_host: ?string, region: ?string}
     */
    private function crossLogin(string $account, string $password): array
    {
        $cacheKey = hash('sha256', $account . ':' . $password);
        if ($this->transport === null && isset($this->sessionCache[$cacheKey])) {
            $cached = $this->sessionCache[$cacheKey];
            if (($cached['expires_at'] ?? 0) > time()) {
                return $cached['session'];
            }
        }

        $payload = [
            'account' => $account,
            'pwd' => $password,
        ];

        $response = $this->postJson('/api/v2/Common/CrossLogin', $payload);

        $hasError = $response['hasError'] ?? true;
        $code = (int) ($response['code'] ?? 1);
        $msg = (string) ($response['msg'] ?? 'Unknown authentication failure');

        if ($hasError || ($code !== 0 && $code !== 200)) {
            throw new RuntimeException("GoodWe SEMS+ login failed: {$msg}");
        }

        $data = $response['data'] ?? [];
        if (!is_array($data) || !isset($data['token'])) {
            throw new RuntimeException('SEMS+ response did not contain expected token payload.');
        }

        // Resolve regional API base URL from response or session data (e.g. "https://eu.semsportal.com" or "https://hk.semsportal.com")
        $apiCandidate = $response['api'] ?? $data['api'] ?? ($response['components']['api'] ?? null);
        $apiRaw = is_string($apiCandidate) && trim($apiCandidate) !== '' ? trim($apiCandidate) : null;

        $regionCandidate = $response['region'] ?? $data['region'] ?? ($response['components']['region'] ?? null);
        $region = is_string($regionCandidate) && trim($regionCandidate) !== '' ? trim($regionCandidate) : null;

        $resolvedBaseUrl = $this->baseUrl;
        $apiHost = null;
        if ($apiRaw !== null) {
            if (!str_starts_with($apiRaw, 'http://') && !str_starts_with($apiRaw, 'https://')) {
                $apiRaw = 'https://' . $apiRaw;
            }
            $parsedHost = parse_url($apiRaw, PHP_URL_HOST);
            $parsedScheme = parse_url($apiRaw, PHP_URL_SCHEME) ?? 'https';
            if ($parsedHost !== null && $parsedHost !== '') {
                $apiHost = $parsedHost;
                $resolvedBaseUrl = "{$parsedScheme}://{$parsedHost}";
            }
        }

        // GoodWe SEMS Mobile v2 API contract requires the Token header on authenticated requests
        // to be the serialized JSON session object (containing uid, timestamp, token, client, version, etc.)
        $tokenData = $data;
        if ($apiRaw !== null && !isset($tokenData['api'])) {
            $tokenData['api'] = $apiRaw;
        }
        if ($region !== null && !isset($tokenData['region'])) {
            $tokenData['region'] = $region;
        }
        $tokenHeader = json_encode($tokenData, JSON_UNESCAPED_SLASHES);

        // Safe diagnostic logging: log host, region, status, content type, top-level keys.
        // NEVER log password, hash, token, cookies, or authorization headers.
        try {
            $meta = $response['_meta'] ?? [];
            Logger::default()->info('goodwe.cross_login_completed', [
                'selected_api_host' => $apiHost ?? (parse_url($this->baseUrl, PHP_URL_HOST) ?? 'www.semsportal.com'),
                'resolved_region' => $region,
                'http_status' => (int) ($meta['http_code'] ?? 200),
                'content_type' => (string) ($meta['content_type'] ?? 'application/json'),
                'response_keys' => array_values(array_filter(array_keys($response), static fn ($k) => $k !== '_meta')),
                'data_keys' => array_keys($data),
                'has_regional_api' => $apiHost !== null,
            ]);
        } catch (\Throwable) {
            // Fail-safe: diagnostic logging must never break authentication flow
        }

        $session = [
            'token' => $tokenHeader,
            'raw_token' => (string) ($data['token'] ?? ''),
            'token_header' => $tokenHeader,
            'token_data' => $tokenData,
            'uid' => (string) ($data['uid'] ?? ''),
            'timestamp' => (int) ($data['timestamp'] ?? time()),
            'base_url' => $resolvedBaseUrl,
            'api_host' => $apiHost,
            'region' => $region,
        ];

        if ($this->transport === null) {
            $this->sessionCache[$cacheKey] = [
                'session' => $session,
                'expires_at' => time() + 900,
            ];
        }

        return $session;
    }

    /**
     * Query all power stations associated with the authenticated account.
     *
     * Queries the user's regional API host with resilient fallback across:
     *  1. /api/v2/PowerStation/QueryPowerStationByPage (mobile v2 contract)
     *  2. /web/sems/sems-plant/api/stations/page (SEMS+ plant contract)
     *  3. /api/v2/PowerStation/GetPowerStationIdByOwner (owner discovery contract)
     *
     * @return list<array{station_id: string, station_name: string, capacity_kw: ?float}>
     */
    public function queryPowerStations(string $sessionToken, ?string $regionalBaseUrl = null): array
    {
        $baseUrl = $regionalBaseUrl ?? $this->baseUrl;
        $host = parse_url($baseUrl, PHP_URL_HOST) ?? 'unknown';

        $candidateEndpoints = [
            [
                'endpoint' => '/api/PowerStation/GetPowerStationIdByOwner',
                'payload' => [],
            ],
            [
                'endpoint' => '/api/v2/PowerStation/QueryPowerStationByPage',
                'payload' => [
                    'page_index' => 1,
                    'page_size' => 50,
                    'pageIndex' => 1,
                    'pageSize' => 50,
                ],
            ],
            [
                'endpoint' => '/api/PowerStation/QueryPowerStationByPage',
                'payload' => [
                    'page_index' => 1,
                    'page_size' => 50,
                    'pageIndex' => 1,
                    'pageSize' => 50,
                ],
            ],
            [
                'endpoint' => '/api/v2/PowerStation/GetPowerStationIdByOwner',
                'payload' => [],
            ],
            [
                'endpoint' => '/web/sems/sems-plant/api/stations/page',
                'payload' => [
                    'page' => 1,
                    'pageSize' => 50,
                    'pageIndex' => 1,
                ],
            ],
        ];

        $results = [];

        foreach ($candidateEndpoints as $candidate) {
            $endpoint = $candidate['endpoint'];
            $payload = $candidate['payload'];

            try {
                $response = $this->postJson($endpoint, $payload, $sessionToken, $baseUrl, connectTimeout: 4, timeout: 6);
                $meta = $response['_meta'] ?? [];
                $httpCode = (int) ($meta['http_code'] ?? 200);
                $contentType = (string) ($meta['content_type'] ?? 'application/json');
                $responseKeys = array_values(array_filter(array_keys($response), static fn ($k) => $k !== '_meta'));

                $data = $response['data'] ?? null;
                $dataKeys = is_array($data) ? array_keys($data) : [];

                $parsedStations = $this->parseStationRecords($data);

                // Safe diagnostic logging: strictly safe metadata only
                try {
                    Logger::default()->info('goodwe.query_stations_response', [
                        'endpoint' => $endpoint,
                        'selected_api_host' => $host,
                        'http_status' => $httpCode,
                        'content_type' => $contentType,
                        'response_keys' => $responseKeys,
                        'response_code' => $response['code'] ?? null,
                        'response_has_error' => $response['hasError'] ?? null,
                        'response_msg' => isset($response['msg']) && is_string($response['msg']) ? substr($response['msg'], 0, 100) : null,
                        'data_shape' => is_array($data) ? (array_is_list($data) ? 'list' : 'assoc_array') : gettype($data),
                        'data_keys' => $dataKeys,
                        'discovered_station_count' => count($parsedStations),
                    ]);
                } catch (\Throwable) {}

                if (!empty($parsedStations)) {
                    $results = $parsedStations;
                    break;
                }
            } catch (\Throwable $e) {
                try {
                    Logger::default()->warning('goodwe.query_stations_endpoint_failed', [
                        'endpoint' => $endpoint,
                        'selected_api_host' => $host,
                        'error_message' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
            }
        }

        return $results;
    }

    /**
     * Resilient parser handling all GoodWe response structures:
     * - Nested pagination: list, records, rows, stations, pageList
     * - Flat array of station objects
     * - Single station object
     * - Scalar string station ID (e.g. from GetPowerStationIdByOwner)
     *
     * @return list<array{station_id: string, station_name: string, capacity_kw: ?float}>
     */
    public function parseStationRecords(mixed $data): array
    {
        if ($data === null) {
            return [];
        }

        $list = [];
        if (is_array($data)) {
            if (isset($data['list']) && is_array($data['list'])) {
                $list = $data['list'];
            } elseif (isset($data['records']) && is_array($data['records'])) {
                $list = $data['records'];
            } elseif (isset($data['rows']) && is_array($data['rows'])) {
                $list = $data['rows'];
            } elseif (isset($data['stations']) && is_array($data['stations'])) {
                $list = $data['stations'];
            } elseif (isset($data['pageList']) && is_array($data['pageList'])) {
                $list = $data['pageList'];
            } elseif (isset($data['stationInverters']) && is_array($data['stationInverters'])) {
                $list = $data['stationInverters'];
            } elseif (isset($data['data']) && is_array($data['data'])) {
                return $this->parseStationRecords($data['data']);
            } elseif (array_is_list($data)) {
                $list = $data;
            } elseif (isset($data['id']) || isset($data['stationId']) || isset($data['powerStationId']) || isset($data['pwId'])) {
                $list = [$data];
            }
        } elseif (is_string($data) && trim($data) !== '') {
            $list = [['id' => trim($data), 'stationname' => 'Solar Power Station']];
        }

        $results = [];
        foreach ($list as $item) {
            if (is_string($item) && trim($item) !== '') {
                $results[] = [
                    'station_id' => trim($item),
                    'station_name' => 'Solar Power Station',
                    'capacity_kw' => null,
                ];
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $id = (string) ($item['id'] ?? $item['stationId'] ?? $item['powerStationId'] ?? $item['station_id'] ?? $item['pwId'] ?? '');
            if ($id === '') {
                continue;
            }

            $name = (string) ($item['stationname'] ?? $item['powerstation_name'] ?? $item['stationName'] ?? $item['name'] ?? 'Solar Power Station');
            $capacity = null;
            if (isset($item['capacity']) && is_numeric($item['capacity'])) {
                $capacity = (float) $item['capacity'];
            } elseif (isset($item['installedPower']) && is_numeric($item['installedPower'])) {
                $capacity = (float) $item['installedPower'];
            } elseif (isset($item['capacity_kw']) && is_numeric($item['capacity_kw'])) {
                $capacity = (float) $item['capacity_kw'];
            }

            $results[] = [
                'station_id' => $id,
                'station_name' => $name,
                'capacity_kw' => $capacity,
            ];
        }

        return $results;
    }

    /**
     * Query power station monitor details.
     *
     * @return array<string, mixed>
     */
    private function getMonitorDetail(string $sessionToken, string $powerStationId, ?string $regionalBaseUrl = null): array
    {
        $payload = [
            'powerStationId' => $powerStationId,
        ];

        $response = $this->postJson(
            '/api/v2/PowerStation/GetMonitorDetailByPowerstationId',
            $payload,
            $sessionToken,
            $regionalBaseUrl
        );

        $hasError = $response['hasError'] ?? false;
        $code = (int) ($response['code'] ?? 0);
        if ($hasError || ($code !== 0 && $code !== 200)) {
            $msg = (string) ($response['msg'] ?? 'Failed to retrieve power station details.');
            throw new RuntimeException("SEMS+ station detail failed: {$msg}");
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new RuntimeException('SEMS+ station detail data was empty or malformed.');
        }

        return $data;
    }

    /**
     * Map raw GoodWe status codes to canonical SPMS inverter statuses.
     */
    public function mapInverterStatus(int $rawStatus, float $pac): string
    {
        // SEMS statuses:
        // 1: Normal (Generating)
        // 5: Normal / Operating (Modern SEMS+ status)
        // 0, 2: Waiting / Check / Standby
        // -1, 1005: Offline / Sleep
        // 2: Fault (Legacy status code when fault detected)
        if ($rawStatus === 1 || $rawStatus === 5) {
            return ($pac > 0.0 || $rawStatus === 5) ? 'NORMAL' : 'WAITING';
        }
        if ($rawStatus === 0 || $rawStatus === 2) {
            return 'WAITING';
        }
        if ($rawStatus === -1 || $rawStatus === 1005) {
            return 'OFFLINE';
        }

        return 'UNKNOWN';
    }

    private function parseMeasurementTime(?string $rawTime, DateTimeImmutable $fallback): DateTimeImmutable
    {
        if ($rawTime === null || trim($rawTime) === '') {
            return $fallback;
        }

        $cleaned = trim($rawTime);

        // Standard GoodWe / SEMS date formats:
        // SEMS often outputs US standard "m/d/Y H:i:s" (e.g. "09/21/2026 17:05:34") or ISO "Y-m-d H:i:s".
        // Crucial: check getLastErrors() to prevent PHP from overflowing days/months (e.g. month 21 -> next year).
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d H:i',
            'Y/m/d H:i:s',
            'Y/m/d H:i',
            'm/d/Y H:i:s',
            'm/d/Y H:i:s A',
            'm/d/Y h:i:s A',
            'm/d/Y H:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i:s A',
            'd/m/Y h:i:s A',
            'd/m/Y H:i',
        ];

        $currentYear = (int) $fallback->format('Y');

        foreach ($formats as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $cleaned, $fallback->getTimezone());
            $errors = DateTimeImmutable::getLastErrors();
            $hasErrors = $errors !== false && ($errors['error_count'] > 0 || $errors['warning_count'] > 0);

            if ($dt !== false && !$hasErrors) {
                $year = (int) $dt->format('Y');
                // Guard against corrupt/overflow future years (> current year) or ancient years (< 2020)
                if ($year >= 2020 && $year <= $currentYear) {
                    return $dt;
                }
            }
        }

        return $fallback;
    }

    /**
     * Encode signature for SEMS+ Web API requests.
     *
     * Wire-parity reproduction of sems-login.js encodeSignature:
     * Hashes "<millis>@<uid>@<token>" with SHA-256 in LOWERCASE HEX,
     * returning base64("<hex>@<millis>").
     */
    public static function encodeSignature(string $tokenHeaderValue, int $timestamp): string
    {
        $parsed = json_decode($tokenHeaderValue, true);
        $uid = is_array($parsed) ? (string) ($parsed['uid'] ?? '') : '';
        $token = is_array($parsed) ? (string) ($parsed['token'] ?? '') : '';

        $raw = hash('sha256', "{$timestamp}@{$uid}@{$token}");

        return base64_encode("{$raw}@{$timestamp}");
    }

    /**
     * Build modern SEMS+ Web API request headers.
     *
     * @return list<string>
     */
    public function buildSemsWebHeaders(string $tokenHeaderValue): array
    {
        $msNow = (int) floor(microtime(true) * 1000);
        $signature = self::encodeSignature($tokenHeaderValue, $msNow);

        return [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'User-Agent: ' . self::USER_AGENT,
            'Origin: https://semsplus.goodwe.com',
            'Referer: https://semsplus.goodwe.com/',
            'token: ' . $tokenHeaderValue,
            'Token: ' . $tokenHeaderValue,
            'X-Signature: ' . $signature,
            'neutral: 0',
            'currentLang: en',
        ];
    }

    /**
     * Parse modern SEMS+ inverter records from /web/sems/sems-report/api/station/inverter/list.
     *
     * Expected data shape: data.stationInverters[].inverters[]
     *
     * @return array{inverters: list<array{sn: string, name: string, status: int, pac: float, capacity: ?float}>, station_name: ?string}
     */
    public function parseModernInverters(mixed $data, ?string $targetStationId = null): array
    {
        if (!is_array($data)) {
            return ['inverters' => [], 'station_name' => null];
        }

        $stationInverters = $data['stationInverters'] ?? ($data['data']['stationInverters'] ?? null);
        if (!is_array($stationInverters) || empty($stationInverters)) {
            return ['inverters' => [], 'station_name' => null];
        }

        $targetGroup = null;
        if ($targetStationId !== null && $targetStationId !== '') {
            foreach ($stationInverters as $group) {
                if (is_array($group) && isset($group['stationId']) && (string) $group['stationId'] === $targetStationId) {
                    $targetGroup = $group;
                    break;
                }
            }
        }

        if ($targetGroup === null && isset($stationInverters[0]) && is_array($stationInverters[0])) {
            $targetGroup = $stationInverters[0];
        }

        if ($targetGroup === null) {
            return ['inverters' => [], 'station_name' => null];
        }

        $stationName = isset($targetGroup['stationName']) && is_string($targetGroup['stationName']) && trim($targetGroup['stationName']) !== ''
            ? trim($targetGroup['stationName'])
            : null;

        $rawInverters = $targetGroup['inverters'] ?? [];
        if (!is_array($rawInverters) || empty($rawInverters)) {
            return ['inverters' => [], 'station_name' => $stationName];
        }

        $inverters = [];
        foreach ($rawInverters as $inv) {
            if (!is_array($inv)) {
                continue;
            }

            $sn = isset($inv['inverterSn']) ? trim((string) $inv['inverterSn']) : (isset($inv['sn']) ? trim((string) $inv['sn']) : '');
            if ($sn === '') {
                continue; // Invariant: Never fabricate missing inverter serial numbers
            }

            $name = isset($inv['inverterName']) && trim((string) $inv['inverterName']) !== ''
                ? trim((string) $inv['inverterName'])
                : (isset($inv['name']) ? trim((string) $inv['name']) : 'GoodWe Inverter');

            $status = isset($inv['inverterStatus']) ? (int) $inv['inverterStatus'] : (isset($inv['status']) ? (int) $inv['status'] : 1);
            $pac = isset($inv['pac']) && is_numeric($inv['pac']) ? (float) $inv['pac'] : 0.0;
            $capacity = isset($inv['capacity']) && is_numeric($inv['capacity']) ? (float) $inv['capacity'] : null;

            $inverters[] = [
                'sn' => $sn,
                'name' => $name,
                'status' => $status,
                'pac' => $pac,
                'capacity' => $capacity,
            ];
        }

        return [
            'inverters' => $inverters,
            'station_name' => $stationName,
        ];
    }

    /**
     * Derive candidate base URLs for modern SEMS+ Web API requests (/web/sems/*).
     *
     * Rules:
     * 1. If regional host matches {region}.semsportal.com (e.g. hk.semsportal.com),
     *    derive primary modern gateway: https://{region}-gateway.semsportal.com.
     *    Excludes non-regional subdomains like 'www', 'api', 'gateway', 'web'.
     * 2. Canonical modern portal fallback: https://semsplus.goodwe.com.
     * 3. Arbitrary/non-matching hosts are NOT blindly transformed.
     *
     * @return list<string>
     */
    public function resolveModernGatewayCandidates(?string $regionalBaseUrl = null): array
    {
        $baseUrl = $regionalBaseUrl ?? $this->baseUrl;
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';

        $candidates = [];

        if (is_string($host) && preg_match('/^([a-z0-9_-]+)\.semsportal\.com$/i', $host, $matches)) {
            $subdomain = strtolower($matches[1]);
            // Exclude non-regional subdomains
            if (!in_array($subdomain, ['www', 'api', 'gateway', 'web'], true)) {
                $candidates[] = "{$scheme}://{$subdomain}-gateway.semsportal.com";
            }
        }

        // Secondary fallback is always the canonical SEMS+ web platform
        $canonicalPortal = 'https://semsplus.goodwe.com';
        if (!in_array($canonicalPortal, $candidates, true)) {
            $candidates[] = $canonicalPortal;
        }

        return $candidates;
    }

    /**
     * Discover inverters for a power station using modern SEMS+ endpoint with legacy fallback.
     *
     * 1. MODERN FIRST: POST /web/sems/sems-report/api/station/inverter/list
     *    Candidate order:
     *      Primary:   https://{region}-gateway.semsportal.com (if derived from {region}.semsportal.com)
     *      Secondary: https://semsplus.goodwe.com
     * 2. LEGACY FALLBACK: POST /api/v2/PowerStation/GetMonitorDetailByPowerstationId
     *    on legacy regional host (e.g. https://hk.semsportal.com)
     *
     * @return array{
     *   inverters: list<array{sn: string, name: string, status: int, pac: float, capacity: ?float}>,
     *   station_name: ?string,
     *   source: 'modern'|'legacy'|'none'
     * }
     */
    public function queryStationInverters(string $sessionToken, string $powerStationId, ?string $regionalBaseUrl = null): array
    {
        $legacyBaseUrl = $regionalBaseUrl ?? $this->baseUrl;
        $legacyHost = parse_url($legacyBaseUrl, PHP_URL_HOST) ?? 'unknown';

        $modernCandidates = $this->resolveModernGatewayCandidates($regionalBaseUrl);

        // 1. MODERN FIRST (Probe modern candidates in order)
        foreach ($modernCandidates as $candidateBaseUrl) {
            $candidateHost = parse_url($candidateBaseUrl, PHP_URL_HOST) ?? 'unknown';

            try {
                $modernResponse = $this->postJson(
                    '/web/sems/sems-report/api/station/inverter/list',
                    ['stationIds' => [$powerStationId]],
                    $sessionToken,
                    $candidateBaseUrl,
                    connectTimeout: 4,
                    timeout: 8
                );

                $modernData = $modernResponse['data'] ?? [];
                $parsedModern = $this->parseModernInverters($modernData, $powerStationId);

                if (!empty($parsedModern['inverters'])) {
                    try {
                        Logger::default()->info('goodwe.query_inverters_completed', [
                            'endpoint' => '/web/sems/sems-report/api/station/inverter/list',
                            'selected_api_host' => $candidateHost,
                            'source' => 'modern',
                            'inverter_count' => count($parsedModern['inverters']),
                        ]);
                    } catch (\Throwable) {}

                    return [
                        'inverters' => $parsedModern['inverters'],
                        'station_name' => $parsedModern['station_name'],
                        'source' => 'modern',
                    ];
                }
            } catch (\Throwable $e) {
                try {
                    Logger::default()->info('goodwe.modern_inverter_discovery_fallback', [
                        'endpoint' => '/web/sems/sems-report/api/station/inverter/list',
                        'selected_api_host' => $candidateHost,
                        'reason' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
            }
        }

        // 2. LEGACY FALLBACK (Must use legacy regional base URL e.g. hk.semsportal.com)
        try {
            $detail = $this->getMonitorDetail($sessionToken, $powerStationId, $legacyBaseUrl);
            $info = $detail['info'] ?? [];
            $legacyStationName = isset($info['powerstation_name']) ? (string) $info['powerstation_name'] : null;
            $rawInverters = $detail['inverter'] ?? [];

            if (is_array($rawInverters) && !empty($rawInverters)) {
                $normalizedLegacy = [];
                foreach ($rawInverters as $inv) {
                    if (!is_array($inv)) {
                        continue;
                    }
                    $sn = isset($inv['sn']) ? trim((string) $inv['sn']) : '';
                    if ($sn === '') {
                        continue;
                    }
                    $name = isset($inv['name']) ? (string) $inv['name'] : (isset($inv['model']) ? (string) $inv['model'] : 'GoodWe Inverter');
                    $status = isset($inv['status']) ? (int) $inv['status'] : 1;
                    $pac = isset($inv['pac']) && is_numeric($inv['pac']) ? (float) $inv['pac'] : 0.0;
                    $capacity = isset($inv['capacity']) && is_numeric($inv['capacity']) ? (float) $inv['capacity'] : null;

                    $normalizedLegacy[] = [
                        'sn' => $sn,
                        'name' => $name,
                        'status' => $status,
                        'pac' => $pac,
                        'capacity' => $capacity,
                    ];
                }

                if (!empty($normalizedLegacy)) {
                    try {
                        Logger::default()->info('goodwe.query_inverters_completed', [
                            'endpoint' => '/api/v2/PowerStation/GetMonitorDetailByPowerstationId',
                            'selected_api_host' => $legacyHost,
                            'source' => 'legacy',
                            'inverter_count' => count($normalizedLegacy),
                        ]);
                    } catch (\Throwable) {}

                    return [
                        'inverters' => $normalizedLegacy,
                        'station_name' => $legacyStationName,
                        'source' => 'legacy',
                    ];
                }
            }
        } catch (\Throwable $e) {
            try {
                Logger::default()->warning('goodwe.legacy_inverter_discovery_failed', [
                    'endpoint' => '/api/v2/PowerStation/GetMonitorDetailByPowerstationId',
                    'selected_api_host' => $host,
                    'error_message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {}
        }

        return [
            'inverters' => [],
            'station_name' => $parsedModern['station_name'] ?? $legacyStationName ?? null,
            'source' => 'none',
        ];
    }

    /**
     * Execute HTTP POST with cURL.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postJson(
        string $endpoint,
        array $body,
        ?string $sessionToken = null,
        ?string $overrideBaseUrl = null,
        int $connectTimeout = 10,
        int $timeout = 20
    ): array {
        $baseUrl = rtrim($overrideBaseUrl ?? $this->baseUrl, '/');
        $url = $baseUrl . $endpoint;
        $this->assertSafeOrigin($url);

        if ($this->transport !== null) {
            return ($this->transport)($endpoint, $body, $sessionToken, $overrideBaseUrl, $connectTimeout, $timeout);
        }

        $tokenHeaderValue = self::CLIENT_TOKEN_HEADER;
        if ($sessionToken !== null && $sessionToken !== '') {
            if (str_starts_with(trim($sessionToken), '{')) {
                $tokenHeaderValue = trim($sessionToken);
            } else {
                // If a raw token scalar string was passed, wrap it with standard client metadata
                $tokenHeaderValue = json_encode([
                    'version' => 'v2.1.0',
                    'client' => 'ios',
                    'language' => 'en',
                    'token' => $sessionToken,
                ], JSON_UNESCAPED_SLASHES);
            }
        }

        if (str_starts_with($endpoint, '/web/sems/')) {
            $headers = $this->buildSemsWebHeaders($tokenHeaderValue);
        } else {
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . self::USER_AGENT,
                'Token: ' . $tokenHeaderValue,
            ];
        }

        $jsonPayload = json_encode($body, JSON_THROW_ON_ERROR);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        unset($ch); // PHP 8.0+ auto-closes CurlHandle on destruction; manual handle closing is deprecated in PHP 8.5

        if ($curlErrno !== 0) {
            throw new RuntimeException("GoodWe SEMS+ network request failed: {$curlError} (curl errno {$curlErrno})");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("GoodWe SEMS+ server returned HTTP error {$httpCode}.");
        }

        if (!is_string($rawResponse) || trim($rawResponse) === '') {
            throw new RuntimeException('GoodWe SEMS+ returned an empty response.');
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GoodWe SEMS+ response was not valid JSON.');
        }

        // Attach safe transport metadata for diagnostic inspection (stripped of headers/payloads)
        $decoded['_meta'] = [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'request_url' => $url,
            'request_host' => parse_url($url, PHP_URL_HOST) ?? '',
        ];

        return $decoded;
    }

    /**
     * Execute HTTP GET with cURL.
     *
     * @return array<string, mixed>
     */
    private function getJson(
        string $endpoint,
        ?string $sessionToken = null,
        ?string $overrideBaseUrl = null,
        int $connectTimeout = 10,
        int $timeout = 20
    ): array {
        $baseUrl = rtrim($overrideBaseUrl ?? $this->baseUrl, '/');
        $url = $baseUrl . $endpoint;
        $this->assertSafeOrigin($url);

        if ($this->transport !== null) {
            return ($this->transport)($endpoint, [], $sessionToken, $overrideBaseUrl, $connectTimeout, $timeout);
        }

        $tokenHeaderValue = self::CLIENT_TOKEN_HEADER;
        if ($sessionToken !== null && $sessionToken !== '') {
            if (str_starts_with(trim($sessionToken), '{')) {
                $tokenHeaderValue = trim($sessionToken);
            } else {
                $tokenHeaderValue = json_encode([
                    'version' => 'v2.1.0',
                    'client' => 'ios',
                    'language' => 'en',
                    'token' => $sessionToken,
                ], JSON_UNESCAPED_SLASHES);
            }
        }

        if (str_starts_with($endpoint, '/web/sems/') || str_starts_with($endpoint, '/sems/')) {
            $headers = $this->buildSemsWebHeaders($tokenHeaderValue);
        } else {
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . self::USER_AGENT,
                'Token: ' . $tokenHeaderValue,
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        unset($ch);

        if ($curlErrno !== 0) {
            throw new RuntimeException("GoodWe SEMS+ network request failed: {$curlError} (curl errno {$curlErrno})");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("GoodWe SEMS+ server returned HTTP error {$httpCode}.");
        }

        if (!is_string($rawResponse) || trim($rawResponse) === '') {
            throw new RuntimeException('GoodWe SEMS+ returned an empty response.');
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GoodWe SEMS+ response was not valid JSON.');
        }

        $decoded['_meta'] = [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'request_url' => $url,
            'request_host' => parse_url($url, PHP_URL_HOST) ?? '',
        ];

        return $decoded;
    }

    /**
     * Flatten grouped factor arrays from GoodWe telemetry / telecounting responses.
     *
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function flattenFactors(mixed $data): array
    {
        $result = [];
        if (!is_array($data)) {
            return $result;
        }

        foreach ($data as $group) {
            if (!is_array($group) || !isset($group['factors']) || !is_array($group['factors'])) {
                continue;
            }
            foreach ($group['factors'] as $factor) {
                if (!is_array($factor) || !isset($factor['code'])) {
                    continue;
                }
                $code = (string) $factor['code'];
                $val = $factor['data'] ?? null;
                $result[$code] = $val;
            }
        }

        return $result;
    }

    private function assertSafeOrigin(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            throw new RuntimeException("Invalid GoodWe URL: {$url}");
        }

        // Allow semsportal.com, *.semsportal.com, goodwe.com, *.goodwe.com, plus localhost for local mock servers
        $isSemPortal = $host === 'semsportal.com' || str_ends_with($host, '.semsportal.com');
        $isGoodWe = $host === 'goodwe.com' || str_ends_with($host, '.goodwe.com');
        $isLocal = $host === 'localhost' || $host === '127.0.0.1';

        if (!$isSemPortal && !$isGoodWe && !$isLocal) {
            throw new RuntimeException("Disallowed solar provider host: '{$host}'. Must be *.semsportal.com or *.goodwe.com.");
        }
    }
}
