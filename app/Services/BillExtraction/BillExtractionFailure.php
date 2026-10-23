<?php

declare(strict_types=1);

namespace App\Services\BillExtraction;

use RuntimeException;
use Throwable;

/**
 * Bill document extraction provider failure.
 *
 * Carries a normalized category so the gateway can decide:
 *   - TRANSIENT: provider-side infrastructure problem; try the next provider.
 *   - TERMINAL:  a problem with the REQUEST or CREDENTIALS that makes
 *                trying another provider for the same reason wrong (invalid
 *                document type, authentication failure).  Stop immediately.
 *
 * Messages are fixed safe strings. The underlying provider exception may
 * be attached as `previous` for server-side diagnostics only — it MUST
 * NOT contain API keys, Authorization headers, or raw provider payloads
 * in a form that would reach logs or API responses.
 */
final class BillExtractionFailure extends RuntimeException
{
    /** Transient infrastructure failure: rate-limit, timeout, 5xx, network. */
    public const TRANSIENT = 'transient';

    /** Terminal: bad key, auth failure — do not try another provider. */
    public const TERMINAL_AUTH = 'terminal_auth';

    /** Terminal: document is invalid/unsupported — provider cannot help. */
    public const TERMINAL_INVALID_DOCUMENT = 'terminal_invalid_document';

    /** Terminal: provider is not configured (missing key). */
    public const TERMINAL_NOT_CONFIGURED = 'terminal_not_configured';

    /** Terminal: provider returned structurally malformed output. */
    public const TERMINAL_MALFORMED_RESPONSE = 'terminal_malformed_response';

    public const ALL_CATEGORIES = [
        self::TRANSIENT,
        self::TERMINAL_AUTH,
        self::TERMINAL_INVALID_DOCUMENT,
        self::TERMINAL_NOT_CONFIGURED,
        self::TERMINAL_MALFORMED_RESPONSE,
    ];

    public function __construct(
        public readonly string $category,
        public readonly string $provider,
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isTransient(): bool
    {
        return $this->category === self::TRANSIENT;
    }

    public static function transient(string $provider, string $message, ?int $httpStatus = null, ?Throwable $previous = null): self
    {
        return new self(self::TRANSIENT, $provider, $message, $httpStatus, $previous);
    }

    public static function authFailed(string $provider, ?int $httpStatus = null): self
    {
        return new self(self::TERMINAL_AUTH, $provider, 'Authentication failed for bill extraction provider.', $httpStatus);
    }

    public static function notConfigured(string $provider): self
    {
        return new self(self::TERMINAL_NOT_CONFIGURED, $provider, 'Bill extraction provider is not configured (API key missing).', null);
    }

    public static function invalidDocument(string $provider, string $reason): self
    {
        return new self(self::TERMINAL_INVALID_DOCUMENT, $provider, $reason, null);
    }

    public static function malformedResponse(string $provider, string $reason): self
    {
        return new self(self::TERMINAL_MALFORMED_RESPONSE, $provider, $reason, null);
    }
}
