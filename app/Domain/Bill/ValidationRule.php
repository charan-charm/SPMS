<?php

declare(strict_types=1);

namespace App\Domain\Bill;

/**
 * B1 — Deterministic validation rules (docs/10 D-067 §10).
 *
 * Exactly the three ratified cross-checks the Mistral testing phase
 * established:
 *
 *   METER_CONSUMPTION:  present_meter_reading - previous_meter_reading
 *                       == consumption_kwh
 *   SOLAR_PRODUCTION:   present_solar_reading - previous_solar_reading
 *                       == solar_units_kwh
 *   NET_UNITS:          consumption_kwh - solar_units_kwh == net_units_kwh
 *                       (sign preserved — expected -177 vs extracted 177
 *                        is a CONFLICT, never an auto-correction)
 */
final class ValidationRule
{
    public const METER_CONSUMPTION = 'METER_CONSUMPTION';
    public const SOLAR_PRODUCTION = 'SOLAR_PRODUCTION';
    public const NET_UNITS = 'NET_UNITS';

    public const ALL = [self::METER_CONSUMPTION, self::SOLAR_PRODUCTION, self::NET_UNITS];

    public static function isValid(string $rule): bool
    {
        return in_array($rule, self::ALL, true);
    }
}
