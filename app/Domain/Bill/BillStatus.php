<?php

declare(strict_types=1);

namespace App\Domain\Bill;

/**
 * B1 — Bill lifecycle states (docs/10 D-067 §3).
 *
 * The vocabulary is explicit — there is deliberately no vague "DONE":
 * EXTRACTED (the AI finished its job) and VERIFIED (a human accepted the
 * data) are different states, and only an explicit verification
 * operation can produce the latter. The five terminal states end the
 * bill's lifecycle; B1 defines no reprocessing path, so terminal states
 * have no outgoing transitions.
 *
 * Storage follows the existing status convention (VARCHAR validated by
 * the application — accounts, households, insights all do this; the
 * schema never needs to know the vocabulary).
 */
final class BillStatus
{
    /** Row exists; document processing has not started. */
    public const UPLOADED = 'UPLOADED';

    /** Extraction is in flight (B2 will drive this stretch). */
    public const PROCESSING = 'PROCESSING';

    /** AI extraction completed and persisted; nothing needs review. */
    public const EXTRACTED = 'EXTRACTED';

    /** AI extraction completed but review is required (conflict/limitation). */
    public const NEEDS_REVIEW = 'NEEDS_REVIEW';

    /** Explicitly user-verified; the ONLY authoritative state. */
    public const VERIFIED = 'VERIFIED';

    /** The submitted document was not an electricity bill. */
    public const NOT_A_BILL = 'NOT_A_BILL';

    /** The document could not be read at all. */
    public const UNREADABLE = 'UNREADABLE';

    /** Extraction finished but the result cannot be trusted enough to use. */
    public const UNCERTAIN = 'UNCERTAIN';

    /** Processing itself failed (provider/transport/unknown). */
    public const PROCESSING_FAILED = 'PROCESSING_FAILED';

    public const ALL = [
        self::UPLOADED,
        self::PROCESSING,
        self::EXTRACTED,
        self::NEEDS_REVIEW,
        self::VERIFIED,
        self::NOT_A_BILL,
        self::UNREADABLE,
        self::UNCERTAIN,
        self::PROCESSING_FAILED,
    ];

    public const TERMINAL = [
        self::VERIFIED,
        self::NOT_A_BILL,
        self::UNREADABLE,
        self::UNCERTAIN,
        self::PROCESSING_FAILED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** States whose data may legitimately feed household analysis. */
    public static function isAuthoritative(string $status): bool
    {
        return $status === self::VERIFIED;
    }
}
