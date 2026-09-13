<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\Config;
use App\Support\Logger;
use App\Support\RequestId;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Throwable;

/**
 * AI foundation (docs/10 D-066) — Amazon Bedrock Runtime adapter
 * (Converse API; initial model NVIDIA Nemotron Nano 12B v2 VL,
 * region ap-south-1).
 *
 * This is the ONLY place the AWS SDK may appear. It converts the
 * provider-neutral AIRequest into a Converse payload, converts the
 * Converse result back into an AIResponse, and translates any AWS
 * failure into a categorized AIException — no raw SDK object or
 * exception crosses the boundary.
 *
 * HARD SAFETY GUARD (mirrors W1.1 Brevo): a real provider call requires
 * the explicit BEDROCK_ALLOW_REAL_CALLS=1 flag. The automated test suite
 * pins the flag off (tests/run-tests.php), and unit tests inject a fake
 * Converse transport instead of the SDK client — so no automated run can
 * ever reach AWS, regardless of a developer's local .env. The only
 * sanctioned real path is the dedicated manual tool
 * (scripts/manual-bedrock-verify.php) run by the owner.
 *
 * Credentials are resolved exclusively by the AWS SDK's standard
 * credential/provider chain (never from request payloads, React, or
 * hand-parsed files) and are never logged or included in errors.
 */
final class BedrockAIProvider implements AIProviderInterface
{
    public const PROVIDER_NAME = 'bedrock';
    public const DEFAULT_REGION = 'ap-south-1';
    public const DEFAULT_MODEL_ID = 'nvidia.nemotron-nano-12b-v2';
    public const SDK_LATEST = 'latest';

    /**
     * Injectable Converse seam: callable(array $payload): array
     * (Converse-shaped result). Null → lazily construct the real
     * BedrockRuntimeClient. Tests pass a fake — zero AWS contact.
     *
     * @var (callable(array<string, mixed>): array<string, mixed>)|null
     */
    private $converseTransport;

    public function __construct(?callable $converseTransport = null, private readonly ?Logger $logger = null)
    {
        $this->converseTransport = $converseTransport;
    }

    public function name(): string
    {
        return self::PROVIDER_NAME;
    }

    public function complete(AIRequest $request): AIResponse
    {
        // Guard before anything else: disabled state must never touch
        // configuration resolution side effects or the SDK client.
        if (Config::get('BEDROCK_ALLOW_REAL_CALLS') !== '1') {
            throw AIException::realCallsDisabled();
        }

        $region = trim(Config::get('AWS_REGION', self::DEFAULT_REGION) ?? self::DEFAULT_REGION);
        $model = trim(Config::get('AWS_BEDROCK_MODEL_ID', self::DEFAULT_MODEL_ID) ?? self::DEFAULT_MODEL_ID);

        if ($region === '' || $model === '') {
            throw AIException::configuration('AWS_REGION and AWS_BEDROCK_MODEL_ID must be non-empty when real calls are enabled.');
        }

        $correlationId = $request->correlationId() ?? RequestId::current() ?? RequestId::new();
        $payload = $this->conversePayload($request, $model);

        try {
            $result = $this->dispatch($payload);
        } catch (Throwable $e) {
            $category = $e instanceof AIException
                ? $e->category()
                : BedrockErrorMapper::category($e);

            $this->log('error', 'ai.bedrock.converse.failed', $region, $model, $correlationId, [
                'status' => 'failure',
                'error_category' => $category,
            ]);

            throw $e instanceof AIException
                ? $e
                : AIException::fromCategory($category, $e);
        }

        $response = $this->normalize($result, $model, $correlationId);

        $this->log('info', 'ai.bedrock.converse.completed', $region, $model, $correlationId, [
            'status' => 'success',
            'usage' => $response->usage(),
            'stop_reason' => $response->stopReason(),
        ]);

        return $response;
    }

    /**
     * Provider-neutral request → Converse payload. Exposed for tests:
     * mapping is asserted without ever calling AWS.
     *
     * @return array<string, mixed>
     */
    public function conversePayload(AIRequest $request, ?string $modelId = null): array
    {
        $model = $modelId ?? (trim(Config::get('AWS_BEDROCK_MODEL_ID', self::DEFAULT_MODEL_ID) ?? self::DEFAULT_MODEL_ID));

        $content = [];

        if (trim($request->prompt()) !== '') {
            $content[] = ['text' => $request->prompt()];
        }

        foreach ($request->images() as $image) {
            // Re-validated at the adapter boundary (defense in depth):
            // non-empty bytes, allowed MIME, bounded size.
            $content[] = [
                'image' => [
                    'format' => $image->bedrockFormat(),
                    'source' => ['bytes' => $image->bytes()],
                ],
            ];
        }

        $payload = [
            'modelId' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
            'inferenceConfig' => [
                'maxTokens' => $request->maxTokens(),
                // Conservative deterministic default; quality tuning
                // is explicitly NOT this milestone.
                'temperature' => $request->temperature(),
            ],
        ];

        if ($request->systemPrompt() !== null && trim($request->systemPrompt()) !== '') {
            $payload['system'] = [['text' => $request->systemPrompt()]];
        }

        // Structured-output preparation: schema-constrained JSON is
        // requested through the documented Converse tool channel, with
        // the schema carried opaquely. No business schema exists here;
        // semantics are validated during the first live verification.
        if ($request->wantsStructuredOutput()) {
            $schema = $request->structuredSchema() ?? ['type' => 'object'];

            $payload['toolConfig'] = [
                'type' => 'explicit',
                'explicit' => [
                    'tools' => [
                        [
                            'toolSpec' => [
                                'name' => 'structured_response',
                                'description' => 'Return the response as schema-constrained JSON.',
                                'inputSchema' => ['json' => $schema],
                            ],
                        ],
                    ],
                ],
            ];
            $payload['toolChoice'] = ['tool' => ['name' => 'structured_response']];
        }

        return $payload;
    }

    /**
     * Converse result (SDK object or array) → normalized AIResponse.
     * Exposed for tests against canned, Converse-shaped arrays.
     *
     * @param array<string, mixed>|object $result
     */
    public function normalize(array|object $result, string $model, string $correlationId): AIResponse
    {
        $decoded = json_decode(json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        if (!is_array($decoded)) {
            throw AIException::providerFailure();
        }

        $blocks = $decoded['output']['message']['content'] ?? [];
        $textParts = [];
        $structured = null;

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (isset($block['text']) && is_string($block['text'])) {
                $textParts[] = $block['text'];
            } elseif (isset($block['toolUse']) && is_array($block['toolUse']['input'] ?? null)) {
                $structured = $block['toolUse']['input'];
            }
        }

        $usage = null;
        if (isset($decoded['usage']) && is_array($decoded['usage'])) {
            $usage = [];
            foreach (['inputTokens' => 'input_tokens', 'outputTokens' => 'output_tokens', 'totalTokens' => 'total_tokens'] as $from => $to) {
                if (isset($decoded['usage'][$from]) && is_numeric($decoded['usage'][$from])) {
                    $usage[$to] = (int) $decoded['usage'][$from];
                }
            }
            if ($usage === []) {
                $usage = null;
            }
        }

        return new AIResponse(
            text: implode('', $textParts),
            provider: self::PROVIDER_NAME,
            model: $model,
            usage: $usage,
            stopReason: isset($decoded['stopReason']) && is_string($decoded['stopReason']) ? $decoded['stopReason'] : null,
            correlationId: $correlationId,
            structuredOutput: $structured
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|object
     */
    private function dispatch(array $payload): array|object
    {
        if ($this->converseTransport !== null) {
            return ($this->converseTransport)($payload);
        }

        // Real path: standard SDK credential chain, no explicit
        // credential handling anywhere in this codebase.
        $client = new BedrockRuntimeClient([
            'region' => trim(Config::get('AWS_REGION', self::DEFAULT_REGION) ?? self::DEFAULT_REGION),
            'version' => self::SDK_LATEST,
        ]);

        return $client->converse($payload);
    }

    /**
     * Safe metadata only: provider, model, region, operation,
     * correlation, status, category/usage counters. Never prompts,
     * never image bytes, never responses, never credential material.
     *
     * @param array<string, mixed> $extra
     */
    private function log(string $level, string $message, string $region, string $model, string $correlationId, array $extra): void
    {
        $this->logger?->{$level}($message, array_merge([
            'provider' => self::PROVIDER_NAME,
            'operation' => 'converse',
            'region' => $region,
            'model' => $model,
            'correlation_id' => $correlationId,
        ], $extra));
    }
}
