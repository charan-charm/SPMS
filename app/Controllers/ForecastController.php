<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Household\HouseholdService;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use InvalidArgumentException;
use Throwable;

/**
 * F3 — Forecasting Controller (docs/06 §3, docs/10 D-040).
 *
 * Exposes authenticated, household-scoped endpoints for solar and consumption forecasts:
 * - GET  /api/v1/forecasts           Retrieve current forecast (cached if recent)
 * - POST /api/v1/forecasts/generate  Force recalculation and store fresh snapshot
 */
final class ForecastController
{
    public function __construct(
        private readonly ForecastService $forecastService = new ForecastService(),
        private readonly HouseholdService $households = new HouseholdService()
    ) {
    }

    /**
     * GET /api/v1/forecasts
     */
    public function index(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $horizon = $request->queryParam('horizon', ForecastHorizon::DAY_AHEAD);

        try {
            $validHorizon = ForecastHorizon::validate((string) $horizon);
        } catch (InvalidArgumentException $e) {
            throw new AppException('VALIDATION_ERROR', $e->getMessage(), 422, ['field' => 'horizon']);
        }

        $result = $this->forecastService->getForecast($householdId, $validHorizon, forceFresh: false);

        return Response::success($result);
    }

    /**
     * POST /api/v1/forecasts/generate
     */
    public function generate(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $horizon = $request->bodyParam('horizon', ForecastHorizon::DAY_AHEAD);

        try {
            $validHorizon = ForecastHorizon::validate((string) $horizon);
        } catch (InvalidArgumentException $e) {
            throw new AppException('VALIDATION_ERROR', $e->getMessage(), 422, ['field' => 'horizon']);
        }

        $result = $this->forecastService->getForecast($householdId, $validHorizon, forceFresh: true);

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $intel = new \App\Domain\Intelligence\IntelligenceService(new \App\Repositories\InsightRepository());
            $intel->refreshFromEnergyPipeline($householdId, $now);
            $recs = new \App\Domain\Recommendation\RecommendationService();
            $recs->generate($householdId, $now, true);
        } catch (\Throwable) {
            // Non-blocking pipeline update
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
                'An active household membership is required to access energy forecasts.',
                404
            );
        }

        return $householdId;
    }
}
