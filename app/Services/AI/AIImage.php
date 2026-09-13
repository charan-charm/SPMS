<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AI foundation (docs/10 D-066) — explicit image content.
 *
 * An image is a MIME type plus raw bytes, validated at construction.
 * Files are never persisted here, URLs are never accepted (the model
 * must not fetch anything), and MIME types are declared by the caller —
 * never fabricated or sniffed into something else.
 */
final class AIImage
{
    /** Development bound for this foundation; bill-module work may re-ratify it. */
    public const MAX_BYTES = 5_242_880; // 5 MiB

    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const BEDROCK_FORMATS = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
    ];

    private function __construct(
        private readonly string $mimeType,
        private readonly string $bytes
    ) {
    }

    public static function fromRawBytes(string $mimeType, string $bytes): self
    {
        $normalized = strtolower(trim($mimeType));

        if ($normalized === '' || !isset(self::BEDROCK_FORMATS[$normalized])) {
            throw AIException::invalidRequest(
                'image mime type must be one of: ' . implode(', ', self::ALLOWED_MIME_TYPES)
            );
        }

        if ($bytes === '') {
            throw AIException::invalidRequest('image content must not be empty.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw AIException::invalidRequest('image content exceeds the maximum of ' . self::MAX_BYTES . ' bytes.');
        }

        return new self($normalized, $bytes);
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function byteLength(): int
    {
        return strlen($this->bytes);
    }

    /** Converse image format token — mapped from the declared MIME type only. */
    public function bedrockFormat(): string
    {
        return self::BEDROCK_FORMATS[$this->mimeType];
    }
}
