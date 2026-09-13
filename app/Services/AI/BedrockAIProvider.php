<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\Config;
use App\Support\Logger;
use App\Support\RequestId;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Token\BedrockTokenProvider;
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
 * Credentials: a short-lived Bedrock API key may live in the local,
 * gitignored .env as AWS_BEARER_TOKEN_BEDROCK (docs/10 D-066 §4
 * amendment). It is read here and handed directly to the SDK's official
 * `token` + `auth_scheme_preference` client options at the single
 * moment the client is constructed (clientConfig()). No putenv(), no
 * global storage, no logging, no response/error surface. When the key
 * is not configured, the SDK's own AWS_BEARER_TOKEN_BEDROCK environment
 * detection and the standard AWS credential chain remain untouched as
 * the fallback path.
 */
final class BedrockAIProvider implements AIProviderInterface
{
    public const PROVIDER_NAME = 'bedrock';
    public const DEFAULT_REGION = 'ap-south-1';
    public const DEFAULT_MODEL_ID = 'nvidia.nemotron-nano-12b-v2';
    public const SDK_LATEST = 'latest';

    /**
     * Injectable Converse seam: callable(array $payload): array|object
     * (Converse-shaped result — a plain array or an Aws\Result).
     * Null → lazily construct the real BedrockRuntimeClient.
     * Tests pass a fake — zero AWS contact.
     *
     * @var (callable(array<string, mixed>): (array<string, mixed>|object))|null
     */
    private $converseTransport;

    /**
     * Client-construction seam: callable(array $clientConfig): object
     * (anything exposing converse()). Null → construct the real
     * BedrockRuntimeClient. Tests inject a recorder — the real client
     * class is never touched by the automated suite.
     *
     * @var (callable(array<string, mixed>): object)|null
     */
    private $clientFactory;

    public function __construct(
        ?callable $converseTransport = null,
        private readonly ?Logger $logger = null,
        ?callable $clientFactory = null
    ) {
        $this->converseTransport = $converseTransport;
        $this->clientFactory = $clientFactory;
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
            // Safe provider metadata only: the sanitized error CODE
            // (e.g. "ValidationException"). The provider message/body
            // is never logged and never reaches callers (D-066 §6-§7).
            $awsCode = $e instanceof AIException ? $e->awsErrorCode() : BedrockErrorMapper::awsErrorCode($e);
            $category = $e instanceof AIException
                ? $e->category()
                : BedrockErrorMapper::category($e);

            $this->log('error', 'ai.bedrock.converse.failed', $region, $model, $correlationId, [
                'status' => 'failure',
                'error_category' => $category,
                'aws_error_code' => $awsCode,
            ]);

            if ($e instanceof AIException) {
                throw $e;
            }

            throw AIException::fromCategory($category, $e)->withAwsErrorCode($awsCode);
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
        ];

        if ($request->sendsInferenceConfig()) {
            $payload['inferenceConfig'] = [
                'maxTokens' => $request->maxTokens(),
                // Conservative deterministic default; quality tuning
                // is explicitly NOT this milestone.
                'temperature' => $request->temperature(),
            ];
        }

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
     * Exposed for tests against canned, Converse-shaped results.
     *
     * @param array<string, mixed>|object $result
     */
    public function normalize(array|object $result, string $model, string $correlationId): AIResponse
    {
        $decoded = self::decodeResult($result);
        if ($decoded === null) {
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
     * Result object → plain array.
     *
     * The AWS SDK returns Aws\Result for converse(): an
     * ArrayAccess/IteratorAggregate wrapper whose payload lives in
     * PRIVATE state and which is NOT JsonSerializable. json_encode()
     * on it silently produces {} — which previously turned every real
     * Converse response into an empty AIResponse. The exposed
     * key/value view is therefore the primary decode path (nested
     * members are plain arrays built by the SDK parser); the JSON
     * round-trip remains only for plain arrays and simple test doubles.
     */
    private static function decodeResult(array|object $result): ?array
    {
        if (is_array($result)) {
            return $result;
        }

        if ($result instanceof \ArrayAccess && $result instanceof \Traversable) {
            return iterator_to_array($result);
        }

        $decoded = json_decode(json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * THE credential handoff point (docs/10 D-066 §4 amendment).
     *
     * Builds the BedrockRuntimeClient constructor options. When a
     * short-lived Bedrock API key is present in the local, gitignored
     * .env (AWS_BEARER_TOKEN_BEDROCK), it is passed directly to the
     * SDK's official `token` client option, with `auth_scheme_preference`
     * set to bearer — the same mechanism the SDK itself applies when it
     * auto-detects the environment variable. The value is scoped to
     * this single construction call: never putenv()'d, never stored in
     * static/global state, never logged, never part of a Converse
     * payload, response, or error.
     *
     * When the key is not configured, no token options are added —
     * the SDK's standard credential chain (and its own env detection)
     * remains fully in charge as before.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array
    {
        $config = [
            'region' => trim(Config::get('AWS_REGION', self::DEFAULT_REGION) ?? self::DEFAULT_REGION),
            'version' => self::SDK_LATEST,
        ];

        $bearerToken = Config::get('AWS_BEARER_TOKEN_BEDROCK');

        if (is_string($bearerToken) && trim($bearerToken) !== '') {
            $config['token'] = ['token' => trim($bearerToken)];
            $config['auth_scheme_preference'] = [BedrockTokenProvider::BEARER_AUTH];
        }

        return $config;
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

        // Real path: reached only after the guard passed. The client
        // factory seam keeps even this constructible offline in tests;
        // production default constructs the official SDK client with
        // the scoped credential handoff from clientConfig().
        $factory = $this->clientFactory
            ?? static fn (array $clientConfig): BedrockRuntimeClient => new BedrockRuntimeClient($clientConfig);

        return $factory($this->clientConfig())->converse($payload);
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
