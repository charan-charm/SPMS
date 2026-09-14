<?php

declare(strict_types=1);

namespace App\Domain\Bill;

/**
 * B1 — Field value provenance sources (docs/10 D-067 §9).
 *
 * Where the CURRENT value of a bill field came from:
 * - AI_EXTRACTION: written by the extraction pipeline from provider output.
 * - USER_CORRECTION: written/confirmed by an authenticated household
 *   member during review.
 *
 * This is deliberately coarse (provenance, not an audit log): the
 * immutable extraction draft already preserves exactly what the AI said,
 * so provenance only needs to say whether the live value still matches an
 * extraction or was changed by a human. Raw provider payloads are NOT
 * provenance and are not stored anywhere (§2).
 */
final class ProvenanceSource
{
    public const AI_EXTRACTION = 'AI_EXTRACTION';
    public const USER_CORRECTION = 'USER_CORRECTION';

    public const ALL = [self::AI_EXTRACTION, self::USER_CORRECTION];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
