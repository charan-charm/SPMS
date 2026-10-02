<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Providers;

use App\Support\Config;

/**
 * Google Gemini API Provider implementation.
 */
final class GeminiProvider implements AIProviderInterface
{
    public const DEFAULT_MODEL = 'gemini-1.5-flash';

    private ?string $apiKey;
    private string $model;
    private int $timeoutSeconds;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        int $timeoutSeconds = 12
    ) {
        $key = $apiKey ?? Config::get('GEMINI_API_KEY');
        $this->apiKey = ($key !== null && $key !== '' && $key !== '...') ? $key : null;

        $m = $model ?? Config::get('GEMINI_MODEL');
        $this->model = ($m !== null && $m !== '' && $m !== '...') ? $m : self::DEFAULT_MODEL;

        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function name(): string
    {
        return 'gemini';
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
                'Gemini API key is not configured.'
            );
        }

        $systemInstructionText = null;
        $contents = [];

        foreach ($messages as $msg) {
            $role = strtolower((string) ($msg['role'] ?? 'user'));
            $text = (string) ($msg['content'] ?? '');

            if ($role === 'system') {
                $systemInstructionText = $systemInstructionText === null ? $text : ($systemInstructionText . "\n\n" . $text);
                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $text]],
            ];
        }

        if ($contents === []) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Hello']],
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($options['temperature'] ?? 0.2),
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? 1024),
            ],
        ];

        if ($systemInstructionText !== null) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstructionText]],
            ];
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            urlencode($this->model),
            urlencode((string) $this->apiKey)
        );

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
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
                'Gemini API rate limit exceeded.',
                429
            );
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_AUTH_FAILED,
                'Gemini API authentication failed. Verify GEMINI_API_KEY.',
                401
            );
        }

        if ($httpCode >= 500) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_PROVIDER_UNAVAILABLE,
                sprintf('Gemini API server error (HTTP %d).', $httpCode),
                $httpCode
            );
        }

        if ($httpCode !== 200) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_PROVIDER_UNAVAILABLE,
                sprintf('Gemini API returned unexpected status %d.', $httpCode),
                $httpCode
            );
        }

        try {
            $data = json_decode((string) $rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_MALFORMED_RESPONSE,
                'Failed to parse Gemini JSON response.',
                500,
                $e
            );
        }

        $candidateText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($candidateText) || trim($candidateText) === '') {
            throw new AssistantProviderException(
                $this->name(),
                AssistantProviderException::ERROR_MALFORMED_RESPONSE,
                'Gemini returned empty or missing candidate text.',
                500
            );
        }

        $promptTokens = (int) ($data['usageMetadata']['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0);

        return new AssistantProviderResponse(
            content: trim($candidateText),
            provider: $this->name(),
            model: $this->model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens
        );
    }
}
