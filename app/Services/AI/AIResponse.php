<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AI foundation (docs/10 D-066) — normalized provider response.
 *
 * Raw AWS SDK result objects never leave the adapter: everything the
 * application sees is this value object. `structuredOutput` is the
 * parsed JSON object when the request asked for structured output and
 * the provider delivered one; otherwise null. Nothing here is stored
 * automatically — persistence is a future, per-feature decision.
 */
final class AIResponse
{
    /**
     * @param array<string, int>|null $usage normalized token-usage counters (safe to log)
     * @param array<mixed>|null $structuredOutput
     */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?array $usage = null,
        public readonly ?string $stopReason = null,
        public readonly ?string $correlationId = null,
        public readonly ?array $structuredOutput = null
    ) {
    }

    public function text(): string
    {
        return $this->text;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** @return array<string, int>|null */
    public function usage(): ?array
    {
        return $this->usage;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /** @return array<mixed>|null */
    public function structuredOutput(): ?array
    {
        return $this->structuredOutput;
    }

    /**
     * Structured-output preparation: when the model answered through the
     * tool-use channel, expose the decoded payload as text too — one
     * predictable accessor for callers.
     */
    public function textOrStructuredJson(): string
    {
        if ($this->text !== '') {
            return $this->text;
        }

        if ($this->structuredOutput !== null) {
            return (string) json_encode($this->structuredOutput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return '';
    }
}
