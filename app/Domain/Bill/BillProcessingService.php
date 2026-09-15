<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use App\Domain\Account\Account;
use App\Repositories\BillTempDocumentRepository;
use App\Services\Files\BillTemporaryStorageInterface;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\BillAnnotationNormalizer;
use App\Services\Mistral\MistralOcrClient;
use App\Services\Mistral\MistralOcrException;
use App\Support\AppException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * B3 — Bill extraction workflow (docs/10 D-069): PROCESSING bill →
 * private temporary document → Mistral OCR → normalized draft +
 * provenance + validations + quality flags → honest lifecycle outcome.
 *
 * The AI supplies interpretation ONLY. This service decides nothing on
 * its own authority either: normalization is structural (the
 * anti-corruption mapper), and the persistence + lifecycle outcome is
 * delegated to the ratified B1 BillService::recordExtraction — which
 * already refuses to verify, already maps assessments to states through
 * the protected lifecycle, and already stores the immutable draft,
 * provenance, validations and flags atomically.
 *
 * Ordering chosen for recoverability (§19/§20):
 *  1. scoped load + strict PROCESSING-only gate (repeat processing can
 *     never re-call the provider or overwrite an existing draft);
 *  2. scoped temporary-document resolution BEFORE any provider call;
 *  3. provider failure → NOTHING persisted, bill stays PROCESSING with
 *     its document intact: the operation is simply retryable;
 *  4. malformed provider result → persisted honestly as UNCERTAIN
 *     (the response WAS received; recording that judgment is the
 *     recoverable state — the input stays until cleanup succeeds);
 *  5. successful persist → best-effort immediate temporary cleanup;
 *     any failure there is intentionally non-fatal because the B2
 *     expiry cleanup service remains the durable backstop.
 */
final class BillProcessingService
{
    public function __construct(
        private readonly BillService $bills = new BillService(),
        private readonly BillTempDocumentRepository $tempDocs = new BillTempDocumentRepository(),
        private readonly BillTemporaryStorageInterface $storage = new LocalPrivateBillStorage(),
        private readonly MistralOcrClient $ocr = new MistralOcrClient()
    ) {
    }

    /**
     * @return array{
     *     bill: Bill,
     *     assessment: string,
     *     document_confidence: string|null,
     *     validations: list<BillValidation>,
     *     quality_flags: list<BillQualityFlag>
     * }
     */
    public function process(Account $account, string $billId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Household-scoped load (B1 repository: foreign ids are 404).
        $bill = $this->bills->get($account, $billId);

        if ($bill === null) {
            throw new AppException('NOT_FOUND', 'The requested bill was not found.', 404);
        }

        // 2. Idempotency gate: extraction runs only from PROCESSING —
        //    never overwrites an existing immutable draft, never burns a
        //    provider call for a bill that has already been extracted,
        //    never re-runs a terminal bill.
        if ($bill->status() !== BillStatus::PROCESSING) {
            throw new AppException(
                'INVALID_BILL_STATE',
                'Only a PROCESSING bill can be extracted; this bill is ' . $bill->status() . '.',
                409
            );
        }

        // 3. The temporary document, resolved through the SAME household
        //    scope as the bill (join bills.household_id).
        $temp = $this->tempDocs->findForBill($bill->id(), $bill->householdId());
        $path = $temp !== null && $temp->state() === BillTempDocument::STATE_AVAILABLE
            ? $this->storage->localPath($temp->storageKey())
            : null;

        if ($temp === null || $path === null) {
            // The only processing input is gone: honestly terminal.
            $this->bills->markProcessingFailed($account, $bill->id(), $now);

            throw new AppException(
                'DOCUMENT_UNAVAILABLE',
                'The uploaded document is no longer available for processing.',
                410
            );
        }

        // 4. Provider call — through the ratified client only (image
        //    data-URL / PDF Files-API routes; no duplicated HTTP here).
        try {
            $validated = MistralOcrClient::validateLocalDocument($path);

            if ($validated['mime'] === 'application/pdf') {
                $document = MistralOcrClient::documentUrlRef(
                    $this->ocr->fetchSignedUrl($this->ocr->uploadDocument($validated), 24)
                );
            } else {
                $document = MistralOcrClient::imageDataUrlDocument($validated);
            }

            $result = $this->ocr->processOcr(
                $document,
                MistralOcrClient::billAnnotationSchema(),
                MistralOcrClient::BILL_ANNOTATION_PROMPT
            );
        } catch (MistralOcrException $e) {
            // Recoverable by design: nothing was persisted, the bill is
            // still PROCESSING and the document is still available.
            // (The exception's own message surface is already sanitized
            // — category + HTTP status + bounded ASCII excerpt only.)
            throw new AppException(
                'EXTRACTION_FAILED',
                'The bill document could not be processed right now (' . $e->category() . '); the bill remains queued for processing.',
                502
            );
        }

        // 5. Normalize + validate deterministically (absent/undecodable
        //    annotation becomes an honest UNCERTAIN record).
        $annotation = is_array($result['annotation'] ?? null) ? $result['annotation'] : null;

        $normalized = BillAnnotationNormalizer::normalize($annotation);
        $validations = BillAnnotationNormalizer::validations($annotation);
        $flags = BillAnnotationNormalizer::qualityFlags($normalized, $validations);

        $draft = BillExtractionDraft::create(
            $bill->id(),
            'mistral',
            is_string($result['model'] ?? null) && $result['model'] !== '' ? $result['model'] : $this->ocr->model(),
            $now,
            $normalized['assessment'],
            $normalized['assessment_reason'],
            $normalized['document_confidence'],
            $normalized['values'],
            $normalized['confidences'],
            $now
        );

        // 6. Persistence + lifecycle through the ratified B1 service.
        try {
            $updated = $this->bills->recordExtraction($account, $bill->id(), $draft, $validations, $flags, $now);
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable) {
            throw new AppException(
                'PERSISTENCE_FAILED',
                'The extraction could not be stored; the bill remains queued for processing.',
                500
            );
        }

        // 7. Immediate, best-effort temporary cleanup (B2 lifecycle).
        try {
            if ($this->storage->delete($temp->storageKey())) {
                $this->tempDocs->markDeleted($temp->id(), $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
            }
        } catch (Throwable) {
            // Deliberately non-fatal: the extraction is committed; the
            // B2 expiry cleanup service will remove the document later.
        }

        return [
            'bill' => $updated,
            'assessment' => $normalized['assessment'],
            'document_confidence' => $normalized['document_confidence'],
            'validations' => $validations,
            'quality_flags' => $flags,
        ];
    }
}
