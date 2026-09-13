<?php

declare(strict_types=1);

namespace App\Domain\Household;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * W3 — Household entity (docs/10 D-050, D-056; docs/01 §31).
 *
 * Value object for a household row. Ownership/authorization lives in
 * memberships, never here as an account reference (D-050). The lifecycle
 * mirrors the account lifecycle vocabulary (docs/01 §31): ACTIVE is the
 * only state treated as participating in active processing;
 * DELETION_PENDING and DELETED are controlled states. Status is
 * server-assigned only — client input can never choose it.
 */
final class Household
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DELETION_PENDING = 'DELETION_PENDING';
    public const STATUS_DELETED = 'DELETED';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DELETION_PENDING, self::STATUS_DELETED];

    private function __construct(
        private readonly string $id,
        private readonly ?string $label,
        private readonly string $timezone,
        private readonly ?string $locationLabel,
        private readonly string $status,
        private readonly string $createdAt,
        private readonly string $updatedAt
    ) {
    }

    public static function create(?string $label, string $timezone, ?string $locationLabel, DateTimeImmutable $now): self
    {
        $timezone = trim($timezone);

        if ($timezone === '' || mb_strlen($timezone) > 64) {
            throw new InvalidArgumentException('timezone must be a non-empty string of at most 64 characters.');
        }

        // IANA identifier validated at construction so records never carry
        // an unusable zone (household timezone is stored explicitly).
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidArgumentException('timezone must be a valid IANA time zone identifier.');
        }

        if ($label !== null && (trim($label) === '' || mb_strlen($label) > 120)) {
            throw new InvalidArgumentException('label must be null or a non-empty string of at most 120 characters.');
        }

        if ($locationLabel !== null && mb_strlen($locationLabel) > 190) {
            throw new InvalidArgumentException('location_label must be null or a string of at most 190 characters.');
        }

        $stamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return new self(
            id: self::uuid(),
            label: $label !== null ? trim($label) : null,
            timezone: $timezone,
            locationLabel: $locationLabel,
            status: self::STATUS_ACTIVE,
            createdAt: $stamp,
            updatedAt: $stamp
        );
    }

    public static function fromStorageRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            label: $row['label'] !== null ? (string) $row['label'] : null,
            timezone: (string) $row['timezone'],
            locationLabel: $row['location_label'] !== null ? (string) $row['location_label'] : null,
            status: (string) $row['status'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function withStatus(string $status, DateTimeImmutable $now): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('unknown household status: ' . $status);
        }

        return new self(
            $this->id,
            $this->label,
            $this->timezone,
            $this->locationLabel,
            $status,
            $this->createdAt,
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageRow(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'timezone' => $this->timezone,
            'location_label' => $this->locationLabel,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'timezone' => $this->timezone,
            'location_label' => $this->locationLabel,
            'status' => $this->status,
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
