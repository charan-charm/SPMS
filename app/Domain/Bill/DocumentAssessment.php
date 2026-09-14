<?php

declare(strict_types=1);

namespace App\Domain\Bill;

/**
 * B1 — Document assessment vocabulary (docs/10 D-067 §11).
 *
 * What the extraction provider judged about the DOCUMENT as a whole.
 * Kept structurally separate from deterministic numeric validation:
 * document = VALID alongside net-units = CONFLICT is a legitimate,
 * meaningful combination (§11) — a conflict on one relationship never
 * invalidates the whole bill, and a clean document is never automatically
 * verified.
 *
 * Mirrors the values produced by the (already ratified) bill annotation
 * schema; B1 persists them, it does not produce them.
 */
final class DocumentAssessment
{
    public const VALID = 'VALID';
    public const VALID_WITH_LIMITATIONS = 'VALID_WITH_LIMITATIONS';
    public const NOT_A_BILL = 'NOT_A_BILL';
    public const UNREADABLE = 'UNREADABLE';
    public const UNCERTAIN = 'UNCERTAIN';

    public const ALL = [
        self::VALID,
        self::VALID_WITH_LIMITATIONS,
        self::NOT_A_BILL,
        self::UNREADABLE,
        self::UNCERTAIN,
    ];

    public static function isValid(string $assessment): bool
    {
        return in_array($assessment, self::ALL, true);
    }

    /** Assessments whose bill may carry usable analytical data. */
    public static function isUsable(string $assessment): bool
    {
        return in_array($assessment, [self::VALID, self::VALID_WITH_LIMITATIONS], true);
    }
}
