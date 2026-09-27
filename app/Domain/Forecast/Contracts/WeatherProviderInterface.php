<?php

declare(strict_types=1);

namespace App\Domain\Forecast\Contracts;

use DateTimeImmutable;

/**
 * F3 — Weather & Solar Irradiance Provider Interface (docs/01 §12, docs/04 §1205).
 *
 * Weather information is supporting predictive evidence, NOT the source of
 * actual solar measurements. GoodWe remains the authoritative source for actuals.
 *
 * Invariant: Provider failures or timeouts must return a failure/unavailable
 * payload with error details so the forecasting engine can degrade gracefully
 * and record an explicit data limitation, rather than crashing or inventing weather data.
 */
interface WeatherProviderInterface
{
    /**
     * Retrieve solar irradiance and atmospheric factors for a time window.
     *
     * @return array{
     *     available: bool,
     *     provider: string,
     *     location: array{lat: float, lon: float},
     *     retrieved_at: string,
     *     error: ?string,
     *     hourly_factors: list<array{
     *         timestamp: string,
     *         clear_sky_index: float,
     *         cloud_cover_pct: float,
     *         temperature_c: ?float,
     *         condition: string
     *     }>
     * }
     */
    public function getSolarFactors(
        float $latitude,
        float $longitude,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array;
}
