<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Providers;

/**
 * Deterministic test/mock AI provider for unit and integration testing.
 */
final class FakeAIProvider implements AIProviderInterface
{
    private string $name;
    private string $model;
    private bool $configured;
    private ?string $responseContent;
    private ?AssistantProviderException $failureException;

    /** @var list<array{messages: list<array<string, mixed>>, options: array<string, mixed>}> */
    public array $recordedCalls = [];

    public function __construct(
        string $name = 'fake',
        string $model = 'mock-model-v1',
        bool $configured = true,
        ?string $responseContent = null,
        ?AssistantProviderException $failureException = null
    ) {
        $this->name = $name;
        $this->model = $model;
        $this->configured = $configured;
        $this->responseContent = $responseContent;
        $this->failureException = $failureException;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function setResponseContent(string $content): void
    {
        $this->responseContent = $content;
    }

    public function setFailure(?AssistantProviderException $exception): void
    {
        $this->failureException = $exception;
    }

    public function generate(array $messages, array $options = []): AssistantProviderResponse
    {
        $this->recordedCalls[] = [
            'messages' => $messages,
            'options' => $options,
        ];

        if ($this->failureException !== null) {
            throw $this->failureException;
        }

        if (!$this->configured) {
            throw new AssistantProviderException(
                $this->name,
                AssistantProviderException::ERROR_NOT_CONFIGURED,
                'Provider is not configured.'
            );
        }

        $content = $this->responseContent ?? 'SPMS Energy Assistant response based on validated facts.';

        return new AssistantProviderResponse(
            content: $content,
            provider: $this->name,
            model: $this->model,
            promptTokens: 150,
            completionTokens: 50
        );
    }
}
