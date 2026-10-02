<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use App\Domain\Account\Account;
use App\Domain\Household\Household;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Domain\Bill\Sequence\BillingPeriodSequenceValidator;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationService;
use App\Support\AppException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * B1 — Bill lifecycle and persistence use-cases (docs/10 D-067).
 *
 * Ownership first, always: every operation starts from the authenticated
 * Account and resolves the household through the W3 membership rule
 * (HouseholdService) — a household id is never accepted from a caller,
 * and a bill id can only ever resolve inside the caller's own derived
 * household (the repository cannot read cross-household rows; a foreign
 * id is the same honest 404 as an unknown one, D-064 §3).
 *
 * The two boundaries B1 exists to make impossible are structural here:
 *
 * 1. AI-completed != user-accepted. recordExtraction() can produce at
 *    most EXTRACTED or NEEDS_REVIEW — never VERIFIED, no matter how
 *    confident or "VALID" the extraction is (§3/§17). VERIFIED comes
 *    only from verify(), an explicit operation, which is also the only
 *    writer of verified_at.
 * 2. Draft is never erased. Corrections go to the bills row plus field
 *    provenance (USER_CORRECTION); the extraction draft is never updated
 *    by any method on this service (§7/§12).
 *
 * This is the persistence foundation only: there is no controller, no
 * HTTP route and no Mistral call wired to these methods yet — B2 (upload
 * handling) and the review flow will be the first real callers.
 */
final class BillService
{
    public function __construct(
        private readonly BillRepository $bills = new BillRepository(),
        private readonly HouseholdService $households = new HouseholdService(),
        private readonly BillingPeriodSequenceValidator $sequenceValidator = new BillingPeriodSequenceValidator(),
        private readonly NotificationService $notifications = new NotificationService()
    ) {
    }

    /**
     * Register a new bill for the caller's household. The bill starts
     * EMPTY and UPLOADED: only period context may be known at this point
     * (optionally), and every analytical value stays NULL — nothing is
     * invented ahead of an extraction (§15).
     */
    public function create(
        Account $account,
        ?string $billingPeriodStart,
        ?string $billingPeriodEnd,
        DateTimeImmutable $now
    ): Bill {
        $householdId = $this->requireHousehold($account);

        if ($billingPeriodStart !== null && $billingPeriodEnd !== null) {
            $existing = $this->bills->findActiveByPeriod($householdId, $billingPeriodStart, $billingPeriodEnd);
            if ($existing !== null) {
                $monthLabel = date('F Y', strtotime($billingPeriodEnd));
                throw new AppException(
                    'DUPLICATE_BILL_PERIOD',
                    "A bill for {$billingPeriodStart} to {$billingPeriodEnd} is already recorded for this household.",
                    409,
                    [
                        'duplicate_type' => 'PERIOD',
                        'existing_bill_id' => $existing->id(),
                        'billing_period_start' => $billingPeriodStart,
                        'billing_period_end' => $billingPeriodEnd,
                        'month_label' => $monthLabel,
                        'status' => $existing->status(),
                    ]
                );
            }
        }

        try {
            $bill = Bill::create($householdId, $billingPeriodStart, $billingPeriodEnd, $now);
        } catch (InvalidArgumentException $e) {
            throw self::validationError($e);
        }

        $this->bills->insert($bill);

        return $bill;
    }

    public function get(Account $account, string $billId): ?Bill
    {
        return $this->bills->find($billId, $this->requireHousehold($account));
    }

    /**
     * @return list<Bill>
     */
    public function listForAccount(Account $account): array
    {
        return $this->bills->listForHousehold($this->requireHousehold($account));
    }

    public function markProcessing(Account $account, string $billId, DateTimeImmutable $now): Bill
    {
        return $this->transition($account, $billId, BillStatus::PROCESSING, $now);
    }

    public function markProcessingFailed(Account $account, string $billId, DateTimeImmutable $now): Bill
    {
        $householdId = $this->requireHousehold($account);
        $bill = $this->transition($account, $billId, BillStatus::PROCESSING_FAILED, $now);

        try {
            $this->notifications->notify(
                householdId: $householdId,
                type: Notification::TYPE_BILL,
                severity: Notification::SEVERITY_WARNING,
                title: 'Bill Processing Failed',
                message: 'An error occurred while processing your utility bill statement. Please review or re-upload the document.',
                relatedResourceType: 'BILL',
                relatedResourceId: $billId,
                eventKey: "bill_failed_{$billId}"
            );
        } catch (\Throwable) {
        }

        return $bill;
    }

    /**
     * Persist one completed extraction (§3–§10, §17; D-071):
     *
     * - requires a PROCESSING bill (lifecycle-guarded, else 409);
     * - stores the immutable draft + validation results + quality flags
     *   atomically with the bill update;
     * - a USABLE assessment (VALID / VALID_WITH_LIMITATIONS) must still
     *   carry actual bill EVIDENCE — a document that produced zero
     *   period/energy/money values is not turned into a bill-shaped
     *   record no matter what the provider labeled it (logo gating,
     *   D-071; the draft keeps the provider's own assessment honestly);
     * - for a usable assessment the normalized values become the bill's
     *   current values with AI_EXTRACTION provenance — including honest
     *   NULLs for whatever the provider could not extract (no
     *   zero-filling), with the service number stored in its canonical
     *   form;
     * - ownership is then checked deterministically against the
     *   household's REGISTERED service number (D-071): a mismatch, a
     *   missing number on the bill, or a household that never registered
     *   one all force NEEDS_REVIEW with the matching quality flag — an
     *   ownership "pass" exists only with actual matching evidence, and
     *   nothing here ever verifies;
     * - for NOT_A_BILL / UNREADABLE / UNCERTAIN the bill terminates in
     *   that state and its value columns stay NULL: a rejected document
     *   leaves no "data" behind, only the assessment record itself;
     * - lands in NEEDS_REVIEW (never EXTRACTED) whenever limitations or
     *   any CONFLICT validation exist — review is an obligation, not an
     *   afterthought;
     * - NEVER verifies, whatever the confidence number says.
     *
     * @param list<BillValidation>  $validations
     * @param list<BillQualityFlag> $flags
     */
    public function recordExtraction(
        Account $account,
        string $billId,
        BillExtractionDraft $draft,
        array $validations = [],
        array $flags = [],
        ?DateTimeImmutable $now = null
    ): Bill {
        $now ??= new DateTimeImmutable('now');
        $household = $this->requireHouseholdContext($account);
        $householdId = $household->id();
        $bill = $this->loadScoped($billId, $householdId);

        if ($draft->billId() !== $bill->id()) {
            throw new AppException('VALIDATION_ERROR', 'The draft belongs to a different bill.', 422);
        }

        foreach ($validations as $validation) {
            if (!$validation instanceof BillValidation) {
                throw new AppException('VALIDATION_ERROR', 'Validation entries must be BillValidation objects.', 422);
            }
        }

        foreach ($flags as $flag) {
            if (!$flag instanceof BillQualityFlag) {
                throw new AppException('VALIDATION_ERROR', 'Quality entries must be BillQualityFlag objects.', 422);
            }
        }

        $assessment = $draft->assessment();

        if (DocumentAssessment::isUsable($assessment) && !self::hasBillEvidence($draft->values())) {
            // Logo/unrelated-image gating (D-071): the provider called this
            // document usable, but not ONE billing period, energy or money
            // value is backed by the extraction. Normalization must not
            // manufacture a bill-shaped record from that — the bill
            // terminates honestly as UNCERTAIN (the draft row still
            // preserves the provider's own assessment for audit).
            $assessment = DocumentAssessment::UNCERTAIN;
            $flags[] = BillQualityFlag::create(QualityCode::NO_BILL_EVIDENCE, null);
        }

        if (!DocumentAssessment::isUsable($assessment)) {
            $terminal = match ($assessment) {
                DocumentAssessment::NOT_A_BILL => BillStatus::NOT_A_BILL,
                DocumentAssessment::UNREADABLE => BillStatus::UNREADABLE,
                DocumentAssessment::UNCERTAIN => BillStatus::UNCERTAIN,
            };

            try {
                $updated = $bill->transitionTo($terminal, $now);
            } catch (InvalidArgumentException $e) {
                throw self::stateError($e);
            }

            $provenance = [];
        } else {
            $conflicted = false;
            foreach ($validations as $validation) {
                $conflicted = $conflicted || $validation->status() === ValidationStatus::CONFLICT;
            }

            // D-071 — deterministic service-number ownership validation.
            // The draft keeps the raw extracted string (immutable AI
            // snapshot); the bill row keeps the canonical form used for
            // matching. Mismatch/missing outcomes NEVER alter any value —
            // they only add quality flags, and any flag routes the bill
            // to NEEDS_REVIEW through the existing rule below.
            $values = $draft->values();
            $billServiceNumber = ServiceNumber::canonicalize($values[BillField::SERVICE_NUMBER] ?? null);
            $values[BillField::SERVICE_NUMBER] = $billServiceNumber;

            $flags = array_merge($flags, self::ownershipFlags(
                $household->serviceNumber(),
                $billServiceNumber
            ));

            $needsReview = $assessment === DocumentAssessment::VALID_WITH_LIMITATIONS
                || $conflicted
                || $flags !== [];

            $start = $values[BillField::BILLING_PERIOD_START] ?? null;
            $end = $values[BillField::BILLING_PERIOD_END] ?? null;
            if ($start !== null && $end !== null) {
                $existing = $this->bills->findActiveByPeriod($householdId, $start, $end, excludeBillId: $bill->id());
                if ($existing !== null) {
                    $monthLabel = date('F Y', strtotime($end));
                    throw new AppException(
                        'DUPLICATE_BILL_PERIOD',
                        "A bill for {$start} to {$end} is already recorded for this household.",
                        409,
                        [
                            'duplicate_type' => 'PERIOD',
                            'existing_bill_id' => $existing->id(),
                            'billing_period_start' => $start,
                            'billing_period_end' => $end,
                            'month_label' => $monthLabel,
                            'status' => $existing->status(),
                        ]
                    );
                }
            }

            try {
                $updated = $bill
                    ->withValues($values, $now)
                    // two ratified lifecycle hops, each validated:
                    ->transitionTo(BillStatus::EXTRACTED, $now);

                if ($needsReview) {
                    $updated = $updated->transitionTo(BillStatus::NEEDS_REVIEW, $now);
                }
            } catch (InvalidArgumentException $e) {
                throw str_starts_with($e->getMessage(), 'a bill cannot move')
                    ? self::stateError($e)
                    : self::validationError($e);
            }

            $isStructured = ($values[BillField::SOURCE] ?? null) === BillSource::APSPDCL_EXPORT
                || $draft->provider() === 'apspdcl_export';
            $provSource = $isStructured ? ProvenanceSource::STRUCTURED_IMPORT : ProvenanceSource::AI_EXTRACTION;

            $provenance = [];
            foreach ($values as $field => $value) {
                if ($value === null) {
                    continue; // no provenance row for data that does not exist
                }
                $provenance[$field] = [
                    'source' => $provSource,
                    'confidence' => $draft->confidences()[$field] ?? null,
                ];
            }
        }

        $this->bills->recordExtraction($updated, $draft, $validations, $flags, $provenance);

        return $updated;
    }

    /**
     * D-071 — the caller's household's canonical registered service
     * number (null when not configured). Read-only and self-scoped:
     * derived from membership like every household fact, never accepted
     * from a caller.
     */
    public function householdServiceNumber(Account $account): ?string
    {
        return $this->requireHouseholdContext($account)->serviceNumber();
    }

    /**
     * D-071 / Correction reconciliation:
     * Re-evaluate ownership flags for a specific bill against the caller household's
     * currently registered service number.
     *
     * Only unverified bills (EXTRACTED, NEEDS_REVIEW) are subject to reconciliation.
     * Preserves original extraction draft, provenance, and values.
     * Does NOT jump the bill to VERIFIED — verification remains an explicit user action.
     */
    public function reconcileOwnership(Account $account, string $billId, ?DateTimeImmutable $now = null): Bill
    {
        $household = $this->requireHouseholdContext($account);
        $bill = $this->loadScoped($billId, $household->id());

        if ($bill->status() === BillStatus::VERIFIED) {
            return $bill;
        }

        $now = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $billServiceNumber = $bill->value(BillField::SERVICE_NUMBER);
        $newFlags = self::ownershipFlags($household->serviceNumber(), $billServiceNumber);

        $this->bills->syncOwnershipQualityFlags($bill->id(), $household->id(), $newFlags, $timestamp);

        return $bill;
    }

    /**
     * Re-evaluate ownership for all unverified bills of a household.
     * Called when the household service number is corrected/updated.
     */
    public function reconcileHouseholdBillsOwnership(Household $household, ?DateTimeImmutable $now = null): void
    {
        $bills = $this->bills->listForHousehold($household->id());
        $now = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        foreach ($bills as $bill) {
            if ($bill->status() === BillStatus::VERIFIED) {
                continue;
            }

            $billServiceNumber = $bill->value(BillField::SERVICE_NUMBER);
            $newFlags = self::ownershipFlags($household->serviceNumber(), $billServiceNumber);

            $this->bills->syncOwnershipQualityFlags($bill->id(), $household->id(), $newFlags, $timestamp);
        }
    }

    /**
     * User corrections (§7/§9): replace CURRENT bill values and flip
     * their provenance to USER_CORRECTION. Only the two reviewable,
     * non-authoritative states accept corrections — a verified bill is
     * frozen (changing authoritative data is a future, explicitly
     * designed operation, not a side effect of this one), and an
     * un-extracted bill has nothing to correct.
     *
     * @param array<string, string|null> $values field => corrected value (null clears honestly)
     */
    public function applyCorrections(Account $account, string $billId, array $values, DateTimeImmutable $now): Bill
    {
        $householdId = $this->requireHousehold($account);
        $bill = $this->loadScoped($billId, $householdId);

        if (!in_array($bill->status(), [BillStatus::EXTRACTED, BillStatus::NEEDS_REVIEW], true)) {
            throw new AppException(
                'INVALID_BILL_STATE',
                'Corrections are only possible on a bill awaiting review (EXTRACTED or NEEDS_REVIEW).',
                409
            );
        }

        if ($values === []) {
            throw new AppException('VALIDATION_ERROR', 'At least one corrected field is required.', 422);
        }

        $newStart = array_key_exists(BillField::BILLING_PERIOD_START, $values)
            ? $values[BillField::BILLING_PERIOD_START]
            : $bill->value(BillField::BILLING_PERIOD_START);
        $newEnd = array_key_exists(BillField::BILLING_PERIOD_END, $values)
            ? $values[BillField::BILLING_PERIOD_END]
            : $bill->value(BillField::BILLING_PERIOD_END);

        if ($newStart !== null && $newEnd !== null) {
            $existing = $this->bills->findActiveByPeriod($householdId, $newStart, $newEnd, excludeBillId: $bill->id());
            if ($existing !== null) {
                throw new AppException(
                    'DUPLICATE_BILL_PERIOD',
                    "A bill for {$newStart} to {$newEnd} is already recorded for this household.",
                    409
                );
            }
        }

        try {
            $updated = $bill->withValues($values, $now);
        } catch (InvalidArgumentException $e) {
            throw self::validationError($e);
        }

        $provenance = [];
        foreach ($values as $field => $value) {
            $provenance[$field] = ['source' => ProvenanceSource::USER_CORRECTION, 'confidence' => null];
        }

        $this->bills->saveCorrections($updated, $provenance);

        return $updated;
    }

    /**
     * The ONLY path to authoritative data (§17): explicit, scoped,
     * lifecycle-guarded; stamps verified_at; touches neither the draft
     * nor the provenance rows it accepts.
     *
     * Enforces:
     * - Ownership: bills with SERVICE_NUMBER_MISMATCH cannot verify;
     * - Chronological sequence: contiguous periods pass; duplicates and
     *   overlaps are rejected (409); gaps require explicit acknowledgment
     *   ($acceptGap = true) to prevent trapped users, flagging INCOMPLETE_HISTORY.
     */
    public function verify(Account $account, string $billId, DateTimeImmutable $now, bool $acceptGap = false): Bill
    {
        $householdId = $this->requireHousehold($account);
        $bill = $this->loadScoped($billId, $householdId);

        try {
            $updated = $bill->verify($now);
        } catch (InvalidArgumentException $e) {
            throw self::stateError($e);
        }

        // 1. Ownership mismatch check: cannot verify foreign/mismatched bills
        $flags = $this->bills->findQualityFlags($billId, $householdId);
        foreach ($flags as $f) {
            if ($f->code() === QualityCode::SERVICE_NUMBER_MISMATCH) {
                throw new AppException(
                    'OWNERSHIP_MISMATCH',
                    'Cannot verify a bill whose service number does not match your registered household service number.',
                    422
                );
            }
        }

        // 2. Sequence validation against canonical statement sequence
        $sequenceBills = array_values(array_filter(
            $this->bills->listForHousehold($householdId),
            static fn (Bill $b): bool => $b->id() !== $bill->id()
                && $b->value(BillField::BILLING_PERIOD_START) !== null
                && $b->value(BillField::BILLING_PERIOD_END) !== null
                && in_array($b->status(), [BillStatus::VERIFIED, BillStatus::EXTRACTED, BillStatus::NEEDS_REVIEW], true)
        ));

        $seqResult = $this->sequenceValidator->validate(
            $bill->value(BillField::BILLING_PERIOD_START),
            $bill->value(BillField::BILLING_PERIOD_END),
            $sequenceBills
        );

        if ($seqResult->status() === BillingPeriodSequenceValidator::DUPLICATE_PERIOD) {
            throw new AppException('DUPLICATE_BILL_PERIOD', $seqResult->message(), 409);
        }

        if ($seqResult->status() === BillingPeriodSequenceValidator::OVERLAPPING_PERIOD) {
            throw new AppException('OVERLAPPING_BILL_PERIOD', $seqResult->message(), 409);
        }

        if ($seqResult->status() === BillingPeriodSequenceValidator::INVALID_REVERSED || $seqResult->status() === BillingPeriodSequenceValidator::MISSING_PERIOD_DATES) {
            throw new AppException('INVALID_BILL_PERIOD', $seqResult->message(), 422);
        }

        if ($seqResult->status() === BillingPeriodSequenceValidator::GAP_DETECTED) {
            if (!$acceptGap) {
                throw new AppException(
                    'BILLING_GAP_DETECTED',
                    $seqResult->message(),
                    422,
                    [
                        'missing_period' => $seqResult->missingPeriod(),
                        'requires_acknowledgment' => true,
                    ]
                );
            }

            // Controlled exception: gap accepted by user. Flag history as incomplete.
            $stamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->bills->recordQualityFlag($bill->id(), BillQualityFlag::create(QualityCode::INCOMPLETE_HISTORY, null), $stamp);
            $this->bills->recordQualityFlag($bill->id(), BillQualityFlag::create(QualityCode::BILLING_GAP_PRECEDING, BillField::BILLING_PERIOD_START), $stamp);
        }

        if (!$this->bills->save($updated)) {
            throw new AppException('NOT_FOUND', 'The requested bill was not found.', 404);
        }

        try {
            $start = $updated->value(BillField::BILLING_PERIOD_START) ?? 'Unknown';
            $end = $updated->value(BillField::BILLING_PERIOD_END) ?? 'Unknown';
            $this->notifications->notify(
                householdId: $householdId,
                type: Notification::TYPE_BILL,
                severity: Notification::SEVERITY_SUCCESS,
                title: 'Utility Bill Statement Verified',
                message: "APSPDCL electricity statement for period {$start} to {$end} was verified.",
                relatedResourceType: 'BILL',
                relatedResourceId: $updated->id(),
                eventKey: "bill_verified_{$updated->id()}"
            );
        } catch (\Throwable) {
        }

        return $updated;
    }

    public function sequenceValidationFor(Account $account, string $billId): \App\Domain\Bill\Sequence\BillingSequenceResult
    {
        $householdId = $this->requireHousehold($account);
        $bill = $this->loadScoped($billId, $householdId);

        $sequenceBills = array_values(array_filter(
            $this->bills->listForHousehold($householdId),
            static fn (Bill $b): bool => $b->id() !== $bill->id()
                && $b->value(BillField::BILLING_PERIOD_START) !== null
                && $b->value(BillField::BILLING_PERIOD_END) !== null
                && in_array($b->status(), [BillStatus::VERIFIED, BillStatus::EXTRACTED, BillStatus::NEEDS_REVIEW], true)
        ));

        return $this->sequenceValidator->validate(
            $bill->value(BillField::BILLING_PERIOD_START),
            $bill->value(BillField::BILLING_PERIOD_END),
            $sequenceBills
        );
    }

    public function draftFor(Account $account, string $billId): ?BillExtractionDraft
    {
        return $this->bills->findDraft($billId, $this->requireHousehold($account));
    }

    /**
     * @return list<BillValidation>
     */
    public function validationsFor(Account $account, string $billId): array
    {
        return $this->bills->findValidations($billId, $this->requireHousehold($account));
    }

    /**
     * @return list<BillQualityFlag>
     */
    public function qualityFlagsFor(Account $account, string $billId): array
    {
        return $this->bills->findQualityFlags($billId, $this->requireHousehold($account));
    }

    /**
     * @return array<string, array{source: string, confidence: string|null}>
     */
    public function provenanceFor(Account $account, string $billId): array
    {
        return $this->bills->findProvenance($billId, $this->requireHousehold($account));
    }

    // ------------------------------------------------------------------

    /**
     * D-071 — the minimum proof that a "usable" document really contributed
     * bill data: at least one billing-period, energy or money value. Purely
     * contextual text (a provider name read off a logo, a service-number-shaped
     * string in decorative text) never counts as evidence.
     *
     * @param array<string, string|null> $values
     */
    private static function hasBillEvidence(array $values): bool
    {
        $evidence = array_merge(BillField::DATES, BillField::ENERGY, BillField::MONEY);

        foreach ($evidence as $field) {
            if (($values[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * D-071 — ownership outcome as controlled quality flags. Each state is
     * exactly what the evidence supports:
     *
     * - household never registered a service number → setup requirement;
     * - the document carried no confirmable number → review, not validation;
     * - canonical strings differ → mismatch (values themselves untouched);
     * - equality after documented conservative canonicalization → NO flag:
     *   a real match, with the matching evidence stored on both sides.
     *
     * There is no "ownership PASS" row by design — absence of an ownership
     * flag alongside a non-null bill service number IS the pass, and it is
     * only ever derivable from actual matching values.
     *
     * @return list<BillQualityFlag>
     */
    private static function ownershipFlags(?string $householdServiceNumber, ?string $billServiceNumber): array
    {
        if ($householdServiceNumber === null) {
            return [BillQualityFlag::create(QualityCode::HOUSEHOLD_SERVICE_NOT_CONFIGURED, null)];
        }

        if ($billServiceNumber === null) {
            return [BillQualityFlag::create(QualityCode::SERVICE_NUMBER_MISSING, BillField::SERVICE_NUMBER)];
        }

        if (!ServiceNumber::matches($householdServiceNumber, $billServiceNumber)) {
            return [BillQualityFlag::create(QualityCode::SERVICE_NUMBER_MISMATCH, BillField::SERVICE_NUMBER)];
        }

        return [];
    }

    /**
     * The caller's active household ENTITY (D-071): ownership checks need
     * the registered profile, still resolved exactly like every other
     * household scope — from membership, never from a caller.
     */
    private function requireHouseholdContext(Account $account): \App\Domain\Household\Household
    {
        $context = $this->households->activeHouseholdFor($account);

        if ($context === null) {
            throw new AppException('NOT_FOUND', 'The caller has no active household context.', 404);
        }

        return $context['household'];
    }

    private function transition(Account $account, string $billId, string $target, DateTimeImmutable $now): Bill
    {
        $householdId = $this->requireHousehold($account);
        $bill = $this->loadScoped($billId, $householdId);

        try {
            $updated = $bill->transitionTo($target, $now);
        } catch (InvalidArgumentException $e) {
            throw self::stateError($e);
        }

        if (!$this->bills->save($updated)) {
            throw new AppException('NOT_FOUND', 'The requested bill was not found.', 404);
        }

        return $updated;
    }

    private function requireHousehold(Account $account): string
    {
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException('NOT_FOUND', 'The caller has no active household context.', 404);
        }

        return $householdId;
    }

    private function loadScoped(string $billId, string $householdId): Bill
    {
        $bill = $this->bills->find($billId, $householdId);

        if ($bill === null) {
            throw new AppException('NOT_FOUND', 'The requested bill was not found.', 404);
        }

        return $bill;
    }

    private static function validationError(InvalidArgumentException $e): AppException
    {
        $message = $e->getMessage();
        $field = null;

        foreach (BillField::ALL as $candidate) {
            if (str_starts_with($message, $candidate)) {
                $field = $candidate;
                break;
            }
        }

        return new AppException(
            'VALIDATION_ERROR',
            $message,
            422,
            $field === null ? [] : ['field' => $field]
        );
    }

    private static function stateError(InvalidArgumentException $e): AppException
    {
        return new AppException('INVALID_BILL_STATE', $e->getMessage(), 409);
    }
}
