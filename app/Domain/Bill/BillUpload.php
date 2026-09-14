<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use App\Support\AppException;
use ValueError;

/**
 * B2 — The validated upload document boundary (docs/10 D-068 §3-§5, §19).
 *
 * A BillUpload exists ONLY after the raw multipart part has survived
 * every fail-closed check; once constructed, it carries just the three
 * facts any later layer may use: a server-side source path, the
 * SIGNATURE-DERIVED canonical type, and the ACTUAL byte size. The
 * client-supplied filename, declared Content-Type and declared size are
 * examined for routing information at most and then discarded — this
 * object does not even keep them, so no downstream code can leak or
 * trust them by accident (§18: intake inspects bytes, never content
 * meaning or identity data).
 *
 * Trust model, strictly fail-closed:
 * - PHP's own upload error codes are mapped first;
 * - the file must exist, be a genuine HTTP upload (injectable seam for
 *   offline tests), be readable, and be within the 25 MiB bound by its
 *   REAL size (stat on disk — never the browser's claim);
 * - the extension is taken from the client name only as a lowercase
 *   character-class tail and must be one of jpg/jpeg/png/pdf — every
 *   other name (including .php/.svg/.exe rebrands, traversal fragments
 *   and control characters) is simply unsupported;
 * - the magic signature must AGREE with the claimed extension:
 *   JPEG FF D8 FF · PNG 89 50 4E 47 0D 0A 1A 0A · PDF 25 50 44 46 2D;
 * - if fileinfo happens to be loaded it adds a cross-check but can only
 *   make the gate stricter — on builds without it (this project's PHP)
 *   the signatures alone carry the requirement, mirroring the ratified
 *   Mistral diagnostic's approach.
 *
 * B2 never OCRs, never renders, never sanitizes the bytes: §20.
 */
final class BillUpload
{
    public const MAX_BILL_UPLOAD_BYTES = 25 * 1024 * 1024;

    public const TYPE_JPEG = 'image/jpeg';
    public const TYPE_PNG = 'image/png';
    public const TYPE_PDF = 'application/pdf';

    /** Controlled extension → canonical type mapping (§19). */
    private const EXTENSION_MAP = [
        'jpg' => self::TYPE_JPEG,
        'jpeg' => self::TYPE_JPEG,
        'png' => self::TYPE_PNG,
        'pdf' => self::TYPE_PDF,
    ];

    /** Canonical type → the one safe extension storage ever uses. */
    private const CANONICAL_EXTENSION = [
        self::TYPE_JPEG => 'jpg',
        self::TYPE_PNG => 'png',
        self::TYPE_PDF => 'pdf',
    ];

    private const SIGNATURES = [
        self::TYPE_JPEG => "\xFF\xD8\xFF",
        self::TYPE_PNG => "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A",
        self::TYPE_PDF => "\x25\x50\x44\x46\x2D",
    ];

    /** Upload error codes meaning "too big" vs every other failure. */
    private const TOO_LARGE_CODES = [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE];

    private function __construct(
        private readonly string $sourcePath,
        private readonly string $mimeType,
        private readonly int $sizeBytes
    ) {
    }

    /**
     * @param array<string, mixed> $spec one $_FILES entry, untrusted by definition
     * @param callable|null $uploadVerifier production defaults to is_uploaded_file();
     *                                      offline tests verify synthetic temp files instead
     * @param int|null $maxBytes production default is the ratified 25 MiB constant;
     *                           smaller test bounds are injection only, never client data
     */
    public static function fromFilesSpec(
        array $spec,
        ?callable $uploadVerifier = null,
        ?int $maxBytes = null
    ): self {
        $maxBytes ??= self::MAX_BILL_UPLOAD_BYTES;

        // 1. Structural shape.
        $error = $spec['error'] ?? null;
        $tmpName = $spec['tmp_name'] ?? null;
        $clientName = $spec['name'] ?? null;

        if (!is_int($error) || !is_string($tmpName) || !is_string($clientName) || !isset($spec['size'])) {
            throw self::rejected('INVALID_UPLOAD', 'The upload part is malformed.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw self::rejected(
                in_array($error, self::TOO_LARGE_CODES, true) ? 'FILE_TOO_LARGE' : 'INVALID_UPLOAD',
                in_array($error, self::TOO_LARGE_CODES, true)
                    ? 'The uploaded file exceeds the maximum allowed size.'
                    : 'The upload did not complete. Please try again.'
            );
        }

        // 2. Extension gate on a strictly lowercase alphanumeric tail —
        //    names with separators, traversal, NUL or control characters
        //    cannot present such a tail for anything but the four allowed
        //    extensions, and even when they do, NOTHING here is used as a
        //    path: only this classification consumes the name at all.
        if (str_contains($clientName, "\0") || preg_match('/\.([a-z0-9]{1,5})$/', strtolower($clientName), $tail) !== 1) {
            throw self::rejected('UNSUPPORTED_FILE_TYPE', 'The uploaded file has no recognizable safe extension.');
        }

        $mimeType = self::EXTENSION_MAP[$tail[1]] ?? null;

        if ($mimeType === null) {
            throw self::rejected(
                'UNSUPPORTED_FILE_TYPE',
                'Unsupported file type. Allowed: jpg, jpeg, png, pdf.'
            );
        }

        // 3. Real existence / authenticity / readability of the temp file.
        if ($tmpName === '' || str_contains($tmpName, "\0")) {
            throw self::rejected('INVALID_UPLOAD', 'The upload part is malformed.');
        }

        $verifier = $uploadVerifier ?? static fn (string $path): bool => is_uploaded_file($path);

        if (!$verifier($tmpName) || !is_file($tmpName) || !is_readable($tmpName)) {
            throw self::rejected('UPLOAD_FAILED', 'The uploaded file could not be read on the server.', 500);
        }

        // 4. ACTUAL size discipline (the browser's number is ignored).
        $size = filesize($tmpName);

        if ($size === false) {
            throw self::rejected('UPLOAD_FAILED', 'The uploaded file could not be read on the server.', 500);
        }

        if ($size === 0) {
            throw self::rejected('EMPTY_FILE', 'The uploaded file is empty.');
        }

        if ($size > $maxBytes) {
            throw self::rejected('FILE_TOO_LARGE', 'The uploaded file exceeds the maximum allowed size.');
        }

        // 5. Magic signature must agree with the claimed extension.
        $handle = fopen($tmpName, 'rb');

        if ($handle === false) {
            throw self::rejected('UPLOAD_FAILED', 'The uploaded file could not be read on the server.', 500);
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        if (!str_starts_with($head, self::SIGNATURES[$mimeType])) {
            throw self::rejected(
                'INVALID_FILE_SIGNATURE',
                'The uploaded file content does not match its claimed type.'
            );
        }

        // 6. fileinfo (when present on this build) may only tighten the
        //    gate — never weaken the signature requirement above.
        if (class_exists(\finfo::class)) {
            try {
                $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
            } catch (ValueError) {
                $detected = false;
            }

            if ($detected !== $mimeType) {
                throw self::rejected(
                    'INVALID_FILE_SIGNATURE',
                    'The uploaded file content does not match its claimed type.'
                );
            }
        }

        return new self($tmpName, $mimeType, $size);
    }

    public static function canonicalExtension(string $mimeType): string
    {
        return self::CANONICAL_EXTENSION[$mimeType]
            ?? throw self::rejected('INVALID_UPLOAD', 'Unknown canonical upload type.');
    }

    public static function isCanonicalType(string $mimeType): bool
    {
        return isset(self::CANONICAL_EXTENSION[$mimeType]);
    }

    /** Internal use only: server-side source path (never surfaced). */
    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    /**
     * Read the validated bytes for private storage. Large-file safety:
     * the size bound was enforced before this point, so the read is
     * bounded by it.
     */
    public function bytes(): string
    {
        $contents = @file_get_contents($this->sourcePath);

        if ($contents === false || strlen($contents) !== $this->sizeBytes) {
            throw self::rejected('UPLOAD_FAILED', 'The uploaded file could not be read on the server.', 500);
        }

        return $contents;
    }

    private static function rejected(string $code, string $message, int $status = 422): AppException
    {
        return new AppException($code, $message, $status);
    }
}
