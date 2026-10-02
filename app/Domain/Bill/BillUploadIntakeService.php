<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use App\Domain\Account\Account;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Repositories\BillTempDocumentRepository;
use App\Services\Files\BillStorageException;
use App\Services\Files\BillTemporaryStorageInterface;
use App\Services\Files\LocalPrivateBillStorage;
use App\Support\AppException;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * B2 — Secure bill upload intake (docs/10 D-068).
 *
 * The complete production sequence for one intake, ordered so that no
 * failure mode leaves an uncontrolled artifact:
 *
 *   1. household derived server-side (W3 membership — never client data);
 *   2. BillUpload VO already validated BEFORE anything was touched;
 *   3. private temporary file written FIRST (no bill exists yet — a
 *      storage failure simply never starts the bill's lifecycle);
 *   4. bill row (UPLOADED) + PROCESSING transition + temp reference
 *      committed in ONE database transaction;
 *   5. if step 4 fails for any reason, the temporary file is DELETED
 *      (compensation) before the error surfaces — §12: file + DB cannot
 *      be one atomic unit, but they can be one committed unit plus a
 *      deterministic cleanup, which is exactly what this does.
 *
 * What intake never does: inspect document content (no OCR/render —
 * B3's job), create extraction drafts, accept any identifier from the
 * client, mark anything VERIFIED, or log/expose paths and filenames.
 */
final class BillUploadIntakeService
{
    public function __construct(
        private readonly BillTempDocumentRepository $tempDocs = new BillTempDocumentRepository(),
        private readonly BillTemporaryStorageInterface $storage = new LocalPrivateBillStorage(),
        private readonly HouseholdService $households = new HouseholdService(),
        private readonly BillRepository $bills = new BillRepository()
    ) {
    }

    /**
     * @return array{bill: Bill, temp: BillTempDocument}
     */
    public function accept(Account $account, BillUpload $upload, DateTimeImmutable $now): array
    {
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException(
                'NOT_FOUND',
                'The caller has no active household context.',
                404
            );
        }

        $fingerprint = $upload->fingerprint();

        // Level 1 — Exact File Duplicate Detection BEFORE external OCR/storage
        $existing = $this->bills->findRecordedByFingerprint($householdId, $fingerprint);
        if ($existing !== null) {
            $start = $existing->value(BillField::BILLING_PERIOD_START);
            $end = $existing->value(BillField::BILLING_PERIOD_END);
            $monthLabel = null;
            if ($end !== null) {
                $monthLabel = date('F Y', strtotime($end));
            }

            throw new AppException(
                'DUPLICATE_BILL_FILE',
                $monthLabel !== null
                    ? "{$monthLabel} bill is already recorded for your household."
                    : 'This bill file is already recorded for your household.',
                409,
                [
                    'duplicate_type' => 'FILE',
                    'existing_bill_id' => $existing->id(),
                    'billing_period_start' => $start,
                    'billing_period_end' => $end,
                    'month_label' => $monthLabel,
                    'status' => $existing->status(),
                ]
            );
        }

        try {
            $bill = Bill::create($householdId, null, null, $now, $fingerprint);
            $temp = BillTempDocument::create(
                $bill->id(),
                $upload->mimeType(),
                $upload->sizeBytes(),
                self::retentionHours(),
                $now
            );
        } catch (\InvalidArgumentException $e) {
            throw new AppException('INVALID_UPLOAD', 'The upload could not be registered.', 422);
        }

        // Storage BEFORE any database write: a failed store leaves no bill.
        try {
            $this->storage->store($temp->storageKey(), $upload->bytes());
        } catch (BillStorageException) {
            throw new AppException(
                'STORAGE_FAILED',
                'The uploaded document could not be stored for processing.',
                500
            );
        }

        // The only path to PROCESSING (B1 lifecycle), after the file is safe.
        try {
            $processing = $bill->transitionTo(BillStatus::PROCESSING, $now);
        } catch (\InvalidArgumentException $e) {
            // Unreachable by construction (UPLOADED -> PROCESSING is legal);
            // still compensate the file if it ever happens.
            $this->storage->delete($temp->storageKey());

            throw new AppException('UPLOAD_FAILED', 'The upload could not be registered.', 500);
        }

        try {
            $this->tempDocs->persistUpload($bill, $processing, $temp);
        } catch (Throwable $e) {
            // Compensation: the committed-unit failed, so the file must
            // not remain uncontrolled. delete() is idempotent.
            $this->storage->delete($temp->storageKey());

            if ($e instanceof AppException) {
                throw $e;
            }

            throw new AppException(
                'STORAGE_FAILED',
                'The upload could not be completed.',
                500
            );
        }

        return ['bill' => $processing, 'temp' => $temp];
    }

    /**
     * Configurable short retention (§10): BILL_TEMP_RETENTION_HOURS,
     * bounded 1..168; any invalid configuration falls back to the
     * ratified 24-hour default rather than inventing a longer life.
     */
    public static function retentionHours(): int
    {
        $raw = Config::get('BILL_TEMP_RETENTION_HOURS', '24');
        $hours = $raw !== null ? (int) $raw : 0;

        return ($hours >= 1 && $hours <= 168) ? $hours : 24;
    }
}
