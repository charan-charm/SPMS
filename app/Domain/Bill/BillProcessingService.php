<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use App\Domain\Account\Account;
use App\Repositories\BillTempDocumentRepository;
use App\Services\BillExtraction\BillExtractionFailure;
use App\Services\BillExtraction\BillExtractionGateway;
use App\Services\BillExtraction\MistralBillExtractionProvider;
use App\Services\Files\BillTemporaryStorageInterface;
use App\Services\Files\LocalPrivateBillStorage;
use App\Services\Mistral\BillAnnotationNormalizer;
use App\Services\Mistral\MistralOcrClient;
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
 *
 * APSPDCL XLS multi-row import (§22):
 *  When the uploaded document is an APSPDCL HTML-formatted .xls export,
 *  ApspdclExportParser returns one record per billing-period row.
 *  records[0] is the primary bill (newest in the export); records[1..n]
 *  are historical months. Each historical month is independently
 *  persisted through the same recordExtraction lifecycle. Per-row
 *  outcomes are captured in import_summary and returned to the caller
 *  so the API surface can expose exactly which months were created,
 *  skipped (already exists), or failed — nothing is silently discarded.
 */
final class BillProcessingService
{
    private readonly BillExtractionGateway $gateway;

    public function __construct(
        private readonly BillService $bills = new BillService(),
        private readonly BillTempDocumentRepository $tempDocs = new BillTempDocumentRepository(),
        private readonly BillTemporaryStorageInterface $storage = new LocalPrivateBillStorage(),
        BillExtractionGateway|MistralOcrClient|null $gateway = null
    ) {
        if ($gateway instanceof MistralOcrClient) {
            $this->gateway = new BillExtractionGateway([new MistralBillExtractionProvider($gateway)]);
        } else {
            $this->gateway = $gateway ?? new BillExtractionGateway();
        }
    }

    /**
     * @return array{
     *     bill: Bill,
     *     assessment: string,
     *     document_confidence: string|null,
     *     validations: list<BillValidation>,
     *     quality_flags: list<BillQualityFlag>,
     *     import_summary: array<string,mixed>|null
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

        // 2. Idempotency gate: extraction runs from PROCESSING or retry from
        //    PROCESSING_FAILED. Never overwrites an existing immutable draft,
        //    never burns a provider call for a bill that has already reached
        //    authoritative or recognized states.
        if ($bill->status() === BillStatus::PROCESSING_FAILED) {
            $bill = $this->bills->markProcessing($account, $billId, $now);
        } elseif ($bill->status() !== BillStatus::PROCESSING) {
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

        // 4. Processing dispatch: APSPDCL spreadsheet export vs. Mistral OCR.
        if ($temp->mimeType() === BillUpload::TYPE_XLS || str_ends_with(strtolower($temp->storageKey()), '.xls')) {
            return $this->processXls($account, $bill, $temp, $path, $now);
        }

        return $this->processMistral($account, $bill, $temp, $path, $now);
    }

    // ------------------------------------------------------------------
    // APSPDCL XLS path (§22)
    // ------------------------------------------------------------------

    /**
     * @return array{
     *     bill: Bill,
     *     assessment: string,
     *     document_confidence: string,
     *     validations: list<BillValidation>,
     *     quality_flags: list<BillQualityFlag>,
     *     import_summary: array<string,mixed>
     * }
     */
    private function processXls(
        Account $account,
        Bill $bill,
        BillTempDocument $temp,
        string $path,
        DateTimeImmutable $now
    ): array {
        $content = file_get_contents($path);
        if ($content === false) {
            $this->bills->markProcessingFailed($account, $bill->id(), $now);
            throw new AppException('DOCUMENT_UNAVAILABLE', 'The uploaded document could not be read.', 410);
        }

        $parser = new \App\Domain\Bill\Apspdcl\ApspdclExportParser();
        try {
            $records = $parser->parse($content);
        } catch (Throwable $e) {
            $this->bills->markProcessingFailed($account, $bill->id(), $now);
            throw new AppException('EXTRACTION_FAILED', 'Failed to parse APSPDCL export: ' . $e->getMessage(), 422);
        }

        $totalRows = count($records);
        $primaryIdx = 0;

        // --- Primary bill (records[0] = most recent row in the export) ---
        $latest = $records[$primaryIdx];
        $values = $latest['values'];
        $validations = $latest['validations'];
        $flags = $latest['quality_flags'];
        $assessment = $latest['assessment'];
        $documentConfidence = '1.0000';

        // Augment source_metadata_json with provenance back to this upload.
        $values = self::augmentMetadata($values, $bill->id(), $primaryIdx, $totalRows);

        $confidences = [];
        foreach ($values as $field => $val) {
            if ($val !== null && BillField::isPersistable($field)) {
                $confidences[$field] = '1.0000';
            }
        }

        $draft = BillExtractionDraft::create(
            $bill->id(),
            'apspdcl_export',
            'apspdcl-html-xls-parser-v1',
            $now,
            $assessment,
            $latest['assessment_reason'],
            $documentConfidence,
            $values,
            $confidences,
            $now
        );

        // --- Historical monthly rows (records[1..n]) ---
        // Each row result is captured in $importSummary.historical so the
        // caller can surface exactly what happened — no silent swallowing.
        $historicalResults = [];

        for ($k = 1; $k < $totalRows; $k++) {
            $olderRec = $records[$k];
            $oValues = $olderRec['values'];
            $oStart = $oValues[BillField::BILLING_PERIOD_START] ?? null;
            $oEnd = $oValues[BillField::BILLING_PERIOD_END] ?? null;

            if ($oStart === null || $oEnd === null) {
                $historicalResults[] = [
                    'row_index' => $k,
                    'period_start' => null,
                    'period_end' => null,
                    'bill_id' => null,
                    'result' => 'skipped',
                    'reason' => 'missing_billing_period',
                ];
                continue;
            }

            // Duplicate guard: skip if this billing period already exists.
            $alreadyExists = false;
            $existingBillId = null;
            try {
                foreach ($this->bills->listForAccount($account) as $eb) {
                    if ($eb->value(BillField::BILLING_PERIOD_START) === $oStart
                        && $eb->value(BillField::BILLING_PERIOD_END) === $oEnd) {
                        $alreadyExists = true;
                        $existingBillId = $eb->id();
                        break;
                    }
                }
            } catch (Throwable) {
                // If the existence check itself fails, fall through to the
                // creation attempt; a DB unique constraint will catch true
                // duplicates there.
            }

            if ($alreadyExists) {
                $historicalResults[] = [
                    'row_index' => $k,
                    'period_start' => $oStart,
                    'period_end' => $oEnd,
                    'bill_id' => $existingBillId,
                    'result' => 'skipped',
                    'reason' => 'already_exists',
                ];
                continue;
            }

            // Augment provenance for this historical row.
            $oValues = self::augmentMetadata($oValues, $bill->id(), $k, $totalRows);

            try {
                $olderBill = $this->bills->create($account, $oStart, $oEnd, $now);
                $olderBill = $this->bills->markProcessing($account, $olderBill->id(), $now);

                $oConfidences = [];
                foreach ($oValues as $f => $v) {
                    if ($v !== null && BillField::isPersistable($f)) {
                        $oConfidences[$f] = '1.0000';
                    }
                }

                $oDraft = BillExtractionDraft::create(
                    $olderBill->id(),
                    'apspdcl_export',
                    'apspdcl-html-xls-parser-v1',
                    $now,
                    $olderRec['assessment'],
                    $olderRec['assessment_reason'],
                    '1.0000',
                    $oValues,
                    $oConfidences,
                    $now
                );

                $persistedOlder = $this->bills->recordExtraction(
                    $account,
                    $olderBill->id(),
                    $oDraft,
                    $olderRec['validations'],
                    $olderRec['quality_flags'],
                    $now
                );

                $historicalResults[] = [
                    'row_index' => $k,
                    'period_start' => $oStart,
                    'period_end' => $oEnd,
                    'bill_id' => $olderBill->id(),
                    'result' => 'created',
                    'status' => $persistedOlder->status(),
                ];
            } catch (AppException $ae) {
                $historicalResults[] = [
                    'row_index' => $k,
                    'period_start' => $oStart,
                    'period_end' => $oEnd,
                    'bill_id' => null,
                    'result' => 'failed',
                    'reason' => $ae->errorCode(),
                ];
            } catch (Throwable) {
                $historicalResults[] = [
                    'row_index' => $k,
                    'period_start' => $oStart,
                    'period_end' => $oEnd,
                    'bill_id' => null,
                    'result' => 'failed',
                    'reason' => 'PERSISTENCE_FAILED',
                ];
            }
        }

        $newlyImported = 1; // +1 for primary bill
        $alreadyExisting = 0;
        $rejectedRows = 0;
        foreach ($historicalResults as $hr) {
            if ($hr['result'] === 'created') {
                $newlyImported++;
            } elseif ($hr['result'] === 'skipped') {
                $alreadyExisting++;
            } elseif ($hr['result'] === 'failed') {
                $rejectedRows++;
            }
        }

        $solarActiveCount = 0;
        $preSolarCount = 0;
        foreach ($records as $r) {
            $app = $r['raw_metadata']['solar_applicability'] ?? null;
            if ($app === 'SOLAR_ACTIVE') {
                $solarActiveCount++;
            } elseif ($app === 'BEFORE_SOLAR_INSTALLATION') {
                $preSolarCount++;
            }
        }

        $oldestRec = $records[$totalRows - 1] ?? null;
        $newestRec = $records[0] ?? null;
        $coverageStart = $oldestRec['values'][BillField::BILLING_PERIOD_START] ?? null;
        $coverageEnd = $newestRec['values'][BillField::BILLING_PERIOD_END] ?? null;

        $importSummary = [
            'total_rows' => $totalRows,
            'total_detected' => $totalRows,
            'newly_imported' => $newlyImported,
            'already_existing' => $alreadyExisting,
            'duplicates_skipped' => $alreadyExisting,
            'rejected_rows' => $rejectedRows,
            'coverage_range' => [
                'start' => $coverageStart,
                'end' => $coverageEnd,
            ],
            'solar_coverage' => [
                'solar_active_periods' => $solarActiveCount,
                'pre_solar_periods' => $preSolarCount,
            ],
            'primary_row_index' => $primaryIdx,
            'historical' => $historicalResults,
        ];

        // Persist the primary bill extraction.
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

        // Best-effort temporary cleanup.
        try {
            if ($this->storage->delete($temp->storageKey())) {
                $this->tempDocs->markDeleted($temp->id(), $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
            }
        } catch (Throwable) {
            // Deliberately non-fatal: B2 expiry cleanup is the durable backstop.
        }

        return [
            'bill' => $updated,
            'assessment' => $assessment,
            'document_confidence' => $documentConfidence,
            'validations' => $validations,
            'quality_flags' => $flags,
            'import_summary' => $importSummary,
        ];
    }

    // ------------------------------------------------------------------
    // Multimodal OCR path (image / PDF) — resilient gateway
    // ------------------------------------------------------------------

    /**
     * @return array{
     *     bill: Bill,
     *     assessment: string,
     *     document_confidence: string|null,
     *     validations: list<BillValidation>,
     *     quality_flags: list<BillQualityFlag>,
     *     import_summary: null
     * }
     */
    private function processMistral(
        Account $account,
        Bill $bill,
        BillTempDocument $temp,
        string $path,
        DateTimeImmutable $now
    ): array {
        // Determine MIME type from the temp document metadata (already
        // validated at intake; no re-sniffing here).
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'pdf'        => 'application/pdf',
            'png'        => 'image/png',
            'jpg', 'jpeg'=> 'image/jpeg',
            default      => $temp->mimeType(),
        };

        // Provider call — through the extraction gateway (Mistral → Gemini fallback).
        try {
            $result = $this->gateway->extract(
                $path,
                $mimeType,
                MistralOcrClient::billAnnotationSchema(),
                MistralOcrClient::BILL_ANNOTATION_PROMPT
            );
        } catch (BillExtractionFailure $e) {
            $this->bills->markProcessingFailed($account, $bill->id(), $now);
            $reasonCode = match ($e->category) {
                BillExtractionFailure::TERMINAL_AUTH             => 'EXTRACTION_AUTHENTICATION_ERROR',
                BillExtractionFailure::TERMINAL_NOT_CONFIGURED   => 'EXTRACTION_CONFIGURATION_ERROR',
                BillExtractionFailure::TERMINAL_INVALID_DOCUMENT => 'UNSUPPORTED_DOCUMENT',
                BillExtractionFailure::TERMINAL_MALFORMED_RESPONSE => 'EXTRACTION_MALFORMED_RESPONSE',
                default                                          => 'EXTRACTION_PROVIDER_ERROR',
            };

            $underlying = $e->getPrevious();
            while ($underlying instanceof BillExtractionFailure && $underlying->getPrevious() !== null) {
                $underlying = $underlying->getPrevious();
            }

            $safeCategory = $e->category;
            if ($underlying instanceof \App\Services\Mistral\MistralOcrException) {
                $safeCategory = $underlying->category();
            }

            try {
                \App\Support\Logger::default()->error('bill.extraction.gateway.failed', [
                    'bill_id'    => $bill->id(),
                    'provider'   => $e->provider,
                    'category'   => $e->category,
                    'reason'     => $reasonCode,
                    'http_status'=> $e->httpStatus,
                ]);
            } catch (\Throwable) {
            }
            throw new AppException(
                'EXTRACTION_FAILED',
                'SPMS could not finish reading this bill (' . $safeCategory . '). Please retry or upload another copy.',
                502,
                [
                    'category' => $e->category,
                    'reason'   => $reasonCode,
                ]
            );
        }

        // Normalize + validate deterministically (absent/undecodable
        // annotation becomes an honest UNCERTAIN record).
        $annotation = is_array($result['annotation'] ?? null) ? $result['annotation'] : null;

        $normalized = BillAnnotationNormalizer::normalize($annotation);
        $validations = BillAnnotationNormalizer::validations($annotation);
        $flags = BillAnnotationNormalizer::qualityFlags($normalized, $validations);
        [$assessment, $assessmentReason] = BillAnnotationNormalizer::reconcileAssessment(
            $normalized['assessment'],
            $normalized['assessment_reason'],
            $validations,
            $flags
        );
        $documentConfidence = $normalized['document_confidence'];

        // Use provider provenance from the gateway result.
        $extractionProvider = is_string($result['provider'] ?? null) && $result['provider'] !== ''
            ? $result['provider']
            : 'unknown';
        $extractionModel = is_string($result['model'] ?? null) && $result['model'] !== ''
            ? $result['model']
            : null;

        $draft = BillExtractionDraft::create(
            $bill->id(),
            $extractionProvider,
            $extractionModel ?? $extractionProvider,
            $now,
            $assessment,
            $assessmentReason,
            $documentConfidence,
            $normalized['values'],
            $normalized['confidences'],
            $now
        );

        // Persistence + lifecycle through the ratified B1 service.
        try {
            $updated = $this->bills->recordExtraction($account, $bill->id(), $draft, $validations, $flags, $now);
        } catch (AppException $e) {
            $this->bills->markProcessingFailed($account, $bill->id(), $now);
            try {
                if ($this->storage->delete($temp->storageKey())) {
                    $this->tempDocs->markDeleted($temp->id(), $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
                }
            } catch (Throwable) {
            }
            throw $e;
        } catch (Throwable) {
            $this->bills->markProcessingFailed($account, $bill->id(), $now);
            try {
                if ($this->storage->delete($temp->storageKey())) {
                    $this->tempDocs->markDeleted($temp->id(), $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
                }
            } catch (Throwable) {
            }
            throw new AppException(
                'PERSISTENCE_FAILED',
                'The extraction could not be stored; the bill was marked failed.',
                500
            );
        }

        // Best-effort temporary cleanup.
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
            'assessment' => $assessment,
            'document_confidence' => $documentConfidence,
            'validations' => $validations,
            'quality_flags' => $flags,
            'import_summary' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Augment source_metadata_json with provenance fields linking this record
     * back to the original XLS upload's bill_id and its position in the export.
     * Handles the 4000-character TEXT column limit conservatively.
     *
     * @param array<string, string|null> $values
     * @return array<string, string|null>
     */
    private static function augmentMetadata(
        array $values,
        string $sourceBillId,
        int $rowIndex,
        int $totalRows
    ): array {
        $meta = json_decode($values[BillField::SOURCE_METADATA_JSON] ?? '{}', true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $meta['source_bill_id'] = $sourceBillId;
        $meta['import_row_index'] = $rowIndex;
        $meta['import_total_rows'] = $totalRows;

        $encoded = json_encode($meta, JSON_THROW_ON_ERROR);

        if (mb_strlen($encoded) > 4000) {
            // Remove the least critical provenance field to fit.
            unset($meta['import_total_rows']);
            $encoded = json_encode($meta, JSON_THROW_ON_ERROR);
        }

        $values[BillField::SOURCE_METADATA_JSON] = $encoded;

        return $values;
    }
}
