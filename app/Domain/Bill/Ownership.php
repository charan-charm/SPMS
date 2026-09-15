<?php

declare(strict_types=1);

namespace App\Domain\Bill;

/**
 * D-071 — the ownership projection the review UI reads.
 *
 * Answers exactly one question — "does this bill demonstrably belong to
 * the household's registered electricity service?" — kept separate from
 * the document assessment ("is this a bill?") and from value-consistency
 * validation ("do the readings agree?"). No merge, no fourth vocabulary:
 * the status is DERIVED from what the extraction persistently recorded
 * (the stored service number plus the controlled ownership quality
 * flags), never recomputed from raw text at read time and never invented
 * when evidence is absent.
 */
final class Ownership
{
    /** The bill's canonical number matches the registered one. */
    public const MATCH = 'MATCH';

    /** The bill shows a different service number than the household's. */
    public const MISMATCH = 'MISMATCH';

    /** The document carried no confirmable service number. */
    public const NOT_ON_BILL = 'NOT_ON_BILL';

    /** The household has not registered a service number yet. */
    public const HOUSEHOLD_NOT_CONFIGURED = 'HOUSEHOLD_NOT_CONFIGURED';

    /**
     * @param array<string, string|null> $values  persisted bill values
     * @param list<BillQualityFlag>      $flags   persisted quality flags
     * @param string|null                $householdServiceNumber the household's canonical registered number (masked here, never raw — N)
     */
    public static function derive(string $status, array $values, array $flags, ?string $householdServiceNumber): ?array
    {
        // Only bills that went through extraction carry an ownership fact.
        if (!in_array($status, [BillStatus::EXTRACTED, BillStatus::NEEDS_REVIEW, BillStatus::VERIFIED], true)) {
            return null;
        }

        $codes = array_map(static fn (BillQualityFlag $f): string => $f->code(), $flags);

        $derived = match (true) {
            in_array(QualityCode::HOUSEHOLD_SERVICE_NOT_CONFIGURED, $codes, true) => self::HOUSEHOLD_NOT_CONFIGURED,
            in_array(QualityCode::SERVICE_NUMBER_MISMATCH, $codes, true) => self::MISMATCH,
            in_array(QualityCode::SERVICE_NUMBER_MISSING, $codes, true) => self::NOT_ON_BILL,
            ($values[BillField::SERVICE_NUMBER] ?? null) !== null => self::MATCH,
            default => self::NOT_ON_BILL,
        };

        return [
            'status' => $derived,
            'bill_service_number' => $values[BillField::SERVICE_NUMBER] ?? null,
            // The registered number is shown masked even to its own
            // household on this view: this panel's job is comparison,
            // not credential display (docs/10 D-071 §N).
            'household_service_number_masked' => ServiceNumber::mask($householdServiceNumber),
        ];
    }
}
