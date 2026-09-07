<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Services\HealthService;

final class HealthController
{
    public function __construct(
        private readonly HealthService $healthService = new HealthService()
    ) {
    }

    public function health(Request $request): Response
    {
        $health = $this->healthService->check();

        $status = $health['overall'] === 'healthy' ? 200 : 503;

        return Response::success($health, $status);
    }
}
