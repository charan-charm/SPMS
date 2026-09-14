<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use InvalidArgumentException;

/**
 * B1 — One controlled data-quality statement (docs/10 D-067 §12).
 *
 * A whitelisted code, optionally pinned to one persistable field. The
 * pair (code, field) is the entire record — there is nowhere to put a
 * raw text reason, so free-text leakage from a provider is structurally
 * impossible. Recording a flag never touches the value it describes;
 * unknown data remains NULL in the bill, honestly.
 */
final class BillQualityFlag
{
    private function __construct(
        private readonly string $id,
        private readonly string $code,
        private readonly ?string $fieldName
    ) {
    }

    public static function create(string $code, ?string $fieldName = null): self
    {
        QualityCode::assertValid($code);

        if ($fieldName !== null) {
            if (!BillField::isPersistable($fieldName)) {
                throw new InvalidArgumentException('quality flag references a non-persistable field: ' . $fieldName);
            }
            if ($fieldName === '') {
                throw new InvalidArgumentException('quality flag field must be null or a persistable field name.');
            }
        }

        return new self(id: self::uuid(), code: $code, fieldName: $fieldName);
    }

    public static function fromStorageRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            code: (string) $row['code'],
            fieldName: $row['field_name'] !== null ? (string) $row['field_name'] : null
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function fieldName(): ?string
    {
        return $this->fieldName;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageRow(string $billId, string $now): array
    {
        return [
            'id' => $this->id,
            'bill_id' => $billId,
            'code' => $this->code,
            'field_name' => $this->fieldName,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
