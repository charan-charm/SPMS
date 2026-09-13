<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;
use Throwable;

/**
 * AI foundation (docs/10 D-066) — the ONLY exception type application
 * code may catch from an AI provider.
 *
 * Messages are fixed, safe strings chosen by this layer: the underlying
 * AWS/Bedrock failure is preserved as the previous exception for
 * server-side diagnostics only, never interpolated into the public
 * message. That structure makes it impossible for credential material,
 * authorization headers or raw provider payloads to leak through an
 * exception message into logs or responses.
 */
final class AIException extends RuntimeException
{
    private function __construct(
        public readonly string $category,
        public readonly string $providerCode,
        string $message,
        private readonly ?Throwable $underlying = null
    ) {
        parent::__construct($message);
    }

    public static function configuration(string $message = 'AI provider is not configured correctly.'): self
    {
        return new self(AIErrorCategory::CONFIGURATION, 'AI_CONFIGURATION', $message);
    }

    public static function realCallsDisabled(): self
    {
        return new self(
            AIErrorCategory::CONFIGURATION,
            'AI_REAL_CALLS_DISABLED',
            'Bedrock real calls are disabled: BEDROCK_ALLOW_REAL_CALLS=1 is required (never set in automated contexts).'
        );
    }

    public static function authentication(?Throwable $underlying = null): self
    {
        return new self(AIErrorCategory::AUTHENTICATION, 'AI_AUTHENTICATION', 'AI provider authentication or authorization failed.', $underlying);
    }

    public static function modelUnavailable(?Throwable $underlying = null): self
    {
        return new self(AIErrorCategory::MODEL_UNAVAILABLE, 'AI_MODEL_UNAVAILABLE', 'The requested AI model is not available in the configured region.', $underlying);
    }

    public static function throttling(?Throwable $underlying = null): self
    {
        return new self(AIErrorCategory::THROTTLING, 'AI_THROTTLED', 'AI provider rate limit reached; retry later.', $underlying);
    }

    public static function timeout(?Throwable $underlying = null): self
    {
        return new self(AIErrorCategory::TIMEOUT, 'AI_TIMEOUT', 'AI provider request timed out or could not reach the service.', $underlying);
    }

    public static function invalidRequest(string $message = 'AI request is invalid.', ?Throwable $underlying = null): self
    {
        return new self(AIErrorCategory::INVALID_REQUEST, 'AI_INVALID_REQUEST', $message, $underlying);
    }

    public static function providerFailure(?Throwable $underlying = null): self
    {
        return new self(AIErrorCategory::PROVIDER_FAILURE, 'AI_PROVIDER_FAILURE', 'AI provider failed to complete the request.', $underlying);
    }

    /**
     * Construct with a category derived from the normalized error
     * mapper (used on the AWS failure path).
     */
    public static function fromCategory(string $category, ?Throwable $underlying = null): self
    {
        return match ($category) {
            AIErrorCategory::AUTHENTICATION => self::authentication($underlying),
            AIErrorCategory::MODEL_UNAVAILABLE => self::modelUnavailable($underlying),
            AIErrorCategory::THROTTLING => self::throttling($underlying),
            AIErrorCategory::TIMEOUT => self::timeout($underlying),
            AIErrorCategory::INVALID_REQUEST => self::invalidRequest('AI request was rejected by the provider.', $underlying),
            AIErrorCategory::CONFIGURATION => self::configuration(),
            default => self::providerFailure($underlying),
        };
    }

    public function category(): string
    {
        return $this->category;
    }

    /** Stable machine code (AI_*), safe for logs. */
    public function providerCode(): string
    {
        return $this->providerCode;
    }

    public function underlying(): ?Throwable
    {
        return $this->underlying;
    }
}
