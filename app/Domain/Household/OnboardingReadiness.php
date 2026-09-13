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
 *   it is NOT a queried-and-empty result and NOT a zero. Solar keeps
 *   the "not connected" vs "service unavailable" boundary inside one
 *   honest state because no integration exists to distinguish them
 *   (docs/04 §15 "Empty solar distinguishes not connected from
 *   unavailable"; docs/10 D-042 verified-bill honesty).
 * - No numbers, percentages, scores or predictions are invented
 *   (docs/04 §15/§20 no-fake-values rules).
 */
final class OnboardingReadiness
{
    /** Capability states for sources with no authoritative persistence yet. */
    public const SOURCE_UNAVAILABLE = 'UNAVAILABLE';
    public const ANALYSIS_LIMITED = 'LIMITED';

    /**
     * @return array<string, bool|string>
     */
    public static function derive(bool $emailVerified, ?Household $activeHousehold): array
    {
        return [
            'email_verified' => $emailVerified,
            'timezone_set' => $activeHousehold !== null && trim($activeHousehold->timezone()) !== '',
            'location_provided' => $activeHousehold !== null
                && $activeHousehold->locationLabel() !== null
                && trim($activeHousehold->locationLabel()) !== '',
            'solar' => self::SOURCE_UNAVAILABLE,
            'verified_bills' => self::SOURCE_UNAVAILABLE,
            'analysis' => self::ANALYSIS_LIMITED,
        ];
    }
}
