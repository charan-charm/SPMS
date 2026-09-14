<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * B1 — The immutable AI extraction draft (docs/10 D-067 §7).
 *
 * A normalized record of what the extraction pipeline produced and the
 * document-level facts around that extraction (provider, model, moment,
 * assessment, overall confidence). It is constructed once, persisted once
 * and never updated: user corrections change the bills row and its field
 * provenance, but the draft keeps the original AI values forever — that
 * separation IS the draft-vs-authoritative mechanism (§7). No raw
 * provider payload exists on this object; only whitelisted fields do
 * (§2/§9).
 *
 * Field-level confidence intentionally does NOT live here: it belongs to
 * bill_field_provenance (a per-field fact), while document_confidence is
 * a per-extraction fact — keeping them in different records makes the
 * "document confidence quietly becomes every field's confidence" bug
 * structurally impossible (§14).
 */
final class BillExtractionDraft
{
    /** @var array<string, string|null> */
    private readonly array $values;

    /** @var array<string, string|null> field => per-field confidence */
    private readonly array $confidences;

    private function __construct(
        private readonly string $id,
        private readonly string $billId,
        private readonly string $provider,
        private readonly ?string $model,
        private readonly string $extractedAt,
        private readonly string $assessment,
        private readonly ?string $assessmentReason,
        private readonly ?string $documentConfidence,
        array $values,
        array $confidences,
        private readonly string $createdAt
    ) {
        if ($billId === '' || mb_strlen($billId) > 36) {
            throw new InvalidArgumentException('bill id is invalid.');
        }

        if (trim($provider) === '' || mb_strlen($provider) > 64) {
            throw new InvalidArgumentException('extraction provider must be a non-empty string of at most 64 characters.');
        }

        if ($model !== null && trim($model) === '') {
            throw new InvalidArgumentException('extraction model must be null or a non-empty string.');
        }

        if (!DocumentAssessment::isValid($assessment)) {
            throw new InvalidArgumentException('unknown document assessment: ' . $assessment);
        }

        if ($assessmentReason !== null && mb_strlen($assessmentReason) > 255) {
            throw new InvalidArgumentException('document assessment reason must be at most 255 characters.');
        }

        $normalized = [];
        foreach (BillField::ALL as $field) {
            $normalized[$field] = BillField::validate($field, $values[$field] ?? null);
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($values)), BillField::ALL));
        if ($unknown !== []) {
            throw new InvalidArgumentException('draft carries a non-persistable field: ' . implode(', ', $unknown));
        }
        BillField::assertChronology(
            $normalized[BillField::BILLING_PERIOD_START],
            $normalized[BillField::BILLING_PERIOD_END]
        );

        $normalizedConfidences = [];
        foreach ($confidences as $field => $confidence) {
            if (!is_string($field) || !BillField::isPersistable($field)) {
                throw new InvalidArgumentException(
                    'confidence refers to a non-persistable field: ' . (is_string($field) ? $field : get_debug_type($field)) . '.'
                );
            }
            $normalizedConfidences[$field] = BillField::validateConfidence($field, $confidence);
        }

        $this->values = $normalized;
        $this->confidences = $normalizedConfidences;
    }

    /**
     * @param array<string, string|null> $values      whitelisted field values; NULL = "unknown"
     * @param array<string, string|null> $confidences per-field confidence, only where the provider stated one
     */
    public static function create(
        string $billId,
        string $provider,
        ?string $model,
        DateTimeImmutable $extractedAt,
        string $assessment,
        ?string $assessmentReason,
        ?string $documentConfidence,
        array $values,
        array $confidences = [],
        ?DateTimeImmutable $now = null
    ): self {
        $moment = self::stamp($extractedAt);
        $created = self::stamp($now ?? $extractedAt);

        return new self(
            id: self::uuid(),
            billId: $billId,
            provider: trim($provider),
            model: $model !== null ? trim($model) : null,
            extractedAt: $moment,
            assessment: $assessment,
            assessmentReason: $assessmentReason,
            documentConfidence: BillField::validateConfidence('document_confidence', $documentConfidence),
            values: $values,
            confidences: $confidences,
            createdAt: $created
        );
    }

    public static function fromStorageRow(array $row): self
    {
        $values = [];
        foreach (BillField::ALL as $field) {
            $values[$field] = array_key_exists($field, $row) && $row[$field] !== null
                ? (string) $row[$field]
                : null;
        }

        return new self(
            id: (string) $row['id'],
            billId: (string) $row['bill_id'],
            provider: (string) $row['extraction_provider'],
            model: $row['extraction_model'] !== null ? (string) $row['extraction_model'] : null,
            extractedAt: (string) $row['extracted_at'],
            assessment: (string) $row['document_assessment'],
            assessmentReason: $row['document_assessment_reason'] !== null ? (string) $row['document_assessment_reason'] : null,
            documentConfidence: $row['document_confidence'] !== null ? (string) $row['document_confidence'] : null,
            values: $values,
            confidences: [],
            createdAt: (string) $row['created_at']
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function billId(): string
    {
        return $this->billId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function extractedAt(): string
    {
        return $this->extractedAt;
    }

    public function assessment(): string
    {
        return $this->assessment;
    }

    public function assessmentReason(): ?string
    {
        return $this->assessmentReason;
    }

    public function documentConfidence(): ?string
    {
        return $this->documentConfidence;
    }

    /** @return array<string, string|null> */
    public function values(): array
    {
        return $this->values;
    }

    /** @return array<string, string|null> */
    public function confidences(): array
    {
        return $this->confidences;
    }

    /** @return array<string, mixed> */
    public function toStorageRow(): array
    {
        return array_merge($this->values, [
            'id' => $this->id,
            'bill_id' => $this->billId,
            'extraction_provider' => $this->provider,
            'extraction_model' => $this->model,
            'extracted_at' => $this->extractedAt,
            'document_assessment' => $this->assessment,
            'document_assessment_reason' => $this->assessmentReason,
            'document_confidence' => $this->documentConfidence,
            'created_at' => $this->createdAt,
        ]);
    }

    private static function stamp(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
