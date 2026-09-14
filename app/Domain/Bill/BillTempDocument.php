<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * B2 — The temporary upload reference (docs/10 D-068 §9/§10).
 *
 * The ONLY bridge between a bill and its private temporary document:
 * an opaque storage key, the normalized type, the actual size, and an
 * explicit expiry. It carries no filename, no path, no content, no
 * PII — there is nothing on this record worth keeping past retention
 * beyond the operational state itself.
 *
 * The storage key is generated here from the record's own UUID and the
 * canonical extension, so user-influenced strings can never appear in
 * it (§5/§6) and a leaked key is inert without the server-side root.
 *
 * Lifecycle: AVAILABLE -> DELETED, one-way; DELETED is a tombstone that
 * keeps cleanup idempotent (a second cleanup pass sees nothing expired
 * because findExpired only queries AVAILABLE rows).
 */
final class BillTempDocument
{
    public const STATE_AVAILABLE = 'AVAILABLE';
    public const STATE_DELETED = 'DELETED';

    public const STATES = [self::STATE_AVAILABLE, self::STATE_DELETED];

    private function __construct(
        private readonly string $id,
        private readonly string $billId,
        private readonly string $storageKey,
        private readonly string $mimeType,
        private readonly int $sizeBytes,
        private readonly string $state,
        private readonly string $createdAt,
        private readonly string $expiresAt,
        private readonly ?string $deletedAt,
        private readonly string $updatedAt
    ) {
        if (preg_match('/^[0-9a-f-]{36}\.(?:jpg|png|pdf)$/', $storageKey) !== 1) {
            throw new InvalidArgumentException('temporary document storage key is not opaque/safe.');
        }
    }

    public static function create(
        string $billId,
        string $mimeType,
        int $sizeBytes,
        int $retentionHours,
        DateTimeImmutable $now
    ): self {
        if ($billId === '' || mb_strlen($billId) > 36) {
            throw new InvalidArgumentException('bill id is invalid.');
        }

        if (!BillUpload::isCanonicalType($mimeType)) {
            throw new InvalidArgumentException('temporary documents only hold canonical upload types.');
        }

        if ($sizeBytes < 1 || $sizeBytes > BillUpload::MAX_BILL_UPLOAD_BYTES) {
            throw new InvalidArgumentException('temporary document size must be between 1 and the ratified upload maximum.');
        }

        if ($retentionHours < 1 || $retentionHours > 168) {
            throw new InvalidArgumentException('retention must be between 1 and 168 hours.');
        }

        $id = self::uuid();
        $stamp = self::stamp($now);
        $expires = self::stamp($now->modify("+{$retentionHours} hours"));

        return new self(
            id: $id,
            billId: $billId,
            storageKey: $id . '.' . BillUpload::canonicalExtension($mimeType),
            mimeType: $mimeType,
            sizeBytes: $sizeBytes,
            state: self::STATE_AVAILABLE,
            createdAt: $stamp,
            expiresAt: $expires,
            deletedAt: null,
            updatedAt: $stamp
        );
    }

    public static function fromStorageRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            billId: (string) $row['bill_id'],
            storageKey: (string) $row['storage_key'],
            mimeType: (string) $row['mime_type'],
            sizeBytes: (int) $row['size_bytes'],
            state: (string) $row['state'],
            createdAt: (string) $row['created_at'],
            expiresAt: (string) $row['expires_at'],
            deletedAt: $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
            updatedAt: (string) $row['updated_at']
        );
    }

    public function markDeleted(DateTimeImmutable $now): self
    {
        if ($this->state === self::STATE_DELETED) {
            return $this;
        }

        $stamp = self::stamp($now);

        return new self(
            $this->id,
            $this->billId,
            $this->storageKey,
            $this->mimeType,
            $this->sizeBytes,
            self::STATE_DELETED,
            $this->createdAt,
            $this->expiresAt,
            $stamp,
            $stamp
        );
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->state === self::STATE_AVAILABLE
            && self::stamp($now) >= $this->expiresAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function billId(): string
    {
        return $this->billId;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function expiresAt(): string
    {
        return $this->expiresAt;
    }

    public function deletedAt(): ?string
    {
        return $this->deletedAt;
    }

    /** @return array<string, mixed> */
    public function toStorageRow(): array
    {
        return [
            'id' => $this->id,
            'bill_id' => $this->billId,
            'storage_key' => $this->storageKey,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'state' => $this->state,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'deleted_at' => $this->deletedAt,
            'updated_at' => $this->updatedAt,
        ];
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
