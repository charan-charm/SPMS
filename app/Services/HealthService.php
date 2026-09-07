<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\HealthRepository;
use RuntimeException;

final class HealthService
{
    public function __construct(
        private readonly HealthRepository $healthRepository = new HealthRepository()
    ) {
    }

    public function check(): array
    {
        $databaseState = $this->checkDatabase();

        $overall = $databaseState['state'] === 'connected' ? 'healthy' : 'degraded';

        return [
            'overall' => $overall,
            'application' => ['state' => 'available'],
            'database' => $databaseState,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            $this->healthRepository->ping();

            return ['state' => 'connected'];
        } catch (RuntimeException) {
            return ['state' => 'unavailable'];
        }
    }
}
