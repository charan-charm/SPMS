<?php

declare(strict_types=1);

namespace App\Domain\Bill\Sequence;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use DateTimeImmutable;

/**
 * Backend-authoritative chronological billing period sequence validator.
 *
 * Compares proposed billing period against existing verified bills:
 * - CONTIGUOUS: Normal continuation or acceptable irregular shift (<= 5 days)
 * - FIRST_BILL: First bill for household establishing baseline
 * - GAP_DETECTED: Missing one or more billing periods
 * - DUPLICATE_PERIOD: Exact same billing period already exists as verified
 * - OVERLAPPING_PERIOD: Partial overlap with an already verified bill
 * - INVALID_REVERSED: Proposed start date >= proposed end date
 * - MISSING_PERIOD_DATES: Proposed start or end date is null
 */
final class BillingPeriodSequenceValidator
{
    public const FIRST_BILL = 'FIRST_BILL';
    public const CONTIGUOUS = 'CONTIGUOUS';
    public const GAP_DETECTED = 'GAP_DETECTED';
    public const DUPLICATE_PERIOD = 'DUPLICATE_PERIOD';
    public const OVERLAPPING_PERIOD = 'OVERLAPPING_PERIOD';
    public const INVALID_REVERSED = 'INVALID_REVERSED';
    public const MISSING_PERIOD_DATES = 'MISSING_PERIOD_DATES';

    /**
     * @param list<Bill> $verifiedBills
     */
    public function validate(
        ?string $proposedStart,
        ?string $proposedEnd,
        array $verifiedBills
    ): BillingSequenceResult {
        if ($proposedStart === null || $proposedEnd === null) {
            return new BillingSequenceResult(
                status: self::MISSING_PERIOD_DATES,
                canVerify: false,
                requiresAcknowledgment: false,
                missingPeriod: null,
                message: 'Billing period start and end dates are required for verification.'
            );
        }

        if ($proposedStart >= $proposedEnd) {
            return new BillingSequenceResult(
                status: self::INVALID_REVERSED,
                canVerify: false,
                requiresAcknowledgment: false,
                missingPeriod: null,
                message: 'Billing period start date must be before end date.'
            );
        }

        // Filter valid bills with periods, deduplicating identical periods
        $existing = [];
        $seen = [];
        foreach ($verifiedBills as $b) {
            $s = $b->value(BillField::BILLING_PERIOD_START);
            $e = $b->value(BillField::BILLING_PERIOD_END);
            if ($s !== null && $e !== null && $s < $e) {
                $key = "{$s}_{$e}";
                if (isset($seen[$key])) {
                    // Keep verified record if one of the duplicates is verified
                    if ($b->status() === \App\Domain\Bill\BillStatus::VERIFIED) {
                        $existing[$seen[$key]] = ['start' => $s, 'end' => $e, 'bill' => $b];
                    }
                    continue;
                }
                $seen[$key] = count($existing);
                $existing[] = ['start' => $s, 'end' => $e, 'bill' => $b];
            }
        }

        if ($existing === []) {
            return new BillingSequenceResult(
                status: self::FIRST_BILL,
                canVerify: true,
                requiresAcknowledgment: false,
                missingPeriod: null,
                message: 'First bill for this household establishing verified baseline.',
                latestVerifiedPeriod: null
            );
        }

        // Sort chronologically by start date ascending
        usort(
            $existing,
            static fn (array $a, array $b): int => strcmp($a['start'], $b['start'])
        );

        // 1. Check for Duplicate and Overlap against all verified periods
        foreach ($existing as $item) {
            $exStart = $item['start'];
            $exEnd = $item['end'];

            // Duplicate: exact match
            if ($proposedStart === $exStart && $proposedEnd === $exEnd) {
                return new BillingSequenceResult(
                    status: self::DUPLICATE_PERIOD,
                    canVerify: false,
                    requiresAcknowledgment: false,
                    missingPeriod: null,
                    message: "A verified bill for period {$exStart} to {$exEnd} already exists."
                );
            }

            // Overlap: proposed intersects existing (excluding contiguous boundary touching)
            if ($proposedStart < $exEnd && $proposedEnd > $exStart) {
                return new BillingSequenceResult(
                    status: self::OVERLAPPING_PERIOD,
                    canVerify: false,
                    requiresAcknowledgment: false,
                    missingPeriod: null,
                    message: "Billing period {$proposedStart} to {$proposedEnd} overlaps with verified bill period {$exStart} to {$exEnd}."
                );
            }
        }

        // 2. Chronological sequence check
        // Find latest verified bill
        $latest = null;
        foreach ($existing as $item) {
            if ($latest === null || $item['end'] > $latest['end']) {
                $latest = $item;
            }
        }

        $latestPeriod = ['start' => $latest['start'], 'end' => $latest['end']];

        // Case A: Proposed bill is forward in time from latest verified
        if ($proposedStart >= $latest['end']) {
            $daysDiff = self::daysBetween($latest['end'], $proposedStart);

            // Contiguous boundary or reading cycle tolerance (<= 5 days)
            if ($daysDiff <= 5) {
                return new BillingSequenceResult(
                    status: self::CONTIGUOUS,
                    canVerify: true,
                    requiresAcknowledgment: false,
                    missingPeriod: null,
                    message: 'Billing period contiguous with previous verified bill.',
                    latestVerifiedPeriod: $latestPeriod
                );
            }

            // Forward gap detected
            $missingLabel = self::formatPeriodLabel($latest['end'], $proposedStart);
            return new BillingSequenceResult(
                status: self::GAP_DETECTED,
                canVerify: false,
                requiresAcknowledgment: true,
                missingPeriod: [
                    'start' => $latest['end'],
                    'end' => $proposedStart,
                    'label' => $missingLabel,
                ],
                message: "Your {$missingLabel} billing period is missing. Please upload the previous bill before adding this bill.",
                latestVerifiedPeriod: $latestPeriod
            );
        }

        // Case B: Proposed bill is backward in time (backfilling before earliest)
        $earliest = $existing[0];
        if ($proposedEnd <= $earliest['start']) {
            $daysDiff = self::daysBetween($proposedEnd, $earliest['start']);

            if ($daysDiff <= 5) {
                return new BillingSequenceResult(
                    status: self::CONTIGUOUS,
                    canVerify: true,
                    requiresAcknowledgment: false,
                    missingPeriod: null,
                    message: 'Historical billing period contiguous with earliest verified bill.',
                    latestVerifiedPeriod: $latestPeriod
                );
            }

            $missingLabel = self::formatPeriodLabel($proposedEnd, $earliest['start']);
            return new BillingSequenceResult(
                status: self::GAP_DETECTED,
                canVerify: false,
                requiresAcknowledgment: true,
                missingPeriod: [
                    'start' => $proposedEnd,
                    'end' => $earliest['start'],
                    'label' => $missingLabel,
                ],
                message: "Your {$missingLabel} historical billing period is missing.",
                latestVerifiedPeriod: $latestPeriod
            );
        }

        // Case C: Proposed bill fits in an internal gap between existing bills
        for ($i = 0; $i < count($existing) - 1; $i++) {
            $currentEnd = $existing[$i]['end'];
            $nextStart = $existing[$i + 1]['start'];

            if ($proposedStart >= $currentEnd && $proposedEnd <= $nextStart) {
                $gapPre = self::daysBetween($currentEnd, $proposedStart);
                $gapPost = self::daysBetween($proposedEnd, $nextStart);

                if ($gapPre <= 5 && $gapPost <= 5) {
                    return new BillingSequenceResult(
                        status: self::CONTIGUOUS,
                        canVerify: true,
                        requiresAcknowledgment: false,
                        missingPeriod: null,
                        message: 'Billing period contiguous, successfully fills historical gap.',
                        latestVerifiedPeriod: $latestPeriod
                    );
                }

                $missingLabel = $gapPre > 5
                    ? self::formatPeriodLabel($currentEnd, $proposedStart)
                    : self::formatPeriodLabel($proposedEnd, $nextStart);

                return new BillingSequenceResult(
                    status: self::GAP_DETECTED,
                    canVerify: false,
                    requiresAcknowledgment: true,
                    missingPeriod: [
                        'start' => $gapPre > 5 ? $currentEnd : $proposedEnd,
                        'end' => $gapPre > 5 ? $proposedStart : $nextStart,
                        'label' => $missingLabel,
                    ],
                    message: "A portion of the historical billing period ({$missingLabel}) remains missing.",
                    latestVerifiedPeriod: $latestPeriod
                );
            }
        }

        // Default contiguous
        return new BillingSequenceResult(
            status: self::CONTIGUOUS,
            canVerify: true,
            requiresAcknowledgment: false,
            missingPeriod: null,
            message: 'Billing period sequence verified.',
            latestVerifiedPeriod: $latestPeriod
        );
    }

    private static function daysBetween(string $start, string $end): int
    {
        $d1 = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $d2 = DateTimeImmutable::createFromFormat('!Y-m-d', $end);

        if ($d1 === false || $d2 === false) {
            return 0;
        }

        return abs($d1->diff($d2)->days);
    }

    private static function formatPeriodLabel(string $start, string $end): string
    {
        $d1 = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $d2 = DateTimeImmutable::createFromFormat('!Y-m-d', $end);

        if ($d1 === false || $d2 === false) {
            return "{$start} to {$end}";
        }

        // If approximately 1 month (e.g. 2026-09-01 to 2026-10-01)
        if ($d1->format('Y-m') === $d2->modify('-1 month')->format('Y-m') || $d1->diff($d2)->days <= 32) {
            return $d1->format('F Y');
        }

        return $d1->format('M Y') . ' – ' . $d2->format('M Y');
    }
}
