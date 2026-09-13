<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AI foundation (docs/10 D-066) — normalized, provider-independent
 * error categories.
 *
 * Raw AWS/Bedrock exceptions NEVER cross the provider boundary; they
 * are translated into one of these categories via an App\Services\AI\
 * AIException. Callers switch on the category, never on AWS SDK types.
 */
final class AIErrorCategory
{
    public const CONFIGURATION = 'configuration';
    public const AUTHENTICATION = 'authentication';
    public const MODEL_UNAVAILABLE = 'model_unavailable';
    public const THROTTLING = 'throttling';
    public const TIMEOUT = 'timeout';
    public const INVALID_REQUEST = 'invalid_request';
    public const PROVIDER_FAILURE = 'provider_failure';

    public const ALL = [
        self::CONFIGURATION,
        self::AUTHENTICATION,
        self::MODEL_UNAVAILABLE,
        self::THROTTLING,
        self::TIMEOUT,
        self::INVALID_REQUEST,
        self::PROVIDER_FAILURE,
    ];

    private function __construct()
    {
    }

    public static function isCategory(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }
}
