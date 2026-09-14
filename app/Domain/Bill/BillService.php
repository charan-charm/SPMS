<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use App\Domain\Account\Account;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
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
        private readonly HouseholdService $households = new HouseholdService()
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
        return $this->transition($account, $billId, BillStatus::PROCESSING_FAILED, $now);
    }

    /**
     * Persist one completed extraction (§3–§10, §17):
     *
     * - requires a PROCESSING bill (lifecycle-guarded, else 409);
     * - stores the immutable draft + validation results + quality flags
     *   atomically with the bill update;
     * - for a USABLE assessment (VALID / VALID_WITH_LIMITATIONS) the
     *   normalized values become the bill's current values with
     *   AI_EXTRACTION provenance — including honest NULLs for whatever
     *   the provider could not extract (no zero-filling);
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
        $householdId = $this->requireHousehold($account);
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

            $needsReview = $assessment === DocumentAssessment::VALID_WITH_LIMITATIONS
                || $conflicted
                || $flags !== [];

            try {
                $updated = $bill
                    ->withValues($draft->values(), $now)
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

            $provenance = [];
            foreach ($draft->values() as $field => $value) {
                if ($value === null) {
                    continue; // no provenance row for data that does not exist
                }
                $provenance[$field] = [
                    'source' => ProvenanceSource::AI_EXTRACTION,
                    'confidence' => $draft->confidences()[$field] ?? null,
                ];
            }
        }

        $this->bills->recordExtraction($updated, $draft, $validations, $flags, $provenance);

        return $updated;
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
     */
    public function verify(Account $account, string $billId, DateTimeImmutable $now): Bill
    {
        $householdId = $this->requireHousehold($account);
        $bill = $this->loadScoped($billId, $householdId);

        try {
            $updated = $bill->verify($now);
        } catch (InvalidArgumentException $e) {
            throw self::stateError($e);
        }

        if (!$this->bills->save($updated)) {
            throw new AppException('NOT_FOUND', 'The requested bill was not found.', 404);
        }

        return $updated;
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
