<?php

declare(strict_types=1);

namespace App\Domain\Bill;

/**
 * B1 — Deterministic validation statuses (docs/10 D-067 §10).
 *
 * PASS: the relationship holds.
 * CONFLICT: it does not — the extracted value is preserved verbatim and
 *   the user resolves it during review; persistence never auto-corrects.
 * NOT_CHECKED: the relationship could not be evaluated (a missing
 *   operand) — neither a pass nor a failure. reason_code explains which.
 */
final class ValidationStatus
{
    public const PASS = 'PASS';
    public const NOT_CHECKED = 'NOT_CHECKED';
    public const CONFLICT = 'CONFLICT';

    public const ALL = [self::PASS, self::NOT_CHECKED, self::CONFLICT];

    /** Reasons a check could not run; whitelisted so free text never persists. */
    public const REASON_MISSING_OPERANDS = 'MISSING_OPERANDS';

    public const REASONS = [self::REASON_MISSING_OPERANDS];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isReasonValid(?string $reason): bool
    {
        return $reason === null || in_array($reason, self::REASONS, true);
    }
}
