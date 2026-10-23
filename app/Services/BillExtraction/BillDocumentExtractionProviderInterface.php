<?php

declare(strict_types=1);

namespace App\Services\BillExtraction;

/**
 * Bill-specific multimodal document extraction provider contract.
 *
 * Responsibilities of an implementor:
 *   - Accept validated PDF / image document bytes + MIME type.
 *   - Receive the canonical SPMS extraction schema and prompt.
 *   - Return structured extraction data in the same normalized shape
 *     the BillAnnotationNormalizer expects (`annotation` + `model`).
 *   - Expose provider/model provenance for the audit trail.
 *   - Classify provider failures using BillExtractionFailure categories.
 *
 * An implementor MUST NOT:
 *   - Perform household authorization.
 *   - Check duplicate-bill conditions.
 *   - Run canonical billing calculations.
 *   - Persist anything.
 *   - Fabricate values for missing fields (return null for unknowns).
 *
 * @see BillExtractionGateway      for sequential provider fallback
 * @see BillExtractionFailure      for the normalized failure taxonomy
 */
interface BillDocumentExtractionProviderInterface
{
    /**
     * Whether the provider has credentials and is reachable.
     * A false here causes the gateway to skip this provider (no attempt).
     */
    public function isConfigured(): bool;

    /**
     * Human-readable provider label for provenance/audit.
     * Example: "mistral", "gemini".
     */
    public function providerName(): string;

    /**
     * The model/variant that this provider will use.
     * Returns null if unknown before the call.
     */
    public function modelId(): ?string;

    /**
     * MIME types this provider can genuinely process.
     * Only 'application/pdf', 'image/jpeg', and 'image/png' are
     * expected for bill documents. A provider that cannot handle PDFs
     * must exclude 'application/pdf' from this list.
     *
     * @return string[]
     */
    public function supportedMimeTypes(): array;

    /**
     * Extract structured annotation data from a bill document.
     *
     * @param string   $filePath   Absolute local path to the temporary document.
     * @param string   $mimeType   Declared MIME type of the document.
     * @param array<string, mixed> $schema   The SPMS extraction JSON schema.
     * @param string   $prompt     The SPMS extraction annotation prompt.
     *
     * @return array{
     *     annotation: array<mixed>|null,
     *     model: string|null,
     *     provider: string
     * }
     *
     * @throws BillExtractionFailure  on any provider failure; the category
     *   determines whether the gateway should try the next provider.
     */
    public function extract(string $filePath, string $mimeType, array $schema, string $prompt): array;
}
