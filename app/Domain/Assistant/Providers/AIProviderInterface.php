<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Providers;

/**
 * Common contract for all AI providers in the SPMS Assistant gateway.
 */
interface AIProviderInterface
{
    /**
     * Unique provider identifier (e.g. 'gemini', 'groq', 'openrouter', 'fake').
     */
    public function name(): string;

    /**
     * Active model identifier.
     */
    public function model(): string;

    /**
     * Whether the provider has credentials and is configured for execution.
     */
    public function isConfigured(): bool;

    /**
     * Generate completion for formatted messages.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @throws AssistantProviderException on provider, network, or rate-limit failure
     */
    public function generate(array $messages, array $options = []): AssistantProviderResponse;
}
