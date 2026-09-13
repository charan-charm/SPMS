<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AI\AIErrorCategory;
use App\Services\AI\AIException;
use App\Services\AI\AIImage;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\AIRequest;
use App\Services\AI\AIResponse;
use App\Services\AI\BedrockAIProvider;
use App\Services\AI\BedrockErrorMapper;
use App\Services\AI\FakeAIProvider;
use App\Support\Config;
use App\Support\Logger;
use App\Support\RequestId;
use ArrayObject;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * AI foundation (docs/10 D-066) — provider seam, guards, mapping,
 * normalization. ALL determinism comes from injected fakes: no test in
 * here can reach AWS (the runner additionally pins
 * BEDROCK_ALLOW_REAL_CALLS=0, and the guard test proves the real
 * transport is never invoked while disabled).
 */
final class AIProviderTest
{
    /**
     * Run a check body under an explicit config pin and restore the
     * automated-suite lockdown afterwards.
     *
     * @param callable(): void $body
     */
    private static function withConfig(array $overrides, callable $body): void
    {
        Config::overrideForTesting($overrides);

        try {
            $body();
        } finally {
            Config::overrideForTesting([
                'AI_PROVIDER' => 'fake',
                'BEDROCK_ALLOW_REAL_CALLS' => '0',
                'AWS_REGION' => 'ap-south-1',
                'AWS_BEDROCK_MODEL_ID' => 'nvidia.nemotron-nano-12b-v2',
            ]);
        }
    }

    /**
     * @param array<string, mixed>|callable(array): mixed $resultOrFn
     */
    private static function providerWithTransport(array|callable $resultOrFn, \ArrayObject $calls, ?\Throwable $throw = null): BedrockAIProvider
    {
        $transport = static function (array $payload) use ($calls, $resultOrFn, $throw): array {
            $calls->append($payload);

            if ($throw !== null) {
                throw $throw;
            }

            return is_callable($resultOrFn) ? $resultOrFn($payload) : $resultOrFn;
        };

        return new BedrockAIProvider($transport);
    }

    private static function cannedResponse(string $text = 'bedrock says hello'): array
    {
        return [
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => $text]]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 11, 'outputTokens' => 5, 'totalTokens' => 16],
        ];
    }

    private static function awsException(string $code, ?int $status = null, bool $connectionError = false): BedrockRuntimeException
    {
        $context = ['code' => $code];

        if ($status !== null) {
            $context['response'] = new PsrResponse($status);
        }

        if ($connectionError) {
            $context['connection_error'] = true;
        }

        return new BedrockRuntimeException('aws-internal-detail', new Command('Converse'), $context);
    }

    public static function run(): array
    {
        return [
            TestResult::check('AIImage validates MIME, emptiness and size bound — never fabricates types', function (): void {
                $image = AIImage::fromRawBytes('image/jpeg', str_repeat("\xFF\xD8", 32));
                Assert::same('image/jpeg', $image->mimeType());
                Assert::same('jpeg', $image->bedrockFormat());
                Assert::same(64, $image->byteLength());

                Assert::same('png', AIImage::fromRawBytes('IMAGE/PNG', 'x')->bedrockFormat(), 'MIME is matched case-insensitively, bytes kept verbatim');

                try {
                    AIImage::fromRawBytes('application/pdf', 'x');
                    Assert::true(false, 'PDF must be rejected in this foundation');
                } catch (AIException $e) {
                    Assert::same(AIErrorCategory::INVALID_REQUEST, $e->category());
                }

                try {
                    AIImage::fromRawBytes('', 'x');
                    Assert::true(false, 'missing MIME must be rejected');
                } catch (AIException $e) {
                    Assert::same(AIErrorCategory::INVALID_REQUEST, $e->category());
                }

                try {
                    AIImage::fromRawBytes('image/png', '');
                    Assert::true(false, 'empty image bytes must be rejected');
                } catch (AIException $e) {
                    Assert::same(AIErrorCategory::INVALID_REQUEST, $e->category());
                }

                try {
                    AIImage::fromRawBytes('image/png', str_repeat('b', AIImage::MAX_BYTES + 1));
                    Assert::true(false, 'oversized image must be rejected');
                } catch (AIException $e) {
                    Assert::same(AIErrorCategory::INVALID_REQUEST, $e->category());
                    Assert::contains((string) AIImage::MAX_BYTES, $e->getMessage());
                }
            }),

            TestResult::check('AIRequest is minimal-honest: text-only and text+image shapes', function (): void {
                $text = AIRequest::text('hello');
                Assert::false($text->hasImages());
                Assert::same(256, $text->maxTokens(), 'bounded conservative default');
                Assert::same(0.0, $text->temperature(), 'deterministic conservative default');
                Assert::false($text->wantsStructuredOutput());

                $mixed = AIRequest::textWithImages('read this', [AIImage::fromRawBytes('image/png', 'bytes')]);
                Assert::true($mixed->hasImages());
                Assert::same(1, count($mixed->images()));

                try {
                    AIRequest::text('   ');
                    Assert::true(false, 'blank prompt must be rejected');
                } catch (AIException $e) {
                    Assert::same(AIErrorCategory::INVALID_REQUEST, $e->category());
                }

                try {
                    AIRequest::text('x')->withMaxTokens(5000);
                    Assert::true(false, 'maxTokens must be bounded');
                } catch (AIException $e) {
                    Assert::same(AIErrorCategory::INVALID_REQUEST, $e->category());
                }
            }),

            TestResult::check('FakeAIProvider answers text and image requests deterministically, offline', function (): void {
                $fake = new FakeAIProvider('fixed answer');

                $response = $fake->complete(AIRequest::text('question one'));
                Assert::same('fixed answer', $response->text());
                Assert::same('fake', $response->provider());
                Assert::same(FakeAIProvider::MODEL, $response->model());
                Assert::same('end_turn', $response->stopReason());
                Assert::same(3, $response->usage()['input_tokens'] ?? 0, 'deterministic usage (12-char prompt ÷ 4)');

                $again = $fake->complete(AIRequest::textWithImages('question one', [AIImage::fromRawBytes('image/jpeg', 'abc')]));
                Assert::same($response->text(), $again->text(), 'same prompt ⇒ same response (deterministic)');
                Assert::same(2, $fake->callCount());
                Assert::true($fake->lastRequest()?->hasImages() === true, 'image request recorded');

                $structured = $fake->complete(AIRequest::text('give json')->withStructuredOutput(['type' => 'object']));
                Assert::same(['ok' => true, 'echo' => 'give json'], $structured->structuredOutput(), 'structured channel is exercisable offline');
            }),

            TestResult::check('FakeAIProvider failure simulation surfaces categorized AIExceptions', function (): void {
                $fake = new FakeAIProvider();
                $fake->failWith(AIException::throttling());

                try {
                    $fake->complete(AIRequest::text('x'));
                    Assert::true(false, 'expected simulated failure');
                } catch (AIException $e) {
                    Assert::same(AIErrorCategory::THROTTLING, $e->category());
                    Assert::same('AI_THROTTLED', $e->providerCode());
                }

                $fake->failWith(null);
                Assert::same('fake: deterministic provider response', $fake->complete(AIRequest::text('x'))->text());
            }),

            TestResult::check('Factory defaults to fake and rejects unknown providers', function (): void {
                self::withConfig(['AI_PROVIDER' => 'fake'], function (): void {
                    Assert::same('fake', (new AIProviderFactory())->provider()->name());
                });

                self::withConfig(['AI_PROVIDER' => null], function (): void {
                    Assert::same('fake', (new AIProviderFactory())->provider()->name(), 'unset AI_PROVIDER defaults to the offline fake');
                });

                self::withConfig(['AI_PROVIDER' => 'bedrock'], function (): void {
                    Assert::same('bedrock', (new AIProviderFactory())->provider()->name());
                });

                self::withConfig(['AI_PROVIDER' => 'openai'], function (): void {
                    try {
                        new AIProviderFactory();
                        Assert::true(false, 'unknown provider must be rejected');
                    } catch (AIException $e) {
                        Assert::same(AIErrorCategory::CONFIGURATION, $e->category());
                    }
                });
            }),

            TestResult::check('REAL-CALL GUARD: disabled flag rejects before any transport is invoked', function (): void {
                $invoked = false;

                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '0'], function () use (&$invoked): void {
                    $provider = new BedrockAIProvider(static function (array $payload) use (&$invoked): array {
                        $invoked = true;

                        return self::cannedResponse();
                    });

                    try {
                        $provider->complete(AIRequest::text('must never reach AWS'));
                        Assert::true(false, 'expected guard rejection');
                    } catch (AIException $e) {
                        Assert::same('AI_REAL_CALLS_DISABLED', $e->providerCode());
                        Assert::same(AIErrorCategory::CONFIGURATION, $e->category());
                    }
                });

                Assert::false($invoked, 'no transport call may happen while the guard is off');
            }),

            TestResult::check('Bedrock adapter maps text requests to the Converse payload', function (): void {
                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function (): void {
                    $calls = new ArrayObject();
                    $provider = self::providerWithTransport(self::cannedResponse('ok'), $calls);

                    $response = $provider->complete(AIRequest::text('what is a surplus?', 'You are SPMS.'));
                    Assert::same('ok', $response->text());

                    $payload = $calls[0];
                    Assert::same('nvidia.nemotron-nano-12b-v2', $payload['modelId'], 'configured/default model');
                    Assert::same('user', $payload['messages'][0]['role']);
                    Assert::same([['text' => 'what is a surplus?']], $payload['messages'][0]['content']);
                    Assert::same([['text' => 'You are SPMS.']], $payload['system']);
                    Assert::same(256, $payload['inferenceConfig']['maxTokens']);
                    Assert::same(0.0, $payload['inferenceConfig']['temperature']);
                    Assert::false(isset($payload['toolConfig']), 'no structured channel unless requested');
                });
            }),

            TestResult::check('Bedrock adapter maps configured region/model and image blocks', function (): void {
                $image = AIImage::fromRawBytes('image/jpeg', "\xFF\xD8\xFF" . 'payload-bytes');

                self::withConfig([
                    'BEDROCK_ALLOW_REAL_CALLS' => '1',
                    'AWS_BEDROCK_MODEL_ID' => 'custom.model-id',
                ], function () use ($image): void {
                    $calls = new ArrayObject();
                    $provider = self::providerWithTransport(self::cannedResponse(), $calls);
                    $provider->complete(AIRequest::textWithImages('explain', [$image]));

                    $payload = $calls[0];
                    Assert::same('custom.model-id', $payload['modelId']);
                    Assert::same('explain', $payload['messages'][0]['content'][0]['text']);

                    $imageBlock = $payload['messages'][0]['content'][1];
                    Assert::same('jpeg', $imageBlock['image']['format']);
                    Assert::same($image->bytes(), $imageBlock['image']['source']['bytes'], 'bytes forwarded verbatim, no conversion');
                });
            }),

            TestResult::check('Structured output preparation rides the Converse tool channel opaquely', function (): void {
                $schema = ['type' => 'object', 'properties' => ['units' => ['type' => 'number']]];

                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function () use ($schema): void {
                    $calls = new ArrayObject();
                    $provider = self::providerWithTransport(self::cannedResponse(), $calls);
                    $provider->complete(AIRequest::text('extract')->withStructuredOutput($schema));

                    $payload = $calls[0];
                    Assert::same('explicit', $payload['toolConfig']['type']);
                    Assert::same($schema, $payload['toolConfig']['explicit']['tools'][0]['toolSpec']['inputSchema']['json'], 'schema passed through unchanged');
                    Assert::same('structured_response', $payload['toolChoice']['tool']['name']);
                });
            }),

            TestResult::check('Converse responses normalize to AIResponse (text, toolUse, usage, stopReason)', function (): void {
                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function (): void {
                    $provider = self::providerWithTransport([
                        'output' => ['message' => ['content' => [
                            ['text' => 'part one. '],
                            ['text' => 'part two.'],
                        ]]],
                        'stopReason' => 'MAX_TOKENS',
                        'usage' => ['inputTokens' => 9, 'outputTokens' => 4],
                    ], new ArrayObject());

                    $response = $provider->complete(AIRequest::text('x', correlationId: 'corr-42'));
                    Assert::same('part one. part two.', $response->text());
                    Assert::same('bedrock', $response->provider());
                    Assert::same('nvidia.nemotron-nano-12b-v2', $response->model());
                    Assert::same('MAX_TOKENS', $response->stopReason());
                    Assert::same('corr-42', $response->correlationId());
                    Assert::same(['input_tokens' => 9, 'output_tokens' => 4], $response->usage(), 'normalized to snake_case counters');
                });

                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function (): void {
                    $provider = self::providerWithTransport([
                        'output' => ['message' => ['content' => [
                            ['toolUse' => ['name' => 'structured_response', 'input' => ['units' => 312.5]]],
                        ]]],
                        'stopReason' => 'tool_use',
                    ], new ArrayObject());

                    $response = $provider->complete(AIRequest::text('x')->withStructuredOutput());
                    Assert::same(['units' => 312.5], $response->structuredOutput());
                    Assert::same('{"units":312.5}', $response->textOrStructuredJson());
                    Assert::same(null, $response->usage(), 'absent usage stays null — never fabricated');
                });
            }),

            TestResult::check('Correlation falls back to the request pipeline id', function (): void {
                RequestId::set('req-fallback-1');

                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function (): void {
                    $provider = self::providerWithTransport(self::cannedResponse(), new ArrayObject());
                    Assert::same('req-fallback-1', $provider->complete(AIRequest::text('x'))->correlationId());
                });
            }),

            TestResult::check('AWS failures normalize to safe categorized AIExceptions', function (): void {
                $matrix = [
                    ['ValidationException', null, AIErrorCategory::INVALID_REQUEST],
                    ['AccessDeniedException', null, AIErrorCategory::AUTHENTICATION],
                    ['UnrecognizedClientException', null, AIErrorCategory::AUTHENTICATION],
                    ['ThrottlingException', null, AIErrorCategory::THROTTLING],
                    ['TooManyRequestsException', 429, AIErrorCategory::THROTTLING],
                    ['ResourceNotFoundException', null, AIErrorCategory::MODEL_UNAVAILABLE],
                    ['ModelNotReadyException', null, AIErrorCategory::MODEL_UNAVAILABLE],
                    ['InternalServerException', 500, AIErrorCategory::PROVIDER_FAILURE],
                    ['SomeUnknownErrorCode', 503, AIErrorCategory::PROVIDER_FAILURE],
                    ['SomeUnknownErrorCode', 401, AIErrorCategory::AUTHENTICATION],
                    ['SomeUnknownErrorCode', 408, AIErrorCategory::TIMEOUT],
                ];

                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function () use ($matrix): void {
                    foreach ($matrix as [$code, $status, $expected]) {
                        $provider = self::providerWithTransport(self::cannedResponse(), new ArrayObject(), self::awsException($code, $status));

                        try {
                            $provider->complete(AIRequest::text('x'));
                            Assert::true(false, 'expected normalized failure for ' . $code);
                        } catch (AIException $e) {
                            Assert::same($expected, $e->category(), $code);
                            Assert::notContains('aws-internal-detail', $e->getMessage(), 'raw provider detail never surfaces');
                            Assert::true($e->underlying() !== null, 'underlying exception preserved for diagnostics');
                        }
                    }
                });

                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function (): void {
                    $provider = self::providerWithTransport(
                        self::cannedResponse(),
                        new ArrayObject(),
                        new ConnectException('cURL error 28: Operation timed out after 5000 ms', new PsrRequest('POST', 'https://bedrock-runtime.example.internal'))
                    );

                    try {
                        $provider->complete(AIRequest::text('x'));
                        Assert::true(false, 'expected timeout classification');
                    } catch (AIException $e) {
                        Assert::same(AIErrorCategory::TIMEOUT, $e->category());
                    }
                });

                // Pure-mapper sanity (also covers connection-error flag path).
                Assert::same(AIErrorCategory::TIMEOUT, BedrockErrorMapper::category(self::awsException('', null, true)));
            }),

            TestResult::check('Provider logging carries only safe metadata — never prompts, images or raw responses', function (): void {
                $dir = sys_get_temp_dir() . '/spms-ai-log-' . bin2hex(random_bytes(4));
                $logger = new Logger($dir, 'debug');
                $canaryPrompt = 'CANARY-PROMPT-' . bin2hex(random_bytes(6));
                $canaryBytes = 'CANARY-BYTES-' . str_repeat('Z', 64);

                self::withConfig(['BEDROCK_ALLOW_REAL_CALLS' => '1'], function () use ($logger, $dir, $canaryPrompt, $canaryBytes): void {
                    $transport = static fn (array $payload): array => self::cannedResponse('ANSWER-CANARY-TEXT');
                    $provider = new BedrockAIProvider($transport, $logger);
                    $provider->complete(AIRequest::textWithImages($canaryPrompt, [AIImage::fromRawBytes('image/png', $canaryBytes)], systemPrompt: null, correlationId: 'corr-log-1'));

                    $failure = new BedrockRuntimeException(
                        'FAILURE-DETAIL-CANARY',
                        new Command('Converse'),
                        ['code' => 'ThrottlingException', 'response' => new PsrResponse(429)]
                    );
                    try {
                        (new BedrockAIProvider(static fn (array $p): array => throw $failure, $logger))
                            ->complete(AIRequest::text('second-prompt', correlationId: 'corr-log-2'));
                        Assert::true(false);
                    } catch (AIException $e) {
                        Assert::same(AIErrorCategory::THROTTLING, $e->category());
                    }
                });

                $line = (string) file_get_contents($dir . '/app-' . gmdate('Y-m-d') . '.log');
                Assert::notContains($canaryPrompt, $line, 'prompts are never logged');
                Assert::notContains($canaryBytes, $line, 'image bytes are never logged');
                Assert::notContains('ANSWER-CANARY-TEXT', $line, 'model responses are never logged');
                Assert::notContains('FAILURE-DETAIL-CANARY', $line, 'raw provider failure detail is never logged');
                Assert::contains('ai.bedrock.converse.completed', $line);
                Assert::contains('ai.bedrock.converse.failed', $line);
                Assert::contains('corr-log-1', $line, 'correlation ids make calls diagnosable');
                Assert::contains('"input_tokens":11', $line, 'usage counters are safe metadata');
                Assert::contains('ap-south-1', $line, 'region/model/operation metadata is present');
            }),

            TestResult::check('Configuration surface: defaults apply when unset values are blank-safe', function (): void {
                self::withConfig([
                    'BEDROCK_ALLOW_REAL_CALLS' => '1',
                    'AWS_REGION' => '',
                    'AWS_BEDROCK_MODEL_ID' => '   ',
                ], function (): void {
                    $provider = self::providerWithTransport(self::cannedResponse(), new ArrayObject());

                    try {
                        $provider->complete(AIRequest::text('x'));
                        Assert::true(false, 'explicitly blank region/model must fail closed as configuration error');
                    } catch (AIException $e) {
                        Assert::same(AIErrorCategory::CONFIGURATION, $e->category());
                    }
                });

                self::withConfig([
                    'BEDROCK_ALLOW_REAL_CALLS' => '1',
                    'AWS_REGION' => 'eu-west-1',
                    'AWS_BEDROCK_MODEL_ID' => 'nvidia.nemotron-nano-12b-v2',
                ], function (): void {
                    $calls = new ArrayObject();
                    $provider = self::providerWithTransport(self::cannedResponse(), $calls);
                    $response = $provider->complete(AIRequest::text('x'));
                    Assert::same('nvidia.nemotron-nano-12b-v2', $calls[0]['modelId']);
                    Assert::same('nvidia.nemotron-nano-12b-v2', $response->model());
                });
            }),
        ];
    }
}
