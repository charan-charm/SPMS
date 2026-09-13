<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AI foundation (docs/10 D-066) — normalized provider request.
 *
 * Supports text-only (A) and text+image (B) invocations through one
 * explicit, immutable value object. It is provider-neutral: no AWS/
 * Converse concepts appear here. Structured output is represented by
 * a generic flag + schema only, so a future Bill Analyzer can request
 * schema-constrained JSON without this seam being redesigned — but no
 * business schema lives in the provider itself.
 */
final class AIRequest
{
    private const DEFAULT_MAX_TOKENS = 256;
    private const DEFAULT_TEMPERATURE = 0.0;
    private const MAX_TOKENS_CEILING = 4096;

    /** @var AIImage[] */
    private array $images;

    /**
     * @param AIImage[] $images
     * @param array<mixed>|null $structuredSchema
     */
    private function __construct(
        private readonly string $prompt,
        array $images,
        private readonly ?string $systemPrompt,
        private readonly int $maxTokens,
        private readonly float $temperature,
        private readonly bool $wantsStructuredOutput,
        private readonly ?array $structuredSchema,
        private readonly ?string $correlationId,
        private readonly bool $sendInferenceConfig = true
    ) {
        if ($maxTokens < 1 || $maxTokens > self::MAX_TOKENS_CEILING) {
            throw AIException::invalidRequest('maxTokens must be between 1 and ' . self::MAX_TOKENS_CEILING . '.');
        }

        if ($temperature < 0.0 || $temperature > 1.0) {
            throw AIException::invalidRequest('temperature must be between 0.0 and 1.0.');
        }

        $this->images = array_values($images);
    }

    /**
     * A text-only request.
     */
    public static function text(string $prompt, ?string $systemPrompt = null, ?string $correlationId = null): self
    {
        return self::make($prompt, [], $systemPrompt, $correlationId, false, null);
    }

    /**
     * A text + image request. Images are validated at construction.
     */
    public static function textWithImages(string $prompt, array $images, ?string $systemPrompt = null, ?string $correlationId = null): self
    {
        return self::make($prompt, $images, $systemPrompt, $correlationId, false, null);
    }

    /**
     * Structured-output preparation: returns a clone asking the provider
     * for schema-constrained JSON. The schema is carried opaquely — the
     * provider adapts it, no business meaning is interpreted here.
     *
     * @param array<mixed>|null $schema JSON-Schema-shaped array; null = plain JSON requested
     */
    public function withStructuredOutput(?array $schema = null): self
    {
        return new self(
            $this->prompt,
            $this->images,
            $this->systemPrompt,
            $this->maxTokens,
            $this->temperature,
            true,
            $schema,
            $this->correlationId,
            $this->sendInferenceConfig
        );
    }

    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            $this->prompt,
            $this->images,
            $this->systemPrompt,
            $maxTokens,
            $this->temperature,
            $this->wantsStructuredOutput,
            $this->structuredSchema,
            $this->correlationId,
            $this->sendInferenceConfig
        );
    }

    /**
     * Diagnostic shape (docs/10 D-066 validation debugging): omit the
     * ENTIRE inferenceConfig block — every Converse request member
     * except modelId is optional per the installed model. Used to
     * isolate whether provider-side parameter validation is the
     * rejection cause; never implied, always explicit.
     */
    public function withoutInferenceConfig(): self
    {
        return new self(
            $this->prompt,
            $this->images,
            $this->systemPrompt,
            $this->maxTokens,
            $this->temperature,
            $this->wantsStructuredOutput,
            $this->structuredSchema,
            $this->correlationId,
            false
        );
    }

    public function sendsInferenceConfig(): bool
    {
        return $this->sendInferenceConfig;
    }

    /**
     * @param array<int, mixed>|mixed $images
     * @param array<mixed>|null $schema
     */
    private static function make(string $prompt, array $images, ?string $systemPrompt, ?string $correlationId, bool $structured, ?array $schema): self
    {
        if (trim($prompt) === '') {
            throw AIException::invalidRequest('prompt must be a non-empty string.');
        }

        foreach ($images as $image) {
            if (!$image instanceof AIImage) {
                throw AIException::invalidRequest('images must be AIImage instances.');
            }
        }

        return new self(
            $prompt,
            $images,
            $systemPrompt,
            self::DEFAULT_MAX_TOKENS,
            self::DEFAULT_TEMPERATURE,
            $structured,
            $schema,
            $correlationId
        );
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    /** @return AIImage[] */
    public function images(): array
    {
        return $this->images;
    }

    public function hasImages(): bool
    {
        return $this->images !== [];
    }

    public function systemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    public function temperature(): float
    {
        return $this->temperature;
    }

    public function wantsStructuredOutput(): bool
    {
        return $this->wantsStructuredOutput;
    }

    /** @return array<mixed>|null */
    public function structuredSchema(): ?array
    {
        return $this->structuredSchema;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }
}
