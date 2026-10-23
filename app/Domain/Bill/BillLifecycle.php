<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use InvalidArgumentException;

/**
 * B1 — The bill lifecycle transition table (docs/10 D-067 §18).
 *
 * The single source of truth for "may this status move to that one?".
 * Deliberately small: the happy chain UPLOADED -> PROCESSING ->
 * EXTRACTED -> NEEDS_REVIEW -> VERIFIED, the assessment outcomes that
 * PROCESSING may terminate in, PROCESSING_FAILED from either live state,
 * and nothing else. Terminal states are terminal — no invented
 * reprocessing loop. In particular:
 *
 * - VERIFIED -> anything  is forbidden (an authoritative record cannot
 *   silently return to draft).
 * - The exception states (NOT_A_BILL / UNREADABLE / UNCERTAIN /
 *   PROCESSING_FAILED) can never reach VERIFIED.
 * - UPLOADED / PROCESSING can never jump to VERIFIED — verification acts
 *   on persisted extraction data only.
 */
final class BillLifecycle
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        BillStatus::UPLOADED => [
            BillStatus::PROCESSING,
            BillStatus::PROCESSING_FAILED,
        ],
        BillStatus::PROCESSING => [
            BillStatus::EXTRACTED,
            BillStatus::NOT_A_BILL,
            BillStatus::UNREADABLE,
            BillStatus::UNCERTAIN,
            BillStatus::PROCESSING_FAILED,
        ],
        BillStatus::EXTRACTED => [
            BillStatus::NEEDS_REVIEW,
            BillStatus::VERIFIED,
        ],
        BillStatus::NEEDS_REVIEW => [
            BillStatus::EXTRACTED,
            BillStatus::VERIFIED,
        ],
        BillStatus::VERIFIED => [],
        BillStatus::NOT_A_BILL => [],
        BillStatus::UNREADABLE => [
            BillStatus::NEEDS_REVIEW,
        ],
        BillStatus::UNCERTAIN => [
            BillStatus::NEEDS_REVIEW,
        ],
        BillStatus::PROCESSING_FAILED => [
            BillStatus::PROCESSING,
            BillStatus::NEEDS_REVIEW,
        ],
    ];

    public static function can(string $from, string $to): bool
    {
        if (!BillStatus::isValid($from) || !BillStatus::isValid($to)) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * @return list<string>
     */
    public static function allowedFrom(string $from): array
    {
        if (!BillStatus::isValid($from)) {
            throw new InvalidArgumentException('unknown bill status: ' . $from);
        }

        return self::TRANSITIONS[$from];
    }

    public static function assert(string $from, string $to): void
    {
        if (!self::can($from, $to)) {
            throw new InvalidArgumentException(
                'a bill cannot move from ' . $from . ' to ' . $to . '.'
            );
        }
    }
}
