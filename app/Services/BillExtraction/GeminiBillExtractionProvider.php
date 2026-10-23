<?php

declare(strict_types=1);

namespace App\Services\BillExtraction;

use App\Support\Config;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;

/**
 * Bill document extraction provider backed by Google Gemini.
 *
 * Uses the Gemini generateContent API with inline image/PDF data (base64)
 * and structured JSON output (`response_mime_type: application/json` +
 * `response_schema`). This is Gemini's documented multimodal path for
 * local document processing — no file upload step required.
 *
 * Endpoint (v1beta):
 *   POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}
 *
 * Inline document part:
 *   { "inlineData": { "mimeType": "<mime>", "data": "<base64>" } }
 *
 * Structured output:
 *   generationConfig.response_mime_type = "application/json"
 *   generationConfig.response_schema    = <JSON Schema>
 *
 * Capability notes:
 *   - gemini-1.5-flash / gemini-1.5-pro / gemini-2.0-flash:  support
 *     image/png, image/jpeg, and application/pdf inline.
 *   - The Gemini API does NOT support Mistral-style document_annotation_format;
 *     instead we use response_schema inside generationConfig.
 *   - Output is a raw JSON string in candidates[0].content.parts[0].text
 *     (when response_mime_type=application/json the text IS the JSON).
 *
 * Security:
 *   - API key comes ONLY from SPMS Config (gitignored .env).
 *   - Key is placed in the URL query string only (Gemini API key convention);
 *     never logged, never returned, never embedded in exception messages.
 *   - Raw provider responses are NEVER stored; only the normalized
 *     annotation array is returned.
 *   - The http handler is injectable for tests (zero real network calls
 *     in the automated suite).
 *
 * Failure mapping:
 *   HTTP 429 → TRANSIENT (rate limit — fall through)
 *   HTTP 503, 5xx → TRANSIENT (temporary provider error — fall through)
 *   Curl timeout → TRANSIENT (network failure — fall through)
 *   HTTP 400 → TERMINAL_INVALID_DOCUMENT (malformed request from our side)
 *   HTTP 401/403 → TERMINAL_AUTH (bad key — stop, do not try more providers)
 *   Malformed JSON response → TERMINAL_MALFORMED_RESPONSE
 */
final class GeminiBillExtractionProvider implements BillDocumentExtractionProviderInterface
{
    public const DEFAULT_MODEL        = 'gemini-3.8-flash';
    public const GEMINI_BASE_URL      = 'https://generativelanguage.googleapis.com';
    public const GENERATE_PATH        = '/v1beta/models/%s:generateContent';
    public const DEFAULT_TIMEOUT      = 120;
    public const MAX_FILE_BYTES       = 20_971_520; // 20 MiB (Gemini inline limit)

    public const SUPPORTED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    private ?string $apiKey;
    private string  $model;
    private int     $timeoutSeconds;
    private string  $baseUrl;

    /** @var (callable(string, string, array<string, mixed>): array{status:int, body:string})|null */
    private $http;

    /**
     * @param (callable(string, string, array<string, mixed>): array{status:int, body:string})|null $http
     */
    public function __construct(
        ?string  $apiKey  = null,
        ?string  $model   = null,
        int      $timeoutSeconds = self::DEFAULT_TIMEOUT,
        ?callable $http   = null,
        string   $baseUrl = self::GEMINI_BASE_URL
    ) {
        $key = $apiKey ?? Config::get('GEMINI_API_KEY');
        $this->apiKey = ($key !== null && trim($key) !== '' && $key !== '...') ? trim($key) : null;

        $m = $model ?? Config::get('GEMINI_MODEL');
        $this->model = ($m !== null && trim($m) !== '' && $m !== '...') ? trim($m) : self::DEFAULT_MODEL;

        $this->timeoutSeconds = $timeoutSeconds;
        $this->http           = $http ?? self::guzzleHandler();
        $this->baseUrl        = rtrim($baseUrl, '/');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null;
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    public function modelId(): ?string
    {
        return $this->model;
    }

    public function supportedMimeTypes(): array
    {
        return self::SUPPORTED_MIME_TYPES;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{annotation: array<mixed>|null, model: string|null, provider: string}
     * @throws BillExtractionFailure
     */
    public function extract(string $filePath, string $mimeType, array $schema, string $prompt): array
    {
        if (!$this->isConfigured()) {
            throw BillExtractionFailure::notConfigured($this->providerName());
        }

        if (!in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            throw BillExtractionFailure::invalidDocument(
                $this->providerName(),
                'Gemini bill extraction does not support MIME type: ' . $mimeType
            );
        }

        // Read + size-check the document BEFORE any network call.
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw BillExtractionFailure::invalidDocument(
                $this->providerName(),
                'Document not found or not readable.'
            );
        }

        $bytes = file_get_contents($filePath);
        if ($bytes === false || $bytes === '') {
            throw BillExtractionFailure::invalidDocument($this->providerName(), 'Document is empty or unreadable.');
        }

        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            throw BillExtractionFailure::invalidDocument(
                $this->providerName(),
                'Document exceeds the Gemini inline size limit (' . self::MAX_FILE_BYTES . ' bytes).'
            );
        }

        $payload = $this->buildPayload($bytes, $mimeType, $schema, $prompt);

        // Candidate models: primary configured model, falling back to gemini-3.5-flash if different
        $modelsToTry = array_values(array_unique(array_filter([
            $this->model,
            'gemini-3.8-flash',
            'gemini-3.5-flash',
        ])));

        $lastException = null;

        foreach ($modelsToTry as $candidateModel) {
            $endpoint = $this->buildEndpoint($candidateModel);

            try {
                $response = ($this->http)('POST', $endpoint, [
                    'json'    => $payload,
                    'timeout' => $this->timeoutSeconds,
                ]);
            } catch (Throwable $e) {
                $lastException = BillExtractionFailure::transient(
                    $this->providerName(),
                    'Gemini API network/transport failure (' . $candidateModel . ').',
                    null,
                    $e
                );
                continue;
            }

            try {
                return $this->parseResponse($response, $candidateModel);
            } catch (BillExtractionFailure $e) {
                if (!$e->isTransient()) {
                    throw $e;
                }
                $lastException = $e;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        throw BillExtractionFailure::transient($this->providerName(), 'All Gemini candidate models failed.');
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Convert standard JSON Schema to Gemini OpenAPI 3.0-compatible schema.
     * Gemini requires:
     * - Uppercase types (OBJECT, STRING, NUMBER, BOOLEAN, ARRAY, INTEGER)
     * - No `additionalProperties`
     * - No union types like `type: ['string', 'null']`; instead use `type: 'STRING'`, `nullable: true`
     * - No `$schema` keyword
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function toGeminiSchema(array $schema): array
    {
        $out = [];
        foreach ($schema as $k => $v) {
            if ($k === '$schema' || $k === 'additionalProperties') {
                continue;
            }

            if ($k === 'type') {
                if (is_array($v)) {
                    $types = array_values(array_filter($v, fn($t) => $t !== 'null'));
                    $type = !empty($types) ? (string) $types[0] : 'string';
                    $out['type'] = strtoupper($type);
                    if (in_array('null', $v, true)) {
                        $out['nullable'] = true;
                    }
                } else {
                    $out['type'] = strtoupper((string) $v);
                }
                continue;
            }

            if ($k === 'properties' && is_array($v)) {
                $out['properties'] = [];
                foreach ($v as $pk => $pv) {
                    $out['properties'][$pk] = is_array($pv) ? self::toGeminiSchema($pv) : $pv;
                }
                continue;
            }

            if ($k === 'items' && is_array($v)) {
                $out['items'] = self::toGeminiSchema($v);
                continue;
            }

            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function buildPayload(string $bytes, string $mimeType, array $schema, string $prompt): array
    {
        $genConfig = [
            'temperature'        => 0.0,
            'maxOutputTokens'    => 4096,
            'response_mime_type' => 'application/json',
        ];

        if (!empty($schema)) {
            $genConfig['response_schema'] = self::toGeminiSchema($schema);
        }

        return [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        // Inline document (image or PDF)
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data'     => base64_encode($bytes),
                            ],
                        ],
                        // Extraction prompt as a text part
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => $genConfig,
        ];
    }

    /**
     * Build the generateContent URL with the API key in the query string.
     * The key is in the URL, not in a header — this is the Gemini API's
     * convention. We never log this URL.
     */
    private function buildEndpoint(?string $model = null): string
    {
        $selectedModel = $model ?? $this->model;
        return $this->baseUrl
            . sprintf(self::GENERATE_PATH, urlencode($selectedModel))
            . '?key=' . urlencode((string) $this->apiKey);
    }

    /**
     * @param array{status: int, body: string} $response
     * @param string|null $currentModel
     * @return array{annotation: array<mixed>|null, model: string|null, provider: string}
     * @throws BillExtractionFailure
     */
    private function parseResponse(array $response, ?string $currentModel = null): array
    {
        $status  = (int) ($response['status'] ?? 0);
        $bodyRaw = (string) ($response['body'] ?? '');

        // Map HTTP errors to failure categories.
        if ($status === 429) {
            throw BillExtractionFailure::transient(
                $this->providerName(),
                'Gemini API rate limit reached (HTTP 429).',
                429
            );
        }

        if ($status === 401 || $status === 403) {
            throw BillExtractionFailure::authFailed($this->providerName(), $status);
        }

        if ($status === 400) {
            throw BillExtractionFailure::invalidDocument(
                $this->providerName(),
                'Gemini API rejected the request (HTTP 400).'
            );
        }

        if ($status >= 500) {
            throw BillExtractionFailure::transient(
                $this->providerName(),
                'Gemini API server error (HTTP ' . $status . ').',
                $status
            );
        }

        if ($status < 200 || $status >= 300) {
            throw BillExtractionFailure::transient(
                $this->providerName(),
                'Gemini API unexpected response (HTTP ' . $status . ').',
                $status
            );
        }

        // Parse the response JSON.
        $data = json_decode($bodyRaw, true);
        if (!is_array($data)) {
            throw BillExtractionFailure::malformedResponse(
                $this->providerName(),
                'Gemini API response is not valid JSON.'
            );
        }

        // Extract model name from metadata (best-effort; null is fine).
        $modelName = null;
        if (isset($data['modelVersion']) && is_string($data['modelVersion'])) {
            $modelName = $data['modelVersion'];
        }

        // The structured JSON output lives in candidates[0].content.parts[0].text
        // when response_mime_type=application/json.
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($text) || trim($text) === '') {
            // Check for content filter / safety block — still a provider issue,
            // but not a document problem. Treat as transient so we try the next.
            $finishReason = $data['candidates'][0]['finishReason'] ?? null;
            if ($finishReason === 'SAFETY' || $finishReason === 'RECITATION') {
                throw BillExtractionFailure::transient(
                    $this->providerName(),
                    'Gemini blocked the response due to content policy.'
                );
            }

            throw BillExtractionFailure::malformedResponse(
                $this->providerName(),
                'Gemini API returned no usable candidate text.'
            );
        }

        // Decode the JSON annotation string.
        $annotation = json_decode($text, true);
        if (!is_array($annotation)) {
            throw BillExtractionFailure::malformedResponse(
                $this->providerName(),
                'Gemini structured output is not a valid JSON object.'
            );
        }

        return [
            'annotation' => $annotation,
            'model'      => $modelName ?? $currentModel ?? $this->model,
            'provider'   => $this->providerName(),
        ];
    }

    /**
     * Production Guzzle handler (same pattern as MistralOcrClient).
     *
     * @return callable(string, string, array<string, mixed>): array{status:int, body:string}
     */
    private static function guzzleHandler(): callable
    {
        return static function (string $method, string $url, array $options): array {
            $options['http_errors']      = false;
            $options['connect_timeout']  = $options['connect_timeout'] ?? 10.0;
            $options['timeout']          = $options['timeout'] ?? 120.0;
            $options['headers']          = array_merge(
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $options['headers'] ?? []
            );
            $client   = new GuzzleClient();
            $response = $client->request($method, $url, $options);

            return [
                'status' => $response->getStatusCode(),
                'body'   => (string) $response->getBody(),
            ];
        };
    }
}
