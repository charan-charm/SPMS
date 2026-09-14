<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use InvalidArgumentException;

/**
 * B1 — One deterministic validation outcome (docs/10 D-067 §10).
 *
 * Value object for a single rule result. It carries the numbers needed to
 * EXPLAIN itself — expected vs extracted (signs preserved, both up to
 * three decimal places like the energy columns) — and nothing else. There
 * is deliberately no field that could hold raw OCR or provider text: the
 * shape is the minimization guarantee.
 *
 * The VO never mutates anything: it cannot touch the extracted value it
 * describes, and persistence stores it as-is — a CONFLICT is a report,
 * not a correction.
 */
final class BillValidation
{
    private function __construct(
        private readonly string $id,
        private readonly string $rule,
        private readonly string $status,
        private readonly ?string $expectedValue,
        private readonly ?string $extractedValue,
        private readonly ?string $reasonCode
    ) {
    }

    public static function create(
        string $rule,
        string $status,
        ?string $expectedValue,
        ?string $extractedValue,
        ?string $reasonCode = null
    ): self {
        if (!ValidationRule::isValid($rule)) {
            throw new InvalidArgumentException('unknown validation rule: ' . $rule);
        }

        if (!ValidationStatus::isValid($status)) {
            throw new InvalidArgumentException('unknown validation status: ' . $status);
        }

        if (!ValidationStatus::isReasonValid($reasonCode)) {
            throw new InvalidArgumentException('unknown validation reason code: ' . $reasonCode);
        }

        if ($status !== ValidationStatus::NOT_CHECKED && ($expectedValue === null || $extractedValue === null)) {
            throw new InvalidArgumentException(
                'a ' . $status . ' validation must carry both expected and extracted values.'
            );
        }

        if ($status === ValidationStatus::NOT_CHECKED && $expectedValue !== null && $extractedValue !== null) {
            throw new InvalidArgumentException(
                'NOT_CHECKED must leave the unavailable operand(s) null (NULL = unknown, §15).'
            );
        }

        if ($status === ValidationStatus::NOT_CHECKED && $reasonCode === null) {
            throw new InvalidArgumentException(
                'NOT_CHECKED requires a whitelisted reason code explaining what was missing.'
            );
        }

        return new self(
            id: self::uuid(),
            rule: $rule,
            status: $status,
            expectedValue: self::decimalOrNull($expectedValue, 'expected_value'),
            extractedValue: self::decimalOrNull($extractedValue, 'extracted_value'),
            reasonCode: $reasonCode
        );
    }

    public static function fromStorageRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            rule: (string) $row['rule'],
            status: (string) $row['status'],
            expectedValue: $row['expected_value'] !== null ? (string) $row['expected_value'] : null,
            extractedValue: $row['extracted_value'] !== null ? (string) $row['extracted_value'] : null,
            reasonCode: $row['reason_code'] !== null ? (string) $row['reason_code'] : null
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function rule(): string
    {
        return $this->rule;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function expectedValue(): ?string
    {
        return $this->expectedValue;
    }

    public function extractedValue(): ?string
    {
        return $this->extractedValue;
    }

    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageRow(string $billId, string $now): array
    {
        return [
            'id' => $this->id,
            'bill_id' => $billId,
            'rule' => $this->rule,
            'status' => $this->status,
            'expected_value' => $this->expectedValue,
            'extracted_value' => $this->extractedValue,
            'reason_code' => $this->reasonCode,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function decimalOrNull(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^-?\d{1,11}(\.\d{1,3})?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                $label . ' must be a signed decimal string with at most 3 decimal places.'
            );
        }

        return $value;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
