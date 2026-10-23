<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Household\HouseholdService;
use App\Domain\Weather\WeatherService;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;

/**
 * SPMS Weather Intelligence Controller (Section 5).
 *
 * Exposes authenticated, household-scoped weather intelligence:
 * - GET /api/v1/weather
 *
 * Strictly household-scoped:
 * - Derives household server-side from authenticated membership.
 * - Rejects arbitrary coordinates or foreign household scopes.
 * - Exposes zero provider credentials or external secrets.
 */
final class WeatherController
{
    public function __construct(
        private readonly WeatherService $weatherService = new WeatherService(),
        private readonly HouseholdService $households = new HouseholdService()
    ) {
    }

    /**
     * GET /api/v1/weather
     */
    public function current(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $result = $this->weatherService->getWeatherForHousehold($householdId);

        return Response::success($result);
    }

    private function requireHouseholdId(Request $request): string
    {
        $account = Authorization::requireAccount($request);
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException(
                'HOUSEHOLD_NOT_FOUND',
                'An active household membership is required to access weather intelligence.',
                404
            );
        }

        return $householdId;
    }
}
