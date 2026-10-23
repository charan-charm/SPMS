<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BillExtraction\BillDocumentExtractionProviderInterface;
use App\Services\BillExtraction\BillExtractionFailure;
use App\Services\BillExtraction\BillExtractionGateway;
use App\Services\BillExtraction\GeminiBillExtractionProvider;
use App\Services\BillExtraction\MistralBillExtractionProvider;
use App\Services\Mistral\MistralOcrClient;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * BillExtractionGateway — unit tests.
 *
 * Tests the provider abstraction, fallback routing, failure classification,
 * MIME-type routing, provenance capture, and security properties.
 * Zero real network calls: all providers are injected fakes.
 */
final class BillExtractionGatewayTest
{
    // ------------------------------------------------------------------
    // Fake provider builder
    // ------------------------------------------------------------------

    /**
     * Creates a fake extraction provider with configured behavior.
     */
    private static function fakeProvider(
        string $name,
        array $supportedMimes,
        ?array $successAnnotation,
        ?BillExtractionFailure $failure = null,
        bool $configured = true,
        string $model = 'fake-model-v1'
    ): BillDocumentExtractionProviderInterface {
        return new class($name, $supportedMimes, $successAnnotation, $failure, $configured, $model)
            implements BillDocumentExtractionProviderInterface
        {
            public function __construct(
                private string $provName,
                private array  $mimes,
                private ?array $annotation,
                private ?BillExtractionFailure $failure,
                private bool   $configured,
                private string $mdl
            ) {}

            public function isConfigured(): bool                   { return $this->configured; }
            public function providerName(): string                  { return $this->provName; }
            public function modelId(): ?string                      { return $this->mdl; }
            public function supportedMimeTypes(): array            { return $this->mimes; }

            public function extract(string $fp, string $mime, array $schema, string $prompt): array
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                return [
                    'annotation' => $this->annotation,
                    'model'      => $this->mdl,
                    'provider'   => $this->provName,
                ];
            }
        };
    }

    /** Minimal annotation shaped like what BillAnnotationNormalizer expects. */
    private static function validAnnotation(): array
    {
        return [
            'document_assessment' => ['status' => 'VALID', 'reason' => 'clear', 'confidence' => 0.95],
            'billing_period'      => ['start' => '2026-09-01', 'end' => '2026-09-30', 'confidence' => 0.95],
            'consumption'         => ['consumption_kwh' => 145.0, 'confidence' => 0.90],
            'meter_evidence'      => ['previous_meter_reading' => 1315.0, 'present_meter_reading' => 1460.0, 'confidence' => 0.88],
            'solar'               => ['previous_solar_reading' => 1878.0, 'present_solar_reading' => 2200.0, 'solar_units_kwh' => 322.0, 'net_units_kwh' => -177.0, 'confidence' => 0.88],
            'cost'                => ['bill_amount' => 521.5, 'arrears' => null, 'total_due' => 521.5, 'energy_charges' => 11.0, 'fixed_charges' => 30.0, 'other_charges' => null, 'customer_service_charges' => 25.0, 'taxes_adjustments' => null, 'confidence' => 0.92],
            'context'             => ['utility_provider' => 'APSPDCL', 'tariff_category' => null, 'meter_type' => null, 'contracted_load' => null, 'service_number' => '1234567890', 'confidence' => 0.93],
        ];
    }

    private static function pngPath(): string
    {
        $path = sys_get_temp_dir() . '/spms_gw_test_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat('x', 64));
        return $path;
    }

    // ------------------------------------------------------------------
    // Test suite
    // ------------------------------------------------------------------

    /** @return TestResult[] */
    public static function run(): array
    {
        return [

            // ---- 1. Mistral succeeds — fallback NOT called ----
            TestResult::check('gateway: Mistral success — Gemini never called', function (): void {
                $called = ['mistral' => 0, 'gemini' => 0];
                $calledRef = &$called;

                $mistral = new class($calledRef) implements BillDocumentExtractionProviderInterface {
                    public function __construct(private array &$c) {}
                    public function isConfigured(): bool { return true; }
                    public function providerName(): string { return 'mistral'; }
                    public function modelId(): ?string { return 'mistral-mock'; }
                    public function supportedMimeTypes(): array { return ['image/png']; }
                    public function extract(string $fp, string $m, array $s, string $pr): array {
                        $this->c['mistral']++;
                        return ['annotation' => [], 'model' => 'mistral-mock', 'provider' => 'mistral'];
                    }
                };
                $gemini = new class($calledRef) implements BillDocumentExtractionProviderInterface {
                    public function __construct(private array &$c) {}
                    public function isConfigured(): bool { return true; }
                    public function providerName(): string { return 'gemini'; }
                    public function modelId(): ?string { return 'gemini-mock'; }
                    public function supportedMimeTypes(): array { return ['image/png']; }
                    public function extract(string $fp, string $m, array $s, string $pr): array {
                        $this->c['gemini']++;
                        return ['annotation' => [], 'model' => 'gemini-mock', 'provider' => 'gemini'];
                    }
                };

                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();
                $result = $gw->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same(1, $called['mistral'], 'mistral called once');
                Assert::same(0, $called['gemini'], 'gemini NOT called');
                Assert::same('mistral', $result['provider']);
                Assert::same(false, $result['fallback_used']);
            }),

            // ---- 2. Mistral 429 → Gemini succeeds ----
            TestResult::check('gateway: Mistral 429 → Gemini succeeds — fallback_used=true', function (): void {
                $mistral = BillExtractionGatewayTest::fakeProvider(
                    'mistral', ['image/png'], null,
                    BillExtractionFailure::transient('mistral', 'Rate limit (HTTP 429).', 429)
                );
                $gemini = BillExtractionGatewayTest::fakeProvider('gemini', ['image/png'], BillExtractionGatewayTest::validAnnotation());
                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();
                $result = $gw->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same('gemini', $result['provider']);
                Assert::same(true, $result['fallback_used']);
                Assert::same('FAILED', $result['attempts'][0]['status']);
                Assert::same('SUCCESS', $result['attempts'][1]['status']);
            }),

            // ---- 3. Mistral timeout → Gemini succeeds ----
            TestResult::check('gateway: Mistral timeout → Gemini succeeds', function (): void {
                $mistral = BillExtractionGatewayTest::fakeProvider(
                    'mistral', ['image/png'], null,
                    BillExtractionFailure::transient('mistral', 'Network failure.', null)
                );
                $gemini = BillExtractionGatewayTest::fakeProvider('gemini', ['image/png'], BillExtractionGatewayTest::validAnnotation());
                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();
                $result = $gw->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same('gemini', $result['provider']);
                Assert::same(true, $result['fallback_used']);
            }),

            // ---- 4. Mistral 5xx → Gemini succeeds ----
            TestResult::check('gateway: Mistral 5xx → Gemini succeeds', function (): void {
                $mistral = BillExtractionGatewayTest::fakeProvider(
                    'mistral', ['image/png'], null,
                    BillExtractionFailure::transient('mistral', 'Server error.', 503)
                );
                $gemini = BillExtractionGatewayTest::fakeProvider('gemini', ['image/png'], BillExtractionGatewayTest::validAnnotation());
                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();
                $result = $gw->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same('gemini', $result['provider']);
            }),

            // ---- 5. Both providers fail → BillExtractionFailure ----
            TestResult::check('gateway: all providers fail → throws BillExtractionFailure', function (): void {
                $mistral = BillExtractionGatewayTest::fakeProvider('mistral', ['image/png'], null, BillExtractionFailure::transient('mistral', '429', 429));
                $gemini  = BillExtractionGatewayTest::fakeProvider('gemini',  ['image/png'], null, BillExtractionFailure::transient('gemini', 'timeout'));
                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();

                $threw = false;
                try {
                    $gw->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same('gateway', $e->provider);
                    Assert::same(BillExtractionFailure::TRANSIENT, $e->category);
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected BillExtractionFailure when all providers fail');
            }),

            // ---- 6. Terminal auth failure — no fallback ----
            TestResult::check('gateway: terminal auth failure stops cascade — Gemini not called', function (): void {
                $geminiCalled = 0;
                $calledRef = &$geminiCalled;

                $mistral = BillExtractionGatewayTest::fakeProvider(
                    'mistral', ['image/png'], null,
                    BillExtractionFailure::authFailed('mistral', 401)
                );
                $gemini = new class($calledRef) implements BillDocumentExtractionProviderInterface {
                    public function __construct(private int &$c) {}
                    public function isConfigured(): bool { return true; }
                    public function providerName(): string { return 'gemini'; }
                    public function modelId(): ?string { return null; }
                    public function supportedMimeTypes(): array { return ['image/png']; }
                    public function extract(string $fp, string $m, array $s, string $pr): array {
                        $this->c++;
                        return ['annotation' => [], 'model' => null, 'provider' => 'gemini'];
                    }
                };

                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();

                $threw = false;
                try {
                    $gw->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TERMINAL_AUTH, $e->category);
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected BillExtractionFailure for terminal auth');
                Assert::same(0, $geminiCalled, 'Gemini must NOT be called after terminal auth failure');
            }),

            // ---- 7. Unconfigured provider is skipped ----
            TestResult::check('gateway: unconfigured provider skipped — next provider used', function (): void {
                $mistral = BillExtractionGatewayTest::fakeProvider('mistral', ['image/png'], null, null, configured: false);
                $gemini  = BillExtractionGatewayTest::fakeProvider('gemini',  ['image/png'], BillExtractionGatewayTest::validAnnotation());
                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();
                $result = $gw->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same('gemini', $result['provider']);
                Assert::same('SKIPPED', $result['attempts'][0]['status']);
                Assert::same('mistral', $result['attempts'][0]['provider']);
                Assert::same('SUCCESS', $result['attempts'][1]['status']);
            }),

            // ---- 8. Provider does not support MIME type — skipped ----
            TestResult::check('gateway: PDF-only provider skips image; image provider skips PDF', function (): void {
                $imageOnly = BillExtractionGatewayTest::fakeProvider('image_only', ['image/png', 'image/jpeg'], null, null);
                $full      = BillExtractionGatewayTest::fakeProvider('full', ['application/pdf', 'image/png', 'image/jpeg'], BillExtractionGatewayTest::validAnnotation());
                $gw = new BillExtractionGateway([$imageOnly, $full]);

                $pdfPath = sys_get_temp_dir() . '/spms_gw_pdf_' . bin2hex(random_bytes(4)) . '.pdf';
                file_put_contents($pdfPath, "%PDF-1.4\n" . str_repeat('d', 64) . "\n%%EOF\n");

                $result = $gw->extract($pdfPath, 'application/pdf', [], 'prompt');
                @unlink($pdfPath);

                Assert::same('full', $result['provider']);
                Assert::same('SKIPPED', $result['attempts'][0]['status']);
            }),

            // ---- 9. Provenance in result ----
            TestResult::check('gateway: provider name and model carried in result', function (): void {
                $gemini = BillExtractionGatewayTest::fakeProvider('gemini', ['image/png'], BillExtractionGatewayTest::validAnnotation(), null, true, 'gemini-1.5-flash');
                $gw = new BillExtractionGateway([$gemini]);
                $path = self::pngPath();
                $result = $gw->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same('gemini', $result['provider']);
                Assert::same('gemini-1.5-flash', $result['model']);
            }),

            // ---- 10. No providers configured → exception ----
            TestResult::check('gateway: all providers unconfigured throws BillExtractionFailure', function (): void {
                $unconfigured = BillExtractionGatewayTest::fakeProvider('p', ['image/png'], null, null, configured: false);
                $gw = new BillExtractionGateway([$unconfigured]);
                $path = self::pngPath();

                $threw = false;
                try {
                    $gw->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected BillExtractionFailure when all unconfigured');
            }),

            // ---- 11. Malformed response is terminal — no fallback ----
            TestResult::check('gateway: malformed response is terminal — subsequent provider not called', function (): void {
                $nextCalled = 0;
                $calledRef = &$nextCalled;

                $mistral = BillExtractionGatewayTest::fakeProvider(
                    'mistral', ['image/png'], null,
                    BillExtractionFailure::malformedResponse('mistral', 'JSON parse failed')
                );
                $gemini = new class($calledRef) implements BillDocumentExtractionProviderInterface {
                    public function __construct(private int &$c) {}
                    public function isConfigured(): bool { return true; }
                    public function providerName(): string { return 'gemini'; }
                    public function modelId(): ?string { return null; }
                    public function supportedMimeTypes(): array { return ['image/png']; }
                    public function extract(string $fp, string $m, array $s, string $pr): array {
                        $this->c++;
                        return ['annotation' => [], 'model' => null, 'provider' => 'gemini'];
                    }
                };

                $gw = new BillExtractionGateway([$mistral, $gemini]);
                $path = self::pngPath();

                $threw = false;
                try {
                    $gw->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TERMINAL_MALFORMED_RESPONSE, $e->category);
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected BillExtractionFailure for malformed response');
                Assert::same(0, $nextCalled, 'next provider must NOT be called after malformed response terminal');
            }),

            // ---- 12. Three-provider chain: Mistral 429 → Gemini timeout → backup succeeds ----
            TestResult::check('gateway: Mistral 429 → Gemini timeout → third provider succeeds', function (): void {
                $mistral = BillExtractionGatewayTest::fakeProvider('mistral', ['image/png'], null, BillExtractionFailure::transient('mistral', '429', 429));
                $gemini  = BillExtractionGatewayTest::fakeProvider('gemini',  ['image/png'], null, BillExtractionFailure::transient('gemini', 'timeout'));
                $backup  = BillExtractionGatewayTest::fakeProvider('backup',  ['image/png'], BillExtractionGatewayTest::validAnnotation());
                $gw = new BillExtractionGateway([$mistral, $gemini, $backup]);
                $path = self::pngPath();
                $result = $gw->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same('backup', $result['provider']);
                Assert::same(true, $result['fallback_used']);
                Assert::same(3, count($result['attempts']));
            }),

            // ---- 13. BillExtractionFailure::isTransient() classification ----
            TestResult::check('BillExtractionFailure: transient category is transient', function (): void {
                $e = BillExtractionFailure::transient('p', 'msg');
                Assert::true($e->isTransient(), 'transient must be transient');
            }),

            TestResult::check('BillExtractionFailure: auth failure is NOT transient', function (): void {
                $e = BillExtractionFailure::authFailed('p', 401);
                Assert::same(false, $e->isTransient(), 'auth failure is not transient');
                Assert::same(BillExtractionFailure::TERMINAL_AUTH, $e->category);
            }),

            TestResult::check('BillExtractionFailure: not-configured is NOT transient', function (): void {
                $e = BillExtractionFailure::notConfigured('p');
                Assert::same(false, $e->isTransient());
                Assert::same(BillExtractionFailure::TERMINAL_NOT_CONFIGURED, $e->category);
            }),

            TestResult::check('BillExtractionFailure: invalid document is NOT transient', function (): void {
                $e = BillExtractionFailure::invalidDocument('p', 'unsupported format');
                Assert::same(false, $e->isTransient());
                Assert::same(BillExtractionFailure::TERMINAL_INVALID_DOCUMENT, $e->category);
            }),

            TestResult::check('BillExtractionFailure: malformed response is NOT transient', function (): void {
                $e = BillExtractionFailure::malformedResponse('p', 'bad json');
                Assert::same(false, $e->isTransient());
                Assert::same(BillExtractionFailure::TERMINAL_MALFORMED_RESPONSE, $e->category);
            }),

            // ---- 14. MistralBillExtractionProvider: no key → not configured ----
            TestResult::check('MistralBillExtractionProvider: no key → isConfigured()=false', function (): void {
                $client = new MistralOcrClient('', 'mistral-ocr-latest', fn($m,$u,$o) => ['status'=>200,'body'=>'{}']);
                $p = new MistralBillExtractionProvider($client);
                Assert::same(false, $p->isConfigured());
            }),

            // ---- 15. MistralBillExtractionProvider: extract without key → terminal ----
            TestResult::check('MistralBillExtractionProvider: extract without key throws TERMINAL_NOT_CONFIGURED', function (): void {
                $client = new MistralOcrClient('', 'mistral-ocr-latest', fn($m,$u,$o) => ['status'=>200,'body'=>'{}']);
                $p = new MistralBillExtractionProvider($client);
                $threw = false;
                try {
                    $p->extract('/nonexistent', 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TERMINAL_NOT_CONFIGURED, $e->category);
                }
                Assert::true($threw, 'expected BillExtractionFailure');
            }),

            // ---- 16. GeminiBillExtractionProvider: no key → not configured ----
            TestResult::check('GeminiBillExtractionProvider: no key → isConfigured()=false', function (): void {
                $p = new GeminiBillExtractionProvider('', null, 10, fn($m,$u,$o) => ['status'=>200,'body'=>'{}']);
                Assert::same(false, $p->isConfigured());
            }),

            // ---- 17. GeminiBillExtractionProvider: 429 → transient ----
            TestResult::check('GeminiBillExtractionProvider: HTTP 429 → transient failure', function (): void {
                $handler = fn($m, $u, $o) => ['status' => 429, 'body' => '{}'];
                $p = new GeminiBillExtractionProvider('FAKE_KEY_NEVER_REAL', null, 10, $handler);
                $path = self::pngPath();
                $threw = false;
                try {
                    $p->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TRANSIENT, $e->category);
                    Assert::same(429, $e->httpStatus);
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected BillExtractionFailure for 429');
            }),

            // ---- 18. GeminiBillExtractionProvider: 401 → terminal auth ----
            TestResult::check('GeminiBillExtractionProvider: HTTP 401 → terminal auth failure', function (): void {
                $handler = fn($m, $u, $o) => ['status' => 401, 'body' => '{"error":{"message":"API key invalid"}}'];
                $p = new GeminiBillExtractionProvider('FAKE_KEY', null, 10, $handler);
                $path = self::pngPath();
                $threw = false;
                try {
                    $p->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TERMINAL_AUTH, $e->category);
                    Assert::same(false, $e->isTransient());
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected BillExtractionFailure for 401');
            }),

            // ---- 19. GeminiBillExtractionProvider: 5xx → transient ----
            TestResult::check('GeminiBillExtractionProvider: HTTP 503 → transient failure', function (): void {
                $handler = fn($m, $u, $o) => ['status' => 503, 'body' => '{}'];
                $p = new GeminiBillExtractionProvider('FAKE_KEY', null, 10, $handler);
                $path = self::pngPath();
                $threw = false;
                try {
                    $p->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TRANSIENT, $e->category);
                    Assert::same(503, $e->httpStatus);
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected BillExtractionFailure for 503');
            }),

            // ---- 20. GeminiBillExtractionProvider: 200 success ----
            TestResult::check('GeminiBillExtractionProvider: 200 success returns annotation', function (): void {
                $annotation = BillExtractionGatewayTest::validAnnotation();
                $body = json_encode([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => json_encode($annotation)]]],
                        'finishReason' => 'STOP',
                    ]],
                    'modelVersion' => 'gemini-1.5-flash-001',
                ]);
                $handler = fn($m, $u, $o) => ['status' => 200, 'body' => $body];
                $p = new GeminiBillExtractionProvider('FAKE_KEY', null, 10, $handler);
                $path = self::pngPath();
                $result = $p->extract($path, 'image/png', [], 'prompt');
                @unlink($path);

                Assert::same('gemini', $result['provider']);
                Assert::true(is_array($result['annotation']), 'annotation is array');
                Assert::same('VALID', $result['annotation']['document_assessment']['status']);
            }),

            // ---- 21. GeminiBillExtractionProvider: malformed JSON response → terminal ----
            TestResult::check('GeminiBillExtractionProvider: malformed candidate JSON → TERMINAL_MALFORMED_RESPONSE', function (): void {
                $body = json_encode([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'THIS IS NOT JSON AT ALL']]],
                        'finishReason' => 'STOP',
                    ]],
                ]);
                $handler = fn($m, $u, $o) => ['status' => 200, 'body' => $body];
                $p = new GeminiBillExtractionProvider('FAKE_KEY', null, 10, $handler);
                $path = self::pngPath();
                $threw = false;
                try {
                    $p->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TERMINAL_MALFORMED_RESPONSE, $e->category);
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected terminal failure for malformed JSON');
            }),

            // ---- 22. GeminiBillExtractionProvider: transport exception → transient ----
            TestResult::check('GeminiBillExtractionProvider: transport exception → transient failure', function (): void {
                $handler = static function($m, $u, $o) { throw new \RuntimeException('Connection refused'); };
                $p = new GeminiBillExtractionProvider('FAKE_KEY', null, 10, $handler);
                $path = self::pngPath();
                $threw = false;
                try {
                    $p->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    $threw = true;
                    Assert::same(BillExtractionFailure::TRANSIENT, $e->category);
                } finally {
                    @unlink($path);
                }
                Assert::true($threw, 'expected transient failure for transport exception');
            }),

            // ---- 23. API key never in failure message ----
            TestResult::check('GeminiBillExtractionProvider: API key does not appear in failure message', function (): void {
                $secretKey = 'SUPER_SECRET_KEY_' . bin2hex(random_bytes(8));
                $handler = fn($m, $u, $o) => ['status' => 429, 'body' => '{}'];
                $p = new GeminiBillExtractionProvider($secretKey, null, 10, $handler);
                $path = self::pngPath();
                try {
                    $p->extract($path, 'image/png', [], 'prompt');
                } catch (BillExtractionFailure $e) {
                    Assert::same(false, str_contains($e->getMessage(), $secretKey), 'API key must not appear in exception message');
                } finally {
                    @unlink($path);
                }
            }),

            // ---- 24. XLS MIME not supported by any OCR provider ----
            TestResult::check('MistralBillExtractionProvider: XLS MIME not in supported types list', function (): void {
                $client = new MistralOcrClient('FAKE_KEY', 'mistral-ocr-latest', fn($m,$u,$o) => ['status'=>200,'body'=>'{}']);
                $p = new MistralBillExtractionProvider($client);
                Assert::same(false, in_array('application/vnd.ms-excel', $p->supportedMimeTypes(), true), 'XLS must not be in Mistral OCR supported types');
            }),

            // ---- 25. GeminiBillExtractionProvider supports expected MIME types ----
            TestResult::check('GeminiBillExtractionProvider: supports PDF + PNG + JPEG but not XLS', function (): void {
                $p = new GeminiBillExtractionProvider('FAKE_KEY', null, 10, fn($m,$u,$o) => ['status'=>200,'body'=>'{}']);
                $types = $p->supportedMimeTypes();
                Assert::true(in_array('application/pdf', $types, true), 'PDF must be supported');
                Assert::true(in_array('image/jpeg', $types, true), 'JPEG must be supported');
                Assert::true(in_array('image/png', $types, true), 'PNG must be supported');
                Assert::same(false, in_array('application/vnd.ms-excel', $types, true), 'XLS must NOT be supported');
            }),

        ];
    }
}
