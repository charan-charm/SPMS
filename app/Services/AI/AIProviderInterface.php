<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AI foundation (docs/10 D-066) — the seam every AI consumer depends on.
 *
 * Business/application services may type-hint ONLY this interface.
 * No AWS SDK class crosses it. Implementations: FakeAIProvider
 * (deterministic, offline — the test/default provider) and
 * BedrockAIProvider (Amazon Bedrock Runtime, Converse API, real calls
 * only when the explicit guard flag is enabled).
 */
interface AIProviderInterface
{
    /**
     * Execute one completion. Throws AIException — never a raw SDK
     * exception — with a normalized category.
     */
    public function complete(AIRequest $request): AIResponse;

    /** Provider identity for logs/diagnostics ("fake" / "bedrock"). */
    public function name(): string;
}
