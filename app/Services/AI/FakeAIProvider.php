<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AI foundation (docs/10 D-066) — deterministic test provider.
 *
 * Runs with zero network access, produces stable responses derived from
 * the request content (never from bytes of images — only their declared
 * MIME types and count, so prompts/images never need to be stored or
 * logged), records every request for assertions, and supports
 * configurable failure simulation for error-path testing.
 */
final class FakeAIProvider implements AIProviderInterface
{
    public const MODEL = 'fake-model-v1';

    /** @var AIRequest[] */
    private array $requests = [];

    /** @var AIException|null thrown by every complete() while set */
    private ?AIException $configuredFailure = null;

    private string $fixedResponse;

    public function __construct(string $fixedResponse = 'fake: deterministic provider response')
    {
        $this->fixedResponse = $fixedResponse;
    }

    /** Failure simulation for tests (e.g. new AIException via factories). */
    public function failWith(?AIException $exception): void
    {
        $this->configuredFailure = $exception;
    }

    public function complete(AIRequest $request): AIResponse
    {
        $this->requests[] = $request;

        if ($this->configuredFailure !== null) {
            throw $this->configuredFailure;
        }

        $text = $this->fixedResponse;

        $structured = null;
        if ($request->wantsStructuredOutput()) {
            // Deterministic placeholder object shaped only by the
            // request — proves the channel without inventing semantics.
            $structured = ['ok' => true, 'echo' => $request->prompt()];
        }

        return new AIResponse(
            text: $text,
            provider: $this->name(),
            model: self::MODEL,
            usage: [
                'input_tokens' => max(1, intdiv(strlen($request->prompt()), 4)),
                'output_tokens' => max(1, intdiv(strlen($text), 4)),
                'total_tokens' => max(2, intdiv(strlen($request->prompt()) + strlen($text), 4)),
            ],
            stopReason: 'end_turn',
            correlationId: $request->correlationId(),
            structuredOutput: $structured
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    /** @return AIRequest[] */
    public function recordedRequests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?AIRequest
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    public function callCount(): int
    {
        return count($this->requests);
    }
}
