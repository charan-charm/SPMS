<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mistral\MistralOcrClient;
use App\Services\Mistral\MistralOcrException;
use App\Support\Config;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * Controlled Mistral OCR integration-test support — OFFLINE only.
 *
 * Every HTTP interaction goes through the injected fake handler:
 * automated tests can never contact Mistral or consume quota (the
 * runner additionally pins MISTRAL_API_KEY to empty, mirroring the
 * Brevo/Bedrock lockdowns).
 */
final class MistralOcrTest
{
    private const FAKE_KEY = 'mk_FAKE-KEY-never-real-DO-NOT-LEAK';

    /**
     * @param array<int, array{status:int, body:string}> $queue responses served in order
     * @return array{0: MistralOcrClient, 1: \ArrayObject<int, array{method:string,url:string,options:array<string,mixed>}>}
     */
    private static function clientWithResponses(array $queue, ?\Closure $onCall = null): array
    {
        $calls = new \ArrayObject();

        $handler = static function (string $method, string $url, array $options) use (&$queue, $calls, $onCall): array {
            $calls->append(['method' => $method, 'url' => $url, 'options' => $options]);
            if ($onCall !== null) {
                $onCall($method, $url, $options);
            }

            $next = array_shift($queue);
            if ($next === null) {
                throw new \RuntimeException('test handler queue exhausted');
            }

            return $next;
        };

        return [new MistralOcrClient(self::FAKE_KEY, 'mistral-ocr-test', $handler), $calls];
    }

    private static function tempDoc(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat('p', 64);
    }

    private static function jpgBytes(): string
    {
        return "\xFF\xD8\xFF\xE0JFIF" . str_repeat('j', 64);
    }

    private static function pdfBytes(): string
    {
        return "%PDF-1.4\n" . str_repeat('d', 64) . "\n%%EOF\n";
    }

    private static function ocrSuccessBody(): string
    {
        return (string) json_encode([
            'model' => 'mistral-ocr-2506',
            'pages' => [
                [
                    'index' => 0,
                    'markdown' => "# BILL\n| kwh | 312 |",
                    'images' => [],
                    'tables' => [['rows' => 2]],
                    'blocks' => [['text' => 'a'], ['text' => 'b']],
                    'confidence_scores' => ['block' => ['min' => 0.91, 'mean' => 0.97]],
                ],
            ],
            'document_annotation' => '{"document_assessment":{"status":"VALID","reason":"clear","confidence":0.98}}',
            'usage_info' => ['input_tokens' => 1200, 'output_tokens' => 340],
        ]);
    }

    public static function run(): array
    {
        return [
            TestResult::check('missing API key fails as configuration error before any network call', function (): void {
                $calls = new \ArrayObject();
                $handler = static function () use ($calls): array {
                    $calls->append(1);

                    return ['status' => 200, 'body' => '{}'];
                };

                // Empty key AND empty config fallback (runner pins the
                // config key to '') must refuse cleanly.
                Config::overrideForTesting(['MISTRAL_API_KEY' => '']);
                $client = new MistralOcrClient('', 'm', $handler);

                try {
                    $client->processOcr(['type' => 'image_url', 'image_url' => 'data:image/png;base64,AA']);
                    Assert::true(false, 'expected configuration failure');
                } catch (MistralOcrException $e) {
                    Assert::same(MistralOcrException::CONFIGURATION, $e->category());
                    Assert::same(0, count($calls), 'no HTTP attempted without a key');
                }
            }),

            TestResult::check('local document validation fails closed: missing, unsupported, mismatched', function (): void {
                try {
                    MistralOcrClient::validateLocalDocument(sys_get_temp_dir() . '/nope-does-not-exist-9f3a.pdf');
                    Assert::true(false, 'missing file must fail');
                } catch (MistralOcrException $e) {
                    Assert::same(MistralOcrException::INVALID_REQUEST, $e->category());
                }

                $txt = self::tempDoc('spms-mistral-note.txt', 'hello world');
                try {
                    MistralOcrClient::validateLocalDocument($txt);
                    Assert::true(false, 'unsupported extension must fail');
                } catch (MistralOcrException $e) {
                    Assert::contains('Unsupported document type', $e->getMessage());
                }

                // A text file wearing a .png name is rejected too —
                // no silent conversion, no fabricated MIME.
                $masked = self::tempDoc('spms-mistral-masked.png', 'totally-not-a-png');
                try {
                    MistralOcrClient::validateLocalDocument($masked);
                    Assert::true(false, 'content/extension mismatch must fail');
                } catch (MistralOcrException $e) {
                    Assert::contains('does not match', $e->getMessage());
                }

                $ok = self::tempDoc('spms-mistral-good.png', self::pngBytes());
                $validated = MistralOcrClient::validateLocalDocument($ok);
                Assert::same('image/png', $validated['mime']);
                Assert::same(strlen(self::pngBytes()), $validated['size']);
            }),

            TestResult::check('OCR request mapping follows the official contract (blocks, confidence, table format, annotation)', function (): void {
                $schema = ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
                $request = MistralOcrClient::buildOcrRequest(
                    'mistral-ocr-latest',
                    ['type' => 'image_url', 'image_url' => 'data:image/jpeg;base64,AAA'],
                    $schema,
                    'Extract only visible fields.'
                );

                Assert::same('mistral-ocr-latest', $request['model']);
                Assert::same('markdown', $request['table_format']);
                Assert::same(true, $request['include_blocks']);
                Assert::same('block', $request['confidence_scores_granularity']);
                Assert::same(false, $request['include_image_base64'], 'no image base64 output in the first test');
                Assert::same('image_url', $request['document']['type']);
                Assert::same('json_schema', $request['document_annotation_format']['type']);
                Assert::same($schema, $request['document_annotation_format']['json_schema']['schema']);
                Assert::same('Extract only visible fields.', $request['document_annotation_prompt']);

                $plain = MistralOcrClient::buildOcrRequest('m', ['type' => 'document_url', 'document_url' => 'https://example.invalid/x']);
                Assert::false(array_key_exists('document_annotation_format', $plain));
                Assert::false(array_key_exists('document_annotation_prompt', $plain));
            }),

            TestResult::check('image flow: data-URL document reaches /v1/ocr with auth header and normalized response', function (): void {
                $png = self::tempDoc('spms-mistral-call.png', self::pngBytes());
                [$client, $calls] = self::clientWithResponses([
                    ['status' => 200, 'body' => self::ocrSuccessBody()],
                ]);

                $result = $client->processOcr(
                    MistralOcrClient::imageDataUrlDocument(MistralOcrClient::validateLocalDocument($png)),
                    ['type' => 'object'],
                    'prompt-x'
                );

                Assert::same(1, count($calls));
                $call = $calls[0];
                Assert::same('POST', $call['method']);
                Assert::same('https://api.mistral.ai/v1/ocr', $call['url']);
                Assert::same('Bearer ' . self::FAKE_KEY, $call['options']['headers']['authorization']);
                Assert::true(str_starts_with((string) $call['options']['json']['document']['image_url'], 'data:image/png;base64,'), 'inline data URL used');

                // Normalized parsing of the official response shape.
                Assert::same('mistral-ocr-2506', $result['model']);
                Assert::same(1, count($result['pages']));
                $page = $result['pages'][0];
                Assert::same(0, $page['index']);
                Assert::contains('# BILL', $page['markdown']);
                Assert::same(2, $page['blocks']);
                Assert::same(1, $page['tables']);
                Assert::same(0, $page['images']);
                Assert::same(0.91, $page['confidence_scores']['block']['min']);
                Assert::same(['input_tokens' => 1200, 'output_tokens' => 340], $result['usage']);
                Assert::same(['document_assessment' => ['status' => 'VALID', 'reason' => 'clear', 'confidence' => 0.98]], $result['annotation']);
                Assert::same(null, $result['annotation_error']);
                Assert::same(200, $client->lastHttpStatus());
            }),

            TestResult::check('PDF flow: Files API upload (purpose=ocr) → signed URL → /v1/ocr document_url', function (): void {
                $pdf = self::tempDoc('spms-mistral-call.pdf', self::pdfBytes());

                [$client, $calls] = self::clientWithResponses([
                    ['status' => 200, 'body' => '{"id":"file-abc-123"}'],
                    ['status' => 200, 'body' => '{"url":"https://mrlists.example.invalid/signed/TOKEN-NOT-LOGGED"}'],
                    ['status' => 200, 'body' => self::ocrSuccessBody()],
                ]);

                $validated = MistralOcrClient::validateLocalDocument($pdf);
                $fileId = $client->uploadDocument($validated);
                $signedUrl = $client->fetchSignedUrl($fileId, 24);
                $result = $client->processOcr(MistralOcrClient::documentUrlRef($signedUrl), ['type' => 'object']);

                Assert::same(3, count($calls));
                Assert::same('https://api.mistral.ai/v1/files', $calls[0]['url']);
                $multipart = $calls[0]['options']['multipart'];
                Assert::same('ocr', $multipart[1]['content'], 'purpose=ocr per official Files API');
                Assert::same('application/pdf', $multipart[0]['headers']['Content-Type']);
                Assert::same('https://api.mistral.ai/v1/files/file-abc-123/url', $calls[1]['url']);
                Assert::same(24, $calls[1]['options']['query']['expiry']);
                Assert::same('document_url', $calls[2]['options']['json']['document']['type']);
                Assert::same('https://mrlists.example.invalid/signed/TOKEN-NOT-LOGGED', $calls[2]['options']['json']['document']['document_url']);
                Assert::same('mistral-ocr-2506', $result['model']);
            }),

            TestResult::check('HTTP failures normalize to safe categorized exceptions without leaking the key', function (): void {
                $cases = [
                    [401, '{"message":"Unauthorized: invalid API key supplied"}', MistralOcrException::AUTHENTICATION],
                    [403, '{"message":"Access denied"}', MistralOcrException::AUTHENTICATION],
                    [429, '{"error":{"type":"rate_limited","message":"Too many requests"}}', MistralOcrException::RATE_LIMIT],
                    [422, '{"detail":[{"type":"missing","loc":["body","model"],"msg":"Field required"}]}', MistralOcrException::INVALID_REQUEST],
                    [500, 'not even json', MistralOcrException::PROVIDER_FAILURE],
                ];

                foreach ($cases as [$status, $body, $expectedCategory]) {
                    [$client] = self::clientWithResponses([['status' => $status, 'body' => $body]]);

                    try {
                        $client->processOcr(['type' => 'image_url', 'image_url' => 'data:image/png;base64,AA']);
                        Assert::true(false, 'expected failure for HTTP ' . $status);
                    } catch (MistralOcrException $e) {
                        Assert::same($expectedCategory, $e->category(), 'category for ' . $status);
                        Assert::same($status, $e->status());
                        Assert::notContains(self::FAKE_KEY, $e->getMessage(), 'key never appears in error text');
                        Assert::notContains('Bearer', $e->getMessage(), 'no bearer material in error text');
                        Assert::true(strlen($e->getMessage()) < 260, 'messages stay bounded');
                    }
                }

                // Transport-level failure → network category, underlying retained.
                $failing = new MistralOcrClient(self::FAKE_KEY, 'm', static function (): array {
                    throw new \RuntimeException('connection refused');
                });

                try {
                    $failing->processOcr(['type' => 'image_url', 'image_url' => 'data:image/png;base64,AA']);
                    Assert::true(false, 'expected network failure normalization');
                } catch (MistralOcrException $e) {
                    Assert::same(MistralOcrException::NETWORK, $e->category());
                    Assert::true($e->getPrevious() !== null, 'raw failure kept for server-side diagnostics only');
                }
            }),

            TestResult::check('sanitized error message strips bearer-like material and bounds length', function (): void {
                $verbose = 'Bearer supersecret-token-value ' . str_repeat('x', 500);
                $exception = MistralOcrException::fromHttpStatus(400, ['message' => $verbose]);

                $message = $exception->getMessage();
                Assert::notContains('supersecret-token-value', $message);
                Assert::contains('[redacted]', $message);
                Assert::true(strlen($message) <= 300, 'sanitized excerpt is bounded');
                Assert::same('HTTP 400', substr($message, strpos($message, 'HTTP 400'), 8));
            }),

            TestResult::check('annotation JSON parsing: valid string, malformed string, absent, garbage response', function (): void {
                $parsed = MistralOcrClient::parseOcrResponse(json_decode(self::ocrSuccessBody(), true) ?: []);
                Assert::same(0.98, $parsed['annotation']['document_assessment']['confidence']);

                $malformed = MistralOcrClient::parseAnnotation('{"unclosed": ');
                Assert::same(null, $malformed['value']);
                Assert::true($malformed['error'] !== null, 'malformed annotation is reported, never repaired');

                $absent = MistralOcrClient::parseAnnotation(null);
                Assert::same(null, $absent['value']);
                Assert::same(null, $absent['error'], 'absent annotation is not an error');

                $garbage = MistralOcrClient::parseOcrResponse([
                    'pages' => 'not-a-list',
                    'document_annotation' => 42,
                    'usage_info' => ['input_tokens' => 'not-numeric', 'x' => ['deep' => true]],
                ]);
                Assert::same([], $garbage['pages'], 'unknown shapes normalize to empty, never guessed');
                Assert::same(null, $garbage['annotation']);
                Assert::true($garbage['annotation_error'] !== null);
                Assert::same(['x' => ['deep' => true]], $garbage['usage'], 'non-numeric junk dropped, safe booleans kept');
            }),
        ];
    }
}
