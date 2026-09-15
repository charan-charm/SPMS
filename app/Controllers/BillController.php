<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillService;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Domain\Bill\BillValidation;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * B2 — Secure bill document intake (docs/10 D-068).
 *
 * POST /api/v1/bills/upload — multipart/form-data, authenticated,
 * CSRF-protected by the auth middleware like every mutation (D-052),
 * household derived server-side through the W3 membership rule.
 *
 * The endpoint's input surface is exactly ONE thing: a file part named
 * "file". Every text field is rejected with the standard field-scoped
 * 422 envelope — household_id, account_id, role, status or anything
 * else cannot be claimed, selected or smuggled here (§2/§17). The file
 * itself is validated fail-closed by BillUpload before the intake
 * service stores it; the response carries only safe processing metadata
 * (§14): bill id, lifecycle status, normalized document type and actual
 * size. Never: filesystem paths, storage keys, the original filename,
 * or any document content.
 *
 * B2 boundary: intake only. No OCR, no draft creation, no verification —
 * the bill is handed to the world as PROCESSING, exactly as B1's
 * lifecycle prescribes, for B3 to consume.
 *
 * B3 added POST /api/v1/bills/{bill_id}/process (docs/10 D-069): the
 * extraction intake, whose response carries normalized, persisted facts
 * only. B4 added the matching READ surface (GET /api/v1/bills and
 * GET /api/v1/bills/{bill_id}) for the bills workspace — the same
 * household scoping, the same data-minimized vocabulary: whitelisted
 * fields, provenance sources/confidences, deterministic validation
 * outcomes verbatim, controlled quality codes. Still nothing beyond
 * that: no paths, no keys, no provider payloads, and no verification —
 * VERIFIED remains a future B5 operation the reads can only report.
 */
final class BillController
{
    /**
     * @param callable|null $uploadVerifier production keeps PHP's
     *        is_uploaded_file() gate; offline tests verify synthetic
     *        temp files through this seam (same pattern as the AI
     *        adapter's transport seam) — never client-controllable.
     */
    private readonly ?\Closure $uploadVerifier;

    public function __construct(
        private readonly BillUploadIntakeService $intake = new BillUploadIntakeService(),
        private readonly BillProcessingService $processing = new BillProcessingService(),
        private readonly BillService $billService = new BillService(),
        ?callable $uploadVerifier = null
    ) {
        $this->uploadVerifier = $uploadVerifier !== null ? \Closure::fromCallable($uploadVerifier) : null;
    }

    public function upload(Request $request): Response
    {
        $account = Authorization::requireAccount($request);

        $body = $request->body();
        if ($body !== []) {
            $field = (string) array_key_first($body);

            throw new AppException(
                'VALIDATION_ERROR',
                'The bill upload accepts exactly one part: the "file". Text fields are not allowed.',
                422,
                ['field' => $field]
            );
        }

        $spec = $request->uploadedFile('file');

        if ($spec === null) {
            throw new AppException(
                'INVALID_UPLOAD',
                'A file upload part named "file" is required.',
                422,
                ['field' => 'file']
            );
        }

        $upload = BillUpload::fromFilesSpec($spec, $this->uploadVerifier);

        $result = $this->intake->accept(
            $account,
            $upload,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );

        return Response::success([
            'bill_id' => $result['bill']->id(),
            'status' => $result['bill']->status(),
            'document' => [
                'type' => $result['temp']->mimeType(),
                'size_bytes' => $result['temp']->sizeBytes(),
            ],
        ], 201);
    }

    /**
     * POST /api/v1/bills/{bill_id}/process — B3 extraction intake
     * (docs/10 D-069). Authenticated + CSRF (middleware) + household
     * scoped through the W3 membership rule: the bill id addresses ONLY
     * a bill inside the caller's own derived household; anything else
     * is the established indistinguishable 404.
     *
     * The response carries normalized, already-persisted facts only —
     * lifecycle status, document assessment, deterministic validation
     * statuses, quality flag codes. No raw OCR, no provider response,
     * no storage paths or keys, no document content (§21).
     */
    public function process(Request $request): Response
    {
        $account = Authorization::requireAccount($request);

        $billId = $this->extractBillId($request->path());

        if ($billId === null) {
            throw new AppException('NOT_FOUND', 'The requested bill was not found.', 404);
        }

        $result = $this->processing->process(
            $account,
            $billId,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );

        $validation = [];
        foreach ($result['validations'] as $v) {
            $validation[$v->rule()] = $v->status();
        }

        $flags = array_values(array_map(
            static fn (BillQualityFlag $flag): string => $flag->code(),
            $result['quality_flags']
        ));
        sort($flags);

        return Response::success([
            'bill_id' => $result['bill']->id(),
            'status' => $result['bill']->status(),
            'extraction' => [
                'document_assessment' => $result['assessment'],
                'document_confidence' => $result['document_confidence'],
                'validation' => $validation,
                'quality_flags' => $flags,
            ],
        ]);
    }

    /**
     * GET /api/v1/bills — B4 household bill list (read surface).
     *
     * Exactly the caller's own household (derived server-side through the
     * W3 membership rule; nothing is accepted from the client), ordered
     * newest first. The row shape is the review/history display set only:
     * lifecycle status, verification stamp, period, headline energy and
     * money fields as canonical decimal strings or honest nulls. Never:
     * household id, storage keys, paths, provenance detail, provider text.
     */
    public function index(Request $request): Response
    {
        $account = Authorization::requireAccount($request);

        $bills = array_map(
            static fn (Bill $bill): array => [
                'bill_id' => $bill->id(),
                'status' => $bill->status(),
                'billing_period_start' => $bill->value(BillField::BILLING_PERIOD_START),
                'billing_period_end' => $bill->value(BillField::BILLING_PERIOD_END),
                'consumption_kwh' => $bill->value('consumption_kwh'),
                'solar_units_kwh' => $bill->value('solar_units_kwh'),
                'net_units_kwh' => $bill->value('net_units_kwh'),
                'bill_amount' => $bill->value('bill_amount'),
                'total_due' => $bill->value('total_due'),
                'verified_at' => $bill->verifiedAt(),
                'updated_at' => $bill->updatedAt(),
            ],
            $this->billService->listForAccount($account)
        );

        return Response::success(['bills' => $bills]);
    }

    /**
     * GET /api/v1/bills/{bill_id} — B4 bill review read model.
     *
     * The household-scoped aggregate view the workspace renders: current
     * whitelisted values, per-field provenance (source + provider-stated
     * confidence, or null where none was stated), the extraction record's
     * document-level facts, deterministic validation outcomes (expected
     * vs extracted, verbatim — a CONFLICT is displayed as persisted,
     * never corrected here) and the controlled quality flags. Foreign or
     * unknown ids are the established indistinguishable 404.
     */
    public function show(Request $request): Response
    {
        $account = Authorization::requireAccount($request);

        $billId = $this->extractBillId($request->path(), requireAction: false);

        $bill = $billId === null ? null : $this->billService->get($account, $billId);

        if ($bill === null) {
            throw new AppException('NOT_FOUND', 'The requested bill was not found.', 404);
        }

        $draft = $this->billService->draftFor($account, $bill->id());

        $validations = array_map(
            static fn (BillValidation $v): array => [
                'rule' => $v->rule(),
                'status' => $v->status(),
                'expected_value' => $v->expectedValue(),
                'extracted_value' => $v->extractedValue(),
                'reason_code' => $v->reasonCode(),
            ],
            $this->billService->validationsFor($account, $bill->id())
        );

        $flags = array_map(
            static fn (BillQualityFlag $f): array => [
                'code' => $f->code(),
                'field_name' => $f->fieldName(),
            ],
            $this->billService->qualityFlagsFor($account, $bill->id())
        );

        return Response::success([
            'bill' => [
                'bill_id' => $bill->id(),
                'status' => $bill->status(),
                'verified_at' => $bill->verifiedAt(),
                'created_at' => $bill->createdAt(),
                'updated_at' => $bill->updatedAt(),
                'values' => $bill->values(),
                'provenance' => $this->billService->provenanceFor($account, $bill->id()),
                'extraction' => $draft === null ? null : [
                    'assessment' => $draft->assessment(),
                    'assessment_reason' => $draft->assessmentReason(),
                    'document_confidence' => $draft->documentConfidence(),
                    'extracted_at' => $draft->extractedAt(),
                ],
                'validations' => $validations,
                'quality_flags' => $flags,
            ],
        ]);
    }

    /**
     * Route-parameter extraction follows the ratified F6 pattern
     * (controller reads the id from the path; the router contract stays
     * [class, action]). Only an exact 36-char UUID segment matches.
     */
    private function extractBillId(string $path, bool $requireAction = true): ?string
    {
        $pattern = $requireAction
            ? '#^/api/v1/bills/([0-9a-fA-F][0-9a-fA-F-]{34}[0-9a-fA-F])/process$#'
            : '#^/api/v1/bills/([0-9a-fA-F][0-9a-fA-F-]{34}[0-9a-fA-F])$#';

        if (preg_match($pattern, $path, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
