<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Providers;

use RuntimeException;
use Throwable;

/**
 * Structured runtime exception emitted on AI provider failure.
 */
final class AssistantProviderException extends RuntimeException
{
    public const ERROR_TIMEOUT = 'TIMEOUT';
    public const ERROR_RATE_LIMITED = 'RATE_LIMITED';
    public const ERROR_AUTH_FAILED = 'AUTHENTICATION_FAILED';
    public const ERROR_PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';
    public const ERROR_MALFORMED_RESPONSE = 'MALFORMED_RESPONSE';
    public const ERROR_NOT_CONFIGURED = 'NOT_CONFIGURED';
    public const ERROR_ALL_PROVIDERS_UNAVAILABLE = 'ALL_PROVIDERS_UNAVAILABLE';

    public function __construct(
        public readonly string $provider,
        public readonly string $errorCode,
        string $message,
        int $statusCode = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct(sprintf('[%s:%s] %s', $provider, $errorCode, $message), $statusCode, $previous);
    }

    public static function timeout(string $provider, int $seconds = 15): self
    {
        return new self($provider, self::ERROR_TIMEOUT, sprintf('Provider timed out after %ds', $seconds), 504);
    }

    public static function rateLimited(string $provider, string $message = 'Rate limit exceeded'): self
    {
        return new self($provider, self::ERROR_RATE_LIMITED, $message, 429);
    }

    public static function providerUnavailable(string $provider, string $message = 'Provider unavailable'): self
    {
        return new self($provider, self::ERROR_PROVIDER_UNAVAILABLE, $message, 503);
    }

    public static function authFailed(string $provider, string $message = 'Authentication failed'): self
    {
        return new self($provider, self::ERROR_AUTH_FAILED, $message, 401);
    }

    public static function malformedResponse(string $provider, string $message = 'Malformed response'): self
    {
        return new self($provider, self::ERROR_MALFORMED_RESPONSE, $message, 502);
    }

    public static function notConfigured(string $provider): self
    {
        return new self($provider, self::ERROR_NOT_CONFIGURED, 'Provider credentials not configured', 500);
    }

    public static function allFailed(string $message = 'All configured AI providers failed'): self
    {
        return new self('gateway', self::ERROR_ALL_PROVIDERS_UNAVAILABLE, $message, 503);
    }
}
