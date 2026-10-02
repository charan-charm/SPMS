<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Providers;

/**
 * Normalized response emitted by an AI Provider in the SPMS assistant gateway.
 */
final class AssistantProviderResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0
    ) {
    }
}
