<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use InvalidArgumentException;

/**
 * D-071 — Service number (USC / Consumer Service number) identity rule.
 *
 * The service number printed on an electricity bill is the anchor the
 * ownership check compares against the household's registered number.
 * Both sides pass through ONE deterministic canonicalization before any
 * comparison, and it is deliberately conservative:
 *
 *   1. trim;
 *   2. ASCII-uppercase;
 *   3. strip DECORATIVE separators: spaces, hyphens, dots, slashes,
 *      underscores and the vertical bar — these vary between how a
 *      utility prints an identifier and how a person types it;
 *   4. require the remaining characters to be alphanumeric (A-Z0-9),
 *      4..24 long — anything else is refused, never "cleaned".
 *
 * Non-aggressive on purpose: leading zeros, character ORDER and length
 * are preserved exactly (no zero-padding, no truncation, no reordering,
 * no removal of letters), so two genuinely different identifiers cannot
 * be flattened into one. The only equivalence it asserts is "separators
 * and case do not distinguish service numbers" — documented in D-071.
 *
 * Matching is canonical-string equality; no fuzzy or partial matching
 * exists anywhere in this class (a prefix "looking similar" is a MISMATCH,
 * which is an honest review outcome, never a fabricated ownership pass).
 */
final class ServiceNumber
{
    private const PATTERN = '/^[A-Z0-9]{4,24}$/';

    /**
     * Canonicalize a raw identifier, or null when nothing remains / the
     * residue is not a plausible service identifier. Callers that need
     * fail-closed registration use assertCanonical().
     */
    public static function canonicalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $stripped = strtoupper(preg_replace('/[ \-\._\/\|]+/', '', trim($raw)) ?? '');

        if ($stripped === '') {
            return null;
        }

        return preg_match(self::PATTERN, $stripped) === 1 ? $stripped : null;
    }

    /** Canonicalization for user-supplied registration: invalid input throws. */
    public static function assertCanonical(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $canonical = self::canonicalize($raw);

        if ($canonical === null) {
            throw new InvalidArgumentException(
                'service_number must be 4-24 alphanumeric characters once spaces and punctuation separate it.'
            );
        }

        return $canonical;
    }

    public static function matches(?string $a, ?string $b): bool
    {
        $left = self::canonicalize($a);
        $right = self::canonicalize($b);

        return $left !== null && $right !== null && $left === $right;
    }

    /**
     * Masked display form for anything that is NOT the authenticated
     * owner's own bill context (logs, cross-household surfaces, generic
     * errors): keep the tail characters, hide the rest (N: identifier
     * minimization).
     */
    public static function mask(?string $canonical): ?string
    {
        if ($canonical === null || $canonical === '') {
            return null;
        }

        $length = strlen($canonical);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - 4) . substr($canonical, -4);
    }
}
