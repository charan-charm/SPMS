<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

/**
 * SPMS F7 — Recommendation Thresholds & Configuration (docs/01 §15, docs/02 §2 F7).
 *
 * Centralized, deterministic, configurable thresholds. No magic numbers scattered in code.
 * Units:
 *  - Energy: kWh
 *  - Monetary: INR
 *  - Time: seconds / hours
 */
final class RecommendationThresholds
{
    /** Minimum potential solar surplus required to trigger solar window / utilization recommendation */
    public const MIN_SURPLUS_KWH = 0.500;

    /** Minimum potential deficit required to trigger deficit window recommendation */
    public const MIN_DEFICIT_KWH = 0.500;

    /** Minimum difference in diurnal pattern (daytime surplus vs evening deficit) */
    public const MIN_DIURNAL_SWING_KWH = 0.750;

    /** Percentage increase in consumption required for high demand reduction recommendation */
    public const HIGH_CONSUMPTION_CHANGE_PCT = 15.0;

    /** Percentage decline in solar generation required for solar decline response recommendation */
    public const SOLAR_DECLINE_CHANGE_PCT = 15.0;

    /** Telemetry age beyond which GoodWe data is considered stale */
    public const STALE_DATA_SECONDS = 900; // 15 minutes

    /** Default duration in hours for recommendation validity */
    public const DEFAULT_VALIDITY_HOURS = 24;

    /** Cooldown period in hours to avoid repeating dismissed/equivalent recommendations */
    public const COOLDOWN_HOURS = 12;

    /** Maximum active recommendations shown to prevent "wall of cards" */
    public const MAX_ACTIVE_RECOMMENDATIONS = 5;
    public const MAX_ACTIVE_PER_HOUSEHOLD = self::MAX_ACTIVE_RECOMMENDATIONS;

    public function __construct(
        public readonly float $minSurplusKwh = self::MIN_SURPLUS_KWH,
        public readonly float $minDeficitKwh = self::MIN_DEFICIT_KWH,
        public readonly float $minDiurnalSwingKwh = self::MIN_DIURNAL_SWING_KWH,
        public readonly float $highConsumptionChangePct = self::HIGH_CONSUMPTION_CHANGE_PCT,
        public readonly float $solarDeclineChangePct = self::SOLAR_DECLINE_CHANGE_PCT,
        public readonly int $staleDataSeconds = self::STALE_DATA_SECONDS,
        public readonly int $defaultValidityHours = self::DEFAULT_VALIDITY_HOURS,
        public readonly int $cooldownHours = self::COOLDOWN_HOURS,
        public readonly int $maxActiveRecommendations = self::MAX_ACTIVE_RECOMMENDATIONS
    ) {
    }

    public static function default(): self
    {
        return new self();
    }
}
