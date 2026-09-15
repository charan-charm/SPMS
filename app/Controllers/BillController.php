<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Bill\BillProcessingService;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
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
     * Route-parameter extraction follows the ratified F6 pattern
     * (controller reads the id from the path; the router contract stays
     * [class, action]). Only an exact 36-char UUID segment matches.
     */
    private function extractBillId(string $path): ?string
    {
        if (preg_match('#^/api/v1/bills/([0-9a-fA-F][0-9a-fA-F-]{34}[0-9a-fA-F])/process$#', $path, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
