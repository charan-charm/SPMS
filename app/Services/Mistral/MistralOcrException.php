<?php

declare(strict_types=1);

namespace App\Services\Mistral;

use RuntimeException;
use Throwable;

/**
 * Controlled Mistral OCR integration test support (NOT the Bill
 * Analyzer; NOT wired into the generic AI seam or any business flow).
 *
 * The ONLY exception type crossing this boundary. Like AIException on
 * the Bedrock side, it carries fixed safe messages: the API key,
 * Authorization headers, signed URLs and raw provider payloads never
 * enter its message — only a sanitized mistral error CODE, a bounded
 * printable-ASCII excerpt of the provider message, the HTTP status
 * and a normalized category. The raw Throwable is retained as the
 * previous exception for server-side diagnostics only.
 */
final class MistralOcrException extends RuntimeException
{
    public const CONFIGURATION = 'configuration';
    public const AUTHENTICATION = 'authentication';
    public const RATE_LIMIT = 'rate_limit';
    public const NETWORK = 'network';
    public const INVALID_REQUEST = 'invalid_request';
    public const PROVIDER_FAILURE = 'provider_failure';

    public const CATEGORIES = [
        self::CONFIGURATION,
        self::AUTHENTICATION,
        self::RATE_LIMIT,
        self::NETWORK,
        self::INVALID_REQUEST,
        self::PROVIDER_FAILURE,
    ];

    private function __construct(
        public readonly string $category,
        public readonly ?int $status,
        public readonly ?string $mistralCode,
        public readonly ?string $safeMessage,
        ?Throwable $underlying = null
    ) {
        parent::__construct($safeMessage ?? 'Mistral OCR request failed.', 0, $underlying);
    }

    public static function configuration(string $message = 'Mistral OCR is not configured correctly.'): self
    {
        return new self(self::CONFIGURATION, null, null, $message);
    }

    public static function invalidRequest(string $message = 'Input rejected before any network call.'): self
    {
        return new self(self::INVALID_REQUEST, null, null, $message);
    }

    public static function network(?Throwable $underlying = null): self
    {
        return new self(self::NETWORK, null, null, 'Mistral API could not be reached (network/transport failure).', $underlying);
    }

    /**
     * From an HTTP error: everything unsuitable (missing, non-scalar,
     * oversized, control characters, embedded tokens) is dropped.
     *
     * @param array<string, mixed> $body decoded JSON error body or []
     */
    public static function fromHttpStatus(int $status, array $body, ?Throwable $underlying = null): self
    {
        $code = self::sanitizeCode($body);
        $message = self::sanitizeMessage($body);

        $category = match (true) {
            $status === 401 || $status === 403 => self::AUTHENTICATION,
            $status === 429 => self::RATE_LIMIT,
            $status === 400 || $status === 422 => self::INVALID_REQUEST,
            $status >= 500 => self::PROVIDER_FAILURE,
            default => self::PROVIDER_FAILURE,
        };

        $text = 'Mistral OCR request failed (HTTP ' . $status
            . ($code !== null ? ' ' . $code : '') . ')'
            . ($message !== null ? ': ' . $message : '.');

        return new self($category, $status, $code, $text, $underlying);
    }

    public function category(): string
    {
        return $this->category;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function mistralCode(): ?string
    {
        return $this->mistralCode;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function sanitizeCode(array $body): ?string
    {
        $raw = null;
        foreach ([['error', 'type'], ['error', 'code'], ['code'], ['type'], ['detail', 0, 'type']] as $path) {
            $cursor = $body;
            foreach ($path as $key) {
                if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if (is_string($cursor) && $cursor !== '') {
                $raw = $cursor;
                break;
            }
        }

        if ($raw === null || preg_match('/^[A-Za-z][A-Za-z0-9_.\-]{0,63}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function sanitizeMessage(array $body): ?string
    {
        $raw = null;
        foreach (['message', 'error', 'detail'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $raw = $value;
                break;
            }
            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                $raw = $value[0];
                break;
            }
            if (is_array($value) && isset($value[0]['msg']) && is_string($value[0]['msg'])) {
                $raw = $value[0]['msg'];
                break;
            }
            if (is_array($value) && isset($value['message']) && is_string($value['message'])) {
                $raw = $value['message'];
                break;
            }
        }

        if ($raw === null) {
            return null;
        }

        // Printable ASCII only, whitespace collapsed, hard 200-char
        // bound; anything bearer/token-looking is stripped defensively.
        $clean = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $raw) ?? '';
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $clean) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        if ($clean === '' || preg_match('/^[\x20-\x7E]+$/', $clean) !== 1) {
            return null;
        }

        return substr($clean, 0, 200);
    }
}
