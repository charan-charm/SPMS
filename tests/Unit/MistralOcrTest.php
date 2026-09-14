<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mistral\MistralBillValidator;
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
                    'markdown' => "# BILL\n[tbl-0](tbl-0)",
                    'images' => [],
                    'tables' => [[
                        'id' => 'tbl-0',
                        'content' => "| Item | Amount |\n|---|---|\n| Fixed charges | 30 |\n| Energy charges | 11 |",
                        'format' => 'markdown',
                    ]],
                    'blocks' => [['text' => 'a'], ['text' => 'b']],
                    'confidence_scores' => ['block' => ['min' => 0.91, 'mean' => 0.97]],
                ],
            ],
            'document_annotation' => '{"document_assessment":{"status":"VALID","reason":"clear","confidence":0.98},'
                . '"cost":{"total_amount":521.5,"energy_charges":11,"fixed_charges":30,"other_charges":null,'
                . '"customer_service_charges":25,"taxes_adjustments":null,"confidence":0.9}}',
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
                Assert::same(1, count($page['tables']), 'one table object parsed');
                Assert::same('tbl-0', $page['tables'][0]['id']);
                Assert::contains('| Fixed charges | 30 |', $page['tables'][0]['content'], 'actual returned table content is kept verbatim');
                Assert::same('markdown', $page['tables'][0]['format']);
                Assert::same(0, $page['images']);
                Assert::same(0.91, $page['confidence_scores']['block']['min']);
                Assert::same(['input_tokens' => 1200, 'output_tokens' => 340], $result['usage']);
                Assert::same('VALID', $result['annotation']['document_assessment']['status']);
                Assert::same(521.5, $result['annotation']['cost']['total_amount'], 'explicit total passes through untouched');
                Assert::same(11, $result['annotation']['cost']['energy_charges'], 'component charges stay separate from the total');
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

            TestResult::check('total_amount targeting: schema description + prompt explicitly forbid reconstruction', function (): void {
                $schema = MistralOcrClient::billAnnotationSchema();
                $total = $schema['properties']['cost']['properties']['total_amount'];

                Assert::same(['number', 'null'], $total['type'], 'stays nullable');
                Assert::true(isset($total['description']) && is_string($total['description']), 'total_amount has an explicit description');
                Assert::contains('explicitly displayed final', $total['description']);
                Assert::contains('Never a calculated or reconstructed sum', $total['description']);

                $prompt = MistralOcrClient::BILL_ANNOTATION_PROMPT;
                Assert::contains('Extract total_amount only when the document explicitly identifies', $prompt);
                Assert::contains('Do not calculate or reconstruct total_amount from component charges', $prompt);
                Assert::contains('return null', $prompt);
                Assert::contains('never collapse or merge them', $prompt, 'component charges stay distinct');
                // The §5 base instructions remain.
                Assert::contains('Do not treat cumulative meter readings as monthly consumption', $prompt);
                Assert::contains('deterministic validation', $prompt);
            }),

            TestResult::check('missing explicit total stays null through parsing; components never merged', function (): void {
                $raw = '{"cost":{"total_amount":null,"energy_charges":11,"fixed_charges":30,'
                    . '"other_charges":null,"customer_service_charges":25,"taxes_adjustments":null,"confidence":0.7}}';
                $parsed = MistralOcrClient::parseAnnotation($raw);

                Assert::same(null, $parsed['value']['cost']['total_amount'], 'null total passes through, never fabricated from 11+30+25');
                Assert::same(11, $parsed['value']['cost']['energy_charges']);
                Assert::same(30, $parsed['value']['cost']['fixed_charges']);
                Assert::same(25, $parsed['value']['cost']['customer_service_charges']);
            }),

            TestResult::check('table representation follows the official OCRTableObject (content kept verbatim, never invented)', function (): void {
                // No tables field at all → null; empty list → []; non-string
                // content → empty string; oversized content → visibly bounded.
                $absent = MistralOcrClient::parseOcrResponse(['pages' => [['index' => 0, 'markdown' => 'x']]]);
                Assert::same(null, $absent['pages'][0]['tables'], 'absent tables field is not presented as an empty result');
                Assert::same(0, MistralOcrClient::tableCount($absent['pages'][0]['tables']));

                $empty = MistralOcrClient::parseOcrResponse(['pages' => [['index' => 0, 'markdown' => 'x', 'tables' => []]]]);
                Assert::same([], $empty['pages'][0]['tables']);
                Assert::same(0, MistralOcrClient::tableCount($empty['pages'][0]['tables']));

                $weird = MistralOcrClient::parseOcrResponse([
                    'pages' => [['index' => 0, 'markdown' => 'x', 'tables' => [['id' => 'tbl-0', 'content' => 12345], 'junk']]],
                ]);
                Assert::same(1, count($weird['pages'][0]['tables']));
                Assert::same('', $weird['pages'][0]['tables'][0]['content'], 'non-string content is never converted into fake markdown');

                $big = str_repeat('| c |\n', MistralOcrClient::MAX_DISPLAY_CHARS);
                $truncated = MistralOcrClient::parseOcrResponse([
                    'pages' => [['index' => 0, 'markdown' => '', 'tables' => [['id' => 't', 'content' => $big, 'format' => 'markdown']]]],
                ]);
                $content = $truncated['pages'][0]['tables'][0]['content'];
                Assert::contains('truncated', $content, 'display bounding is visible, not silent truncation');
                Assert::true(strlen($content) < strlen($big), 'retained content is bounded');
            }),

            TestResult::check('deterministic validation: PASS on the known live bill relationships', function (): void {
                $annotation = [
                    'consumption' => ['consumption_kwh' => 145],
                    'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460],
                    'solar' => [
                        'previous_solar_reading' => 1878,
                        'present_solar_reading' => 2200,
                        'solar_units_kwh' => 322,
                        'net_units_kwh' => -177,
                    ],
                ];

                $result = MistralBillValidator::validate($annotation);
                Assert::same(MistralBillValidator::PASS, $result['meter']['status'], '1460 - 1315 = 145');
                Assert::same(MistralBillValidator::PASS, $result['solar']['status'], '2200 - 1878 = 322');
                Assert::same(MistralBillValidator::PASS, $result['net']['status'], '145 - 322 = -177');
            }),

            TestResult::check('deterministic validation: missing or non-numeric values are NOT_CHECKED, never guessed', function (): void {
                $result = MistralBillValidator::validate(null);
                Assert::same(MistralBillValidator::NOT_CHECKED, $result['meter']['status']);
                Assert::same(MistralBillValidator::NOT_CHECKED, $result['solar']['status']);
                Assert::same(MistralBillValidator::NOT_CHECKED, $result['net']['status']);

                $partial = MistralBillValidator::validate([
                    'consumption' => ['consumption_kwh' => 'not-a-number'],
                    'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => null],
                    'solar' => ['previous_solar_reading' => 1878, 'present_solar_reading' => 2200, 'solar_units_kwh' => 322],
                ]);
                Assert::same(MistralBillValidator::NOT_CHECKED, $partial['meter']['status'], 'null/non-numeric members keep the check unrun');
                Assert::same(MistralBillValidator::PASS, $partial['solar']['status'], 'complete relationships still validate');
                Assert::same(MistralBillValidator::NOT_CHECKED, $partial['net']['status']);
            }),

            TestResult::check('deterministic validation: CONFLICT reports compared values without correcting the AI result', function (): void {
                $annotation = [
                    'consumption' => ['consumption_kwh' => 200],
                    'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460],
                    'solar' => [
                        'previous_solar_reading' => 1878,
                        'present_solar_reading' => 2200,
                        'solar_units_kwh' => 300,
                        'net_units_kwh' => -50,
                    ],
                ];

                $before = $annotation;
                $result = MistralBillValidator::validate($annotation);

                Assert::same(MistralBillValidator::CONFLICT, $result['meter']['status']);
                Assert::same(145.0, $result['meter']['expected'], 'expected = present - previous');
                Assert::same(200.0, $result['meter']['consumption_kwh'], 'the extracted (conflicting) value is reported as-is');
                Assert::same(MistralBillValidator::CONFLICT, $result['solar']['status']);
                Assert::same(322.0, $result['solar']['expected']);
                Assert::same(MistralBillValidator::CONFLICT, $result['net']['status']);
                Assert::same(-100.0, $result['net']['expected'], 'expected net = consumption - solar = 200 - 300');
                Assert::same(-50.0, $result['net']['net_units_kwh'], 'conflicting extracted value is reported, never rewritten');

                // Core safety: the validator NEVER touches the extracted values.
                Assert::same($before, $annotation, 'input annotation is not modified in any way');
            }),

            TestResult::check('REGRESSION: prompt/schema teach the observed row semantics (generalized, not per-bill)', function (): void {
                $prompt = MistralOcrClient::BILL_ANNOTATION_PROMPT;

                foreach ([
                    'KWH with PRESENT and PREVIOUS values represents cumulative meter readings',
                    'Do not map either cumulative reading to monthly consumption',
                    'BILLED UNITS',
                    'BILL UNITS',
                    'CONSUMPTION',
                    'UNITS CONSUMED',
                    'Map the current billing-period BILLED UNITS value to consumption_kwh',
                    'Do not use the previous-period BILLED UNITS value as the current consumption',
                    'SOLARKWH with PRESENT and PREVIOUS values represents cumulative solar generation readings',
                    'SOLAR UNITS represents the billing-period solar generation quantity',
                    'NET UNITS represents the billing-period net energy quantity',
                    'Preserve its sign exactly. Do not replace a negative value with zero',
                    'the first/current value is the current billing period',
                    'Do not use arithmetic to invent missing extraction fields',
                    'start is the earlier date and end is the later date (start <= end)',
                    'Never infer dates that are not visibly supported',
                    'other_charges merely because it is not classified elsewhere; when classification is ambiguous prefer null',
                ] as $sentence) {
                    Assert::contains($sentence, $prompt, 'prompt must carry: ' . $sentence);
                }

                $schema = MistralOcrClient::billAnnotationSchema();
                $props = $schema['properties'];

                Assert::contains('BILLED UNITS', $props['consumption']['properties']['consumption_kwh']['description']);
                Assert::contains('Evidence only', $props['meter_evidence']['properties']['previous_meter_reading']['description']);
                Assert::contains('Evidence only', $props['meter_evidence']['properties']['present_meter_reading']['description']);
                Assert::contains('SOLARKWH', $props['solar']['properties']['present_solar_reading']['description']);
                Assert::contains('not a cumulative SOLARKWH reading', $props['solar']['properties']['solar_units_kwh']['description']);
                Assert::contains('never become zero', $props['solar']['properties']['net_units_kwh']['description']);
                Assert::contains('start <= end', $props['billing_period']['properties']['start']['description']);
            }),

            TestResult::check('REGRESSION: full parser→annotation→validator flow on the exact live-shape bill (three PASS)', function (): void {
                // Semantic structure of the real bill (test data only —
                // the image/PDF stays outside the repository). The
                // annotation is supplied as a JSON STRING inside a real
                // OCRResponse envelope, exercising the actual parse path.
                $annotationJson = (string) json_encode([
                    'document_assessment' => ['status' => 'VALID', 'reason' => 'clear', 'confidence' => 0.99],
                    'billing_period' => ['start' => '2026-08-01', 'end' => '2026-09-01', 'confidence' => 0.95],
                    'consumption' => ['consumption_kwh' => 145, 'confidence' => 0.98],
                    'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460, 'confidence' => 0.99],
                    'solar' => [
                        'previous_solar_reading' => 1878,
                        'present_solar_reading' => 2200,
                        'solar_units_kwh' => 322,
                        'net_units_kwh' => -177,
                        'confidence' => 0.97,
                    ],
                    'cost' => [
                        'total_amount' => null,
                        'energy_charges' => null,
                        'fixed_charges' => 30,
                        'other_charges' => null,
                        'customer_service_charges' => 25,
                        'taxes_adjustments' => null,
                        'confidence' => 0.9,
                    ],
                    'context' => [
                        'tariff_category' => 'DOMESTIC (A)',
                        'meter_type' => 'SMART SOLAR',
                        'contracted_load' => '3',
                        'utility_provider' => null,
                        'confidence' => 0.95,
                    ],
                ]);

                $parsed = MistralOcrClient::parseOcrResponse([
                    'model' => 'mistral-ocr-latest',
                    'pages' => [[
                        'index' => 0,
                        'markdown' => '[tbl-0](tbl-0)',
                        'images' => [],
                        'tables' => [[
                            'id' => 'tbl-0',
                            'content' => "KWH | 1460 1315\nSOLARKWH | 2200 1878\nBILLED UNITS | 145.0 172.0\n"
                                . "SOLAR UNITS | 322.0/0.0\nNET UNITS | -177.0/0.0\nBILL PERIOD | 01.09.26 01.08.26",
                            'format' => 'markdown',
                        ]],
                    ]],
                    'document_annotation' => $annotationJson,
                    'usage_info' => ['input_tokens' => 1500, 'output_tokens' => 400],
                ]);

                // The table row that also contains the previous-period
                // value 172 is retained as evidence text, and the
                // parsed consumption must be the CURRENT 145.
                Assert::contains('172.0', $parsed['pages'][0]['tables'][0]['content']);
                $annotation = $parsed['annotation'];
                Assert::same(145, $annotation['consumption']['consumption_kwh'], 'BILLED UNITS current value — never 172');
                Assert::same(1315, $annotation['meter_evidence']['previous_meter_reading']);
                Assert::same(1460, $annotation['meter_evidence']['present_meter_reading']);
                Assert::same(1878, $annotation['solar']['previous_solar_reading']);
                Assert::same(2200, $annotation['solar']['present_solar_reading']);
                Assert::same(322, $annotation['solar']['solar_units_kwh']);
                Assert::same(-177, $annotation['solar']['net_units_kwh'], 'negative sign preserved through the real parser');
                Assert::same('2026-08-01', $annotation['billing_period']['start'], 'chronological, not reversed');
                Assert::same('2026-09-01', $annotation['billing_period']['end']);
                Assert::same(null, $annotation['cost']['total_amount'], 'absent explicit total stays null after full flow');

                $validation = MistralBillValidator::validate($annotation);
                Assert::same(MistralBillValidator::PASS, $validation['meter']['status']);
                Assert::same(MistralBillValidator::PASS, $validation['solar']['status']);
                Assert::same(MistralBillValidator::PASS, $validation['net']['status']);
            }),

            TestResult::check('REGRESSION: validator labels can never describe another field (the 322-under-consumption bug)', function (): void {
                // Exact incident replay: consumption_kwh NULL while
                // solar_units_kwh = 322 and net_units_kwh = 0.
                $annotation = [
                    'consumption' => ['consumption_kwh' => null],
                    'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460],
                    'solar' => [
                        'previous_solar_reading' => 1878,
                        'present_solar_reading' => 2200,
                        'solar_units_kwh' => 322,
                        'net_units_kwh' => 0,
                    ],
                ];

                $result = MistralBillValidator::validate($annotation);

                // Meter: null consumption must stay null under ITS OWN key.
                Assert::same(MistralBillValidator::NOT_CHECKED, $result['meter']['status']);
                Assert::same(null, $result['meter']['consumption_kwh'], 'consumption_kwh describes only consumption/consumption_kwh');
                Assert::same(1315.0, $result['meter']['previous_meter_reading']);
                Assert::same(1460.0, $result['meter']['present_meter_reading']);

                // Net: the historical bug reported 322 under
                // 'consumption_kwh' here. Values must now be exact.
                Assert::same(MistralBillValidator::NOT_CHECKED, $result['net']['status']);
                Assert::same(null, $result['net']['consumption_kwh'], 'solar_units must never surface under the consumption label');
                Assert::same(322.0, $result['net']['solar_units_kwh'], 'solar_units_kwh keeps its own label');
                Assert::same(0.0, $result['net']['net_units_kwh'], 'zero extracted net is reported as exactly 0, not hidden');
            }),

            TestResult::check('REGRESSION: zero net units with real consumption/solar produces CONFLICT; negatives preserved', function (): void {
                $conflict = MistralBillValidator::validate([
                    'consumption' => ['consumption_kwh' => 145],
                    'meter_evidence' => ['previous_meter_reading' => 1315, 'present_meter_reading' => 1460],
                    'solar' => [
                        'previous_solar_reading' => 1878,
                        'present_solar_reading' => 2200,
                        'solar_units_kwh' => 322,
                        'net_units_kwh' => 0,
                    ],
                ]);

                Assert::same(MistralBillValidator::PASS, $conflict['meter']['status']);
                Assert::same(MistralBillValidator::PASS, $conflict['solar']['status']);
                Assert::same(MistralBillValidator::CONFLICT, $conflict['net']['status'], '0 net against 145-322 must conflict');
                Assert::same(-177.0, $conflict['net']['expected']);
                Assert::same(0.0, $conflict['net']['net_units_kwh'], 'the AI value is reported, never corrected');
                Assert::contains('must equal consumption_kwh - solar_units_kwh', $conflict['net']['reason'], 'formula direction is labelled correctly');

                // Correctly extracted negative net → PASS.
                $ok = MistralBillValidator::validate([
                    'consumption' => ['consumption_kwh' => 145],
                    'solar' => ['solar_units_kwh' => 322, 'net_units_kwh' => -177],
                ]);
                Assert::same(MistralBillValidator::PASS, $ok['net']['status']);
            }),

            TestResult::check('REGRESSION: confidence semantics stay separate (no implied field confidence)', function (): void {
                $parsed = MistralOcrClient::parseAnnotation(
                    '{"document_assessment":{"status":"VALID","reason":"ok","confidence":0.99},'
                    . '"consumption":{"consumption_kwh":145,"confidence":0.42}}'
                );

                Assert::same(0.99, $parsed['value']['document_assessment']['confidence']);
                Assert::same(0.42, $parsed['value']['consumption']['confidence'], 'field confidence is only ever what the model returned');
                Assert::true(!isset($parsed['value']['meter_evidence']), 'absent sections are not backfilled with document-level confidence');
            }),
        ];
    }
}
