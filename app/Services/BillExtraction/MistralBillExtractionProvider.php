<?php

declare(strict_types=1);

namespace App\Services\BillExtraction;

use App\Services\Mistral\MistralOcrClient;
use App\Services\Mistral\MistralOcrException;

/**
 * Bill document extraction provider backed by Mistral OCR (primary).
 *
 * Thin adapter: wraps the existing MistralOcrClient — NO duplication of
 * HTTP logic. Maps MistralOcrException categories to BillExtractionFailure
 * categories so the gateway can decide whether to fall through to the next
 * provider.
 *
 * Transient → fallback eligible:
 *   RATE_LIMIT   (HTTP 429)
 *   NETWORK      (transport failure, timeout)
 *   PROVIDER_FAILURE (5xx)
 *
 * Terminal → do NOT fall through:
 *   AUTHENTICATION   (bad key / 401/403)
 *   CONFIGURATION    (key not configured at all)
 *   INVALID_REQUEST  (unsupported doc type / bad request from our side)
 */
final class MistralBillExtractionProvider implements BillDocumentExtractionProviderInterface
{
    private readonly MistralOcrClient $ocr;

    public function __construct(?MistralOcrClient $client = null)
    {
        $this->ocr = $client ?? new MistralOcrClient();
    }

    public function isConfigured(): bool
    {
        return $this->ocr->hasApiKey();
    }

    public function providerName(): string
    {
        return 'mistral';
    }

    public function modelId(): ?string
    {
        return $this->ocr->model();
    }

    public function supportedMimeTypes(): array
    {
        return MistralOcrClient::SUPPORTED_MIME_TYPES;
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

        try {
            $validated = MistralOcrClient::validateLocalDocument($filePath);

            if ($validated['mime'] === 'application/pdf') {
                $document = MistralOcrClient::documentUrlRef(
                    $this->ocr->fetchSignedUrl($this->ocr->uploadDocument($validated), 24)
                );
            } else {
                $document = MistralOcrClient::imageDataUrlDocument($validated);
            }

            $result = $this->ocr->processOcr($document, $schema, $prompt);
        } catch (MistralOcrException $e) {
            throw $this->mapMistralException($e);
        }

        $annotation = is_array($result['annotation'] ?? null) ? $result['annotation'] : null;
        $model = is_string($result['model'] ?? null) && $result['model'] !== ''
            ? $result['model']
            : $this->ocr->model();

        return [
            'annotation' => $annotation,
            'model'      => $model,
            'provider'   => $this->providerName(),
        ];
    }

    private function mapMistralException(MistralOcrException $e): BillExtractionFailure
    {
        return match ($e->category()) {
            MistralOcrException::RATE_LIMIT       => BillExtractionFailure::transient($this->providerName(), 'Mistral OCR rate limit reached (HTTP 429).', $e->status(), $e),
            MistralOcrException::NETWORK          => BillExtractionFailure::transient($this->providerName(), 'Mistral OCR network/transport failure.', null, $e),
            MistralOcrException::PROVIDER_FAILURE => BillExtractionFailure::transient($this->providerName(), 'Mistral OCR server error.', $e->status(), $e),
            MistralOcrException::AUTHENTICATION   => BillExtractionFailure::authFailed($this->providerName(), $e->status()),
            MistralOcrException::CONFIGURATION    => BillExtractionFailure::notConfigured($this->providerName()),
            MistralOcrException::INVALID_REQUEST  => BillExtractionFailure::invalidDocument($this->providerName(), 'Document rejected by Mistral OCR.'),
            default                               => BillExtractionFailure::transient($this->providerName(), 'Mistral OCR failed.', $e->status(), $e),
        };
    }
}
