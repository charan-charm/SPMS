<?php

declare(strict_types=1);

namespace App\Domain\Household;

/**
 * W3.1 — Derived onboarding readiness (docs/10 D-065).
 *
 * A capability/readiness projection computed from authoritative facts
 * that already exist — never stored, never a workflow state machine.
 * There is deliberately no onboarding progress table: the frontend
 * reconstructs its steps from this projection plus the account state
 * (docs/04 §35: UI is rebuilt from current authoritative state, not a
 * snapshot).
 *
 * Honesty rules honored here:
 * - UNAVAILABLE means the source does not exist in this system yet —
 *   it is NOT a queried-and-empty result and NOT a zero. Solar distinguishes
 *   "not connected", "connected", and "unavailable" truthfully.
 * - No numbers, percentages, scores or predictions are invented
 *   (docs/04 §15/§20 no-fake-values rules).
 */
final class OnboardingReadiness
{
    /** Capability states for sources. */
    public const SOURCE_UNAVAILABLE = 'UNAVAILABLE';
    public const ANALYSIS_LIMITED = 'LIMITED';
    public const SOLAR_CONNECTED = 'CONNECTED';
    public const SOLAR_NOT_CONFIGURED = 'NOT_CONFIGURED';

    /**
     * @return array<string, bool|string>
     */
    public static function derive(
        bool $emailVerified,
        ?Household $activeHousehold,
        string $solarStatus = self::SOURCE_UNAVAILABLE
    ): array {
        return [
            'email_verified' => $emailVerified,
            'timezone_set' => $activeHousehold !== null && trim($activeHousehold->timezone()) !== '',
            'location_provided' => $activeHousehold !== null
                && $activeHousehold->locationLabel() !== null
                && trim($activeHousehold->locationLabel()) !== '',
            'solar' => $solarStatus,
            'verified_bills' => self::SOURCE_UNAVAILABLE,
            'analysis' => self::ANALYSIS_LIMITED,
        ];
    }
}
