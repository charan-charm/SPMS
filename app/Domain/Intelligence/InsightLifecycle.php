<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Insight lifecycle states (docs/10 D-046).
 *
 * Lifecycle is engine/repository-controlled: API consumers can filter by
 * state but never assign one. ONGOING is accepted as an input/filter alias
 * and normalized to UPDATED so no duplicate state name can ever persist.
 * RESOLVED, EXPIRED and HISTORICAL are reserved states: filterable in v1,
 * but only assigned by future evaluation paths (no background job exists).
 */
final class InsightLifecycle
{
    public const DETECTED = 'DETECTED';
    public const VALIDATED = 'VALIDATED';
    public const ACTIVE = 'ACTIVE';
    public const UPDATED = 'UPDATED';
    public const RESOLVED = 'RESOLVED';
    public const EXPIRED = 'EXPIRED';
    public const HISTORICAL = 'HISTORICAL';

    /** Input/filter alias for UPDATED — never persisted verbatim. */
    public const ONGOING_ALIAS = 'ONGOING';

    public const STATES = [
        self::DETECTED,
        self::VALIDATED,
        self::ACTIVE,
        self::UPDATED,
        self::RESOLVED,
        self::EXPIRED,
        self::HISTORICAL,
    ];

    public const FILTERABLE_STATES = [
        self::DETECTED,
        self::VALIDATED,
        self::ACTIVE,
        self::UPDATED,
        self::ONGOING_ALIAS,
        self::RESOLVED,
        self::EXPIRED,
        self::HISTORICAL,
    ];

    public static function isValidState(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }

    /**
     * Normalize an API-provided lifecycle value to its canonical state
     * (ONGOING -> UPDATED). Returns null for values outside the filterable
     * set, including reserved-but-unassignable states is not possible here:
     * every filterable value maps onto a persisted state.
     */
    public static function normalize(string $state): ?string
    {
        if ($state === self::ONGOING_ALIAS) {
            return self::UPDATED;
        }

        return self::isValidState($state) ? $state : null;
    }

    /**
     * Deterministic lifecycle ladder for a repeated observation with an
     * unchanged evidence fingerprint: DETECTED -> VALIDATED -> ACTIVE.
     * A changed fingerprint moves an already-validated insight to UPDATED.
     */
    public static function nextOnObservation(string $current, bool $evidenceChanged): string
    {
        if ($evidenceChanged) {
            return $current === self::DETECTED ? self::DETECTED : self::UPDATED;
        }

        return match ($current) {
            self::DETECTED => self::VALIDATED,
            self::VALIDATED => self::ACTIVE,
            default => $current,
        };
    }
}
