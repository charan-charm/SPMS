<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Household\HouseholdService;
use App\Domain\Solar\DTO\SolarCredentials;
use App\Domain\Solar\SolarService;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use DateTimeImmutable;
use Throwable;

/**
 * SPMS O4 — Solar Connection & Telemetry Controller (docs/10 D-059, D-060, D-061).
 *
 * Exposes authenticated, household-scoped REST endpoints for solar provider integration:
 *  - GET    /api/v1/solar/connection
 *  - POST   /api/v1/solar/connection/test
 *  - POST   /api/v1/solar/connection
 *  - DELETE /api/v1/solar/connection
 *  - GET    /api/v1/solar/latest
 *  - GET    /api/v1/solar/history
 *  - POST   /api/v1/solar/sync
 *
 * Security Invariants:
 *  - Every endpoint requires an authenticated account with an active household membership.
 *  - Never echoes plain passwords or AEAD ciphertexts back to the client.
 *  - State-changing mutations (POST/DELETE) are guarded by CSRF middleware.
 */
final class SolarController
{
    public function __construct(
        private readonly SolarService $solarService = new SolarService(),
        private readonly HouseholdService $households = new HouseholdService()
    ) {
    }

    /**
     * GET /api/v1/solar/connection
     */
    public function connection(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $conn = $this->solarService->getConnection($householdId);

        return Response::success([
            'connection' => $conn,
        ]);
    }

    /**
     * POST /api/v1/solar/stations/discover
     */
    public function discoverStations(Request $request): Response
    {
        $this->requireHouseholdId($request);
        $credentials = $this->extractCredentials($request);

        $stations = $this->solarService->discoverStations($credentials);

        return Response::success([
            'stations' => $stations,
            'count' => count($stations),
        ]);
    }

    /**
     * POST /api/v1/solar/connection/test
     */
    public function testConnection(Request $request): Response
    {
        $this->requireHouseholdId($request);
        $credentials = $this->extractCredentials($request);

        $result = $this->solarService->testConnection($credentials);

        return Response::success([
            'test_result' => $result->toArray(),
        ]);
    }

    /**
     * POST /api/v1/solar/connection
     */
    public function saveConnection(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $credentials = $this->extractCredentials($request);

        try {
            $testResult = $this->solarService->saveConnection($householdId, $credentials);
            $conn = $this->solarService->getConnection($householdId);

            return Response::success([
                'connection' => $conn,
                'test_result' => $testResult->toArray(),
            ], 201);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $code = str_contains($msg, 'Multiple power stations') ? 'MULTIPLE_STATIONS' : 'SOLAR_CONNECTION_FAILED';
            throw new AppException(
                $code,
                $msg,
                422,
                ['error' => $msg]
            );
        }
    }

    /**
     * DELETE /api/v1/solar/connection
     */
    public function disconnect(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $disconnected = $this->solarService->disconnect($householdId);

        return Response::success([
            'disconnected' => $disconnected,
        ]);
    }

    /**
     * GET /api/v1/solar/latest
     */
    public function latest(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $latest = $this->solarService->getLatestSnapshot($householdId);

        return Response::success([
            'latest' => $latest,
        ]);
    }

    /**
     * GET /api/v1/solar/history
     */
    public function history(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);

        $dateParam = $request->queryParam('date');
        $fromParam = $request->queryParam('from');
        $toParam = $request->queryParam('to');
        $limitParam = $request->queryParam('limit');

        $from = null;
        $to = null;

        if (is_string($dateParam) && trim($dateParam) !== '') {
            try {
                $cleanDate = trim($dateParam);
                $from = new DateTimeImmutable("{$cleanDate} 00:00:00");
                $to = new DateTimeImmutable("{$cleanDate} 23:59:59");
            } catch (Throwable) {}
        }

        if ($from === null && is_string($fromParam) && trim($fromParam) !== '') {
            try {
                $from = new DateTimeImmutable(trim($fromParam));
            } catch (Throwable) {}
        }

        if ($to === null && is_string($toParam) && trim($toParam) !== '') {
            try {
                $to = new DateTimeImmutable(trim($toParam));
            } catch (Throwable) {}
        }

        $limit = ($dateParam !== null && trim($dateParam) !== '') ? 1000 : 500;
        if (is_string($limitParam) && is_numeric($limitParam)) {
            $limit = max(1, min(1000, (int) $limitParam));
        }

        $history = $this->solarService->getHistory($householdId, $from, $to, $limit);

        return Response::success([
            'history' => $history,
            'count' => count($history),
        ]);
    }

    /**
     * GET /api/v1/solar/days
     */
    public function days(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $days = $this->solarService->getDailySummaries($householdId);

        return Response::success([
            'days' => $days,
            'count' => count($days),
        ]);
    }

    /**
     * POST /api/v1/solar/sync
     */
    public function sync(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);

        $dateParam = $request->bodyParam('date') ?? $request->queryParam('date');
        $date = null;
        if (is_string($dateParam) && trim($dateParam) !== '') {
            try {
                $date = new DateTimeImmutable(trim($dateParam));
            } catch (Throwable) {}
        }

        try {
            $snapshot = $this->solarService->syncHouseholdTelemetry($householdId, $date);

            try {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $intel = new \App\Domain\Intelligence\IntelligenceService(new \App\Repositories\InsightRepository());
                $intel->refreshFromEnergyPipeline($householdId, $now);
                $recs = new \App\Domain\Recommendation\RecommendationService();
                $recs->generate($householdId, $now, true);
            } catch (Throwable) {
                // Non-blocking pipeline update
            }

            return Response::success([
                'synced' => true,
                'snapshot' => $snapshot?->toArray(),
                'latest' => $this->solarService->getLatestSnapshot($householdId),
            ]);
        } catch (Throwable $e) {
            throw new AppException(
                'SOLAR_SYNC_FAILED',
                $e->getMessage(),
                422,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * POST /api/v1/solar/sync/auto
     *
     * Opportunistic visit-triggered synchronization.
     * Non-blocking, rate-limited, and concurrency-locked.
     */
    public function autoSync(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $result = $this->solarService->syncIfStale($householdId, 300);

        if (($result['synced'] ?? false) === true) {
            try {
                $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $intel = new \App\Domain\Intelligence\IntelligenceService(new \App\Repositories\InsightRepository());
                $intel->refreshFromEnergyPipeline($householdId, $now);
                $recs = new \App\Domain\Recommendation\RecommendationService();
                $recs->generate($householdId, $now, true);
            } catch (Throwable) {
                // Non-blocking pipeline update
            }
        }

        return Response::success($result);
    }

    private function requireHouseholdId(Request $request): string
    {
        $account = Authorization::requireAccount($request);
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException(
                'HOUSEHOLD_NOT_FOUND',
                'An active household membership is required to access solar integration.',
                404
            );
        }

        return $householdId;
    }

    private function extractCredentials(Request $request, bool $stationRequired = false): SolarCredentials
    {
        $account = $request->bodyParam('account');
        if (!is_string($account) || trim($account) === '') {
            throw new AppException('VALIDATION_ERROR', 'account is required.', 422, ['field' => 'account']);
        }

        $password = $request->bodyParam('password');
        if (!is_string($password) || trim($password) === '') {
            throw new AppException('VALIDATION_ERROR', 'password is required.', 422, ['field' => 'password']);
        }

        $stationId = $request->bodyParam('station_id');
        $effectiveStationId = is_string($stationId) && trim($stationId) !== '' ? trim($stationId) : null;
        if ($stationRequired && $effectiveStationId === null) {
            throw new AppException('VALIDATION_ERROR', 'station_id is required.', 422, ['field' => 'station_id']);
        }

        $inverterSn = $request->bodyParam('inverter_sn');
        $effectiveSn = is_string($inverterSn) && trim($inverterSn) !== '' ? trim($inverterSn) : null;

        return new SolarCredentials(
            account: trim($account),
            password: $password,
            stationId: $effectiveStationId,
            inverterSn: $effectiveSn
        );
    }
}
