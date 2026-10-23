<?php

declare(strict_types=1);

namespace App\Services\BillExtraction;

use App\Support\Logger;
use Throwable;

/**
 * Resilient bill document extraction gateway.
 *
 * Implements sequential provider fallback specifically for bill document
 * (multimodal OCR) extraction. It is intentionally separate from the F8
 * text chat AIProviderGateway — the concerns are different:
 *   - F8: text-only completion, Gemini/Groq/OpenRouter.
 *   - Bill extraction: multimodal image/PDF document processing, Mistral
 *     OCR as primary + Gemini as fallback (and extensible to further
 *     providers if genuinely multimodal-capable ones are added).
 *
 * Fallback rules (enforced here, not in individual providers):
 *   - TRANSIENT failures (429, timeout, 5xx, network) → try next provider.
 *   - TERMINAL failures (auth, configuration, invalid document) → stop NOW.
 *     Do not call any further provider for a problem that is not transient.
 *   - Each provider is tried at most once per process() invocation.
 *   - Providers that report isConfigured()=false are skipped (no attempt).
 *   - Providers that do not support the document's MIME type are skipped.
 *
 * Provenance:
 *   The result carries `provider` (the name of the provider that succeeded)
 *   and `model` (the model identifier used) so callers can persist the
 *   extraction provenance accurately.
 *
 * Security:
 *   - API keys are never logged, returned in results, or included in
 *     exceptions that reach callers.
 *   - Diagnostic context (provider name, HTTP status) is logged server-side;
 *     safe metadata only.
 */
final class BillExtractionGateway
{
    /** @var list<BillDocumentExtractionProviderInterface> */
    private array $providers;

    /**
     * @param list<BillDocumentExtractionProviderInterface>|null $providers
     *   Null → build the default production sequence (Mistral → Gemini).
     *   Inject custom providers in tests.
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? [
            new MistralBillExtractionProvider(),
            new GeminiBillExtractionProvider(),
        ];
    }

    /**
     * Extract structured bill data from a document using the first
     * available, capable provider. Falls through on transient failures.
     *
     * @param string               $filePath  Absolute local path to temp document.
     * @param string               $mimeType  Declared MIME type of the document.
     * @param array<string, mixed> $schema    SPMS bill extraction JSON schema.
     * @param string               $prompt    SPMS bill extraction annotation prompt.
     *
     * @return array{
     *     annotation: array<mixed>|null,
     *     model: string|null,
     *     provider: string,
     *     fallback_used: bool,
     *     attempts: list<array{provider: string, status: string, error: string|null}>
     * }
     *
     * @throws BillExtractionFailure  when ALL providers have been exhausted
     *   (category=TRANSIENT, provider='gateway', message explains what happened)
     *   OR when a TERMINAL failure occurs (forwarded as-is).
     */
    public function extract(string $filePath, string $mimeType, array $schema, string $prompt): array
    {
        $attempts    = [];
        $lastFailure = null;

        foreach ($this->providers as $index => $provider) {
            // Skip unconfigured providers.
            if (!$provider->isConfigured()) {
                $attempts[] = [
                    'provider' => $provider->providerName(),
                    'status'   => 'SKIPPED',
                    'error'    => 'Provider not configured (API key missing).',
                ];
                continue;
            }

            // Skip providers that cannot handle this MIME type.
            if (!in_array($mimeType, $provider->supportedMimeTypes(), true)) {
                $attempts[] = [
                    'provider' => $provider->providerName(),
                    'status'   => 'SKIPPED',
                    'error'    => 'Provider does not support MIME type: ' . $mimeType,
                ];
                continue;
            }

            try {
                $result = $provider->extract($filePath, $mimeType, $schema, $prompt);

                $attempts[] = [
                    'provider' => $provider->providerName(),
                    'status'   => 'SUCCESS',
                    'error'    => null,
                ];

                return array_merge($result, [
                    'fallback_used' => $index > 0,
                    'attempts'      => $attempts,
                ]);
            } catch (BillExtractionFailure $e) {
                $lastFailure = $e;
                $attempts[]  = [
                    'provider' => $provider->providerName(),
                    'status'   => 'FAILED',
                    'error'    => $e->getMessage(),
                ];

                // Log the failure with safe metadata (no keys, no payloads).
                $this->logFailure($provider->providerName(), $e);

                // Terminal failures stop the cascade immediately.
                if (!$e->isTransient()) {
                    throw $e;
                }

                // Transient → continue to next provider.
            } catch (Throwable $e) {
                // Unexpected exception from a provider — treat as transient
                // (we don't know what happened, but it is not our request).
                $lastFailure = new BillExtractionFailure(
                    BillExtractionFailure::TRANSIENT,
                    $provider->providerName(),
                    'Unexpected error from provider: ' . get_class($e),
                    null,
                    $e
                );
                $attempts[] = [
                    'provider' => $provider->providerName(),
                    'status'   => 'FAILED',
                    'error'    => 'Unexpected provider exception.',
                ];

                $this->logFailure($provider->providerName(), $lastFailure);
            }
        }

        // All providers exhausted.
        throw new BillExtractionFailure(
            BillExtractionFailure::TRANSIENT,
            'gateway',
            'All configured bill extraction providers failed or are unavailable.',
            null,
            $lastFailure
        );
    }

    // ------------------------------------------------------------------

    private function logFailure(string $providerName, BillExtractionFailure $e): void
    {
        try {
            Logger::default()->error('bill.extraction.provider.failed', [
                'provider'    => $providerName,
                'category'    => $e->category,
                'http_status' => $e->httpStatus,
                // Safe safe message only — never the URL, never the body.
                'message'     => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // Logger failure must never prevent the fallback from continuing.
        }
    }
}
