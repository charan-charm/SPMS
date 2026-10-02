<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Providers;

use App\Support\Config;

/**
 * OpenRouter AI Provider implementation (OpenAI-compatible multi-model routing).
 */
final class OpenRouterProvider implements AIProviderInterface
{
    public const DEFAULT_MODEL = 'meta-llama/llama-3.3-70b-instruct';

    private ?string $apiKey;
    private string $model;
    private int $timeoutSeconds;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        int $timeoutSeconds = 12
    ) {
        $key = $apiKey ?? Config::get('OPENROUTER_API_KEY');
        $this->apiKey = ($key !== null && $key !== '' && $key !== '...') ? $key : null;

        $m = $model ?? Config::get('OPENROUTER_MODEL');
        $this->model = ($m !== null && $m !== '' && $m !== '...') ? $m : self::DEFAULT_MODEL;

        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null;
    }

    public function generate(array $messages, array $options = []): AssistantProviderResponse
    {
        if (!$this->isConfigured()) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_NOT_CONFIGURED,
                'OpenRouter API key is not configured.'
            );
        }

        $formattedMessages = [];
        foreach ($messages as $msg) {
            $formattedMessages[] = [
                'role' => (string) ($msg['role'] ?? 'user'),
                'content' => (string) ($msg['content'] ?? ''),
            ];
        }

        $payload = [
            'model' => $this->model,
            'messages' => $formattedMessages,
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens' => (int) ($options['max_tokens'] ?? 1024),
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: http://localhost:8080',
                'X-Title: SPMS Energy Assistant',
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        if ($rawResponse === false || $curlErrno !== 0) {
            $isTimeout = $curlErrno === CURLE_OPERATION_TIMEDOUT;
            throw new AssistantProviderException(
                $this->name(),
                $isTimeout ? AssistantProviderException::ERROR_TIMEOUT : AssistantProviderException::ERROR_PROVIDER_UNAVAILABLE,
                sprintf('Curl error (%d): %s', $curlErrno, $curlError),
                504
            );
        }

        if ($httpCode === 429) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_RATE_LIMITED,
                'OpenRouter API rate limit exceeded.',
                429
            );
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_AUTH_FAILED,
                'OpenRouter API authentication failed. Verify OPENROUTER_API_KEY.',
                401
            );
        }

        if ($httpCode >= 500) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_PROVIDER_UNAVAILABLE,
                sprintf('OpenRouter API server error (HTTP %d).', $httpCode),
                $httpCode
            );
        }

        if ($httpCode !== 200) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_PROVIDER_UNAVAILABLE,
                sprintf('OpenRouter API returned unexpected status %d.', $httpCode),
                $httpCode
            );
        }

        try {
            $data = json_decode((string) $rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_MALFORMED_RESPONSE,
                'Failed to parse OpenRouter JSON response.',
                500,
                $e
            );
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_MALFORMED_RESPONSE,
                'OpenRouter returned empty or missing completion content.',
                500
            );
        }

        $promptTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($data['usage']['completion_tokens'] ?? 0);

        return new AssistantProviderResponse(
            content: trim($content),
            provider: $this->name(),
            model: $this->model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens
        );
    }
}
