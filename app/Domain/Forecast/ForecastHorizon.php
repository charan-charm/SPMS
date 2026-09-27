<?php

declare(strict_types=1);

namespace App\Domain\Forecast;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F3 — Forecast Horizon & Time Windows (docs/01 §14, docs/02 §2, docs/04 §16).
 *
 * Encapsulates the officially supported forecast horizons:
 * - DAY_AHEAD: 24 to 48 hours, hourly resolution (near-term intraday and next-day operations)
 * - SEVEN_DAY: 7 days, daily resolution (weekly trend & routine planning)
 * - MONTHLY: 30 days / billing cycle, daily/monthly resolution (monthly cost & utilization planning)
 */
final class ForecastHorizon
{
    public const DAY_AHEAD = 'DAY_AHEAD';
    public const SEVEN_DAY = 'SEVEN_DAY';
    public const MONTHLY = 'MONTHLY';

    public const ALL = [
        self::DAY_AHEAD,
        self::SEVEN_DAY,
        self::MONTHLY,
    ];

    public const RESOLUTION_HOURLY = 'HOURLY';
    public const RESOLUTION_DAILY = 'DAILY';
    public const RESOLUTION_MONTHLY = 'MONTHLY';

    /**
     * Validate and normalize a horizon string.
     */
    public static function validate(string $horizon): string
    {
        $normalized = strtoupper(trim($horizon));
        if (!in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid forecast horizon "%s". Supported horizons: %s.',
                $horizon,
                implode(', ', self::ALL)
            ));
        }

        return $normalized;
    }

    /**
     * Default resolution for a given horizon.
     */
    public static function defaultResolution(string $horizon): string
    {
        return match (self::validate($horizon)) {
            self::DAY_AHEAD => self::RESOLUTION_HOURLY,
            self::SEVEN_DAY => self::RESOLUTION_DAILY,
            self::MONTHLY => self::RESOLUTION_DAILY,
        };
    }

    /**
     * Compute start and end timestamps for a horizon from a given reference point.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, hours: int, days: int, resolution: string}
     */
    public static function computeTimeWindow(string $horizon, ?DateTimeImmutable $reference = null, ?DateTimeZone $timezone = null): array
    {
        $tz = $timezone ?? new DateTimeZone('UTC');
        $now = ($reference ?? new DateTimeImmutable('now'))->setTimezone($tz);
        $canonicalHorizon = self::validate($horizon);

        return match ($canonicalHorizon) {
            self::DAY_AHEAD => [
                'start' => $now->setTime((int) $now->format('H'), 0, 0),
                'end' => $now->setTime((int) $now->format('H'), 0, 0)->modify('+24 hours'),
                'hours' => 24,
                'days' => 1,
                'resolution' => self::RESOLUTION_HOURLY,
            ],
            self::SEVEN_DAY => [
                'start' => $now->setTime(0, 0, 0),
                'end' => $now->setTime(0, 0, 0)->modify('+7 days'),
                'hours' => 168,
                'days' => 7,
                'resolution' => self::RESOLUTION_DAILY,
            ],
            self::MONTHLY => [
                'start' => $now->setTime(0, 0, 0),
                'end' => $now->setTime(0, 0, 0)->modify('+30 days'),
                'hours' => 720,
                'days' => 30,
                'resolution' => self::RESOLUTION_DAILY,
            ],
        };
    }
}
