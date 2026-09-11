<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * W1 — Canonical account identity (docs/10 D-049).
 *
 * role/status/mode are server-assigned only and are NEVER accepted from
 * client input; registration fixes USER + REAL + PENDING_VERIFICATION.
 * The public representation deliberately excludes password_hash — it can
 * never cross the API boundary.
 */
final class Account
{
    public const STATUS_PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DISABLED = 'DISABLED';
    public const STATUS_DELETION_PENDING = 'DELETION_PENDING';

    public const STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
        self::STATUS_DELETION_PENDING,
    ];

    public const ROLE_USER = 'USER';
    public const ROLE_ADMIN = 'ADMIN';

    public const ROLES = [self::ROLE_USER, self::ROLE_ADMIN];

    public const MODE_REAL = 'REAL';
    public const MODE_DEMO_TEST = 'DEMO_TEST';

    public const MODES = [self::MODE_REAL, self::MODE_DEMO_TEST];

    private function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $status,
        private readonly string $role,
        private readonly string $mode,
        private readonly ?string $emailVerifiedAt,
        private readonly string $createdAt,
        private readonly string $updatedAt
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown account status: ' . $status);
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Unknown account role: ' . $role);
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown account mode: ' . $mode);
        }
    }

    /** Registration default — the only client-independent creation path. */
    public static function register(string $email, string $passwordHash, \DateTimeImmutable $now): self
    {
        $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return new self(
            id: self::uuid(),
            email: self::normalizeEmail($email),
            passwordHash: $passwordHash,
            status: self::STATUS_PENDING_VERIFICATION,
            role: self::ROLE_USER,
            mode: self::MODE_REAL,
            emailVerifiedAt: null,
            createdAt: $timestamp,
            updatedAt: $timestamp
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromStorageRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            status: (string) $row['status'],
            role: (string) $row['role'],
            mode: (string) $row['mode'],
            emailVerifiedAt: $row['email_verified_at'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    /**
     * Storage row. password_hash is stored in the database but is part
     * of no API representation.
     *
     * @return array<string, mixed>
     */
    public function toStorageRow(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'password_hash' => $this->passwordHash,
            'status' => $this->status,
            'role' => $this->role,
            'mode' => $this->mode,
            'email_verified_at' => $this->emailVerifiedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Safe API representation — never contains password_hash, security
     * metadata, or session material.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->status,
            'role' => $this->role,
            'mode' => $this->mode,
            'email_verified' => $this->emailVerifiedAt !== null,
        ];
    }

    public function withStatus(string $status, ?string $emailVerifiedAt, \DateTimeImmutable $now): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown account status: ' . $status);
        }

        return new self(
            id: $this->id,
            email: $this->email,
            passwordHash: $this->passwordHash,
            status: $status,
            role: $this->role,
            mode: $this->mode,
            emailVerifiedAt: $emailVerifiedAt,
            createdAt: $this->createdAt,
            updatedAt: $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    public function withPasswordHash(string $passwordHash, \DateTimeImmutable $now): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            passwordHash: $passwordHash,
            status: $this->status,
            role: $this->role,
            mode: $this->mode,
            emailVerifiedAt: $this->emailVerifiedAt,
            createdAt: $this->createdAt,
            updatedAt: $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Only ACTIVE accounts may authenticate; DISABLED and
     * DELETION_PENDING are hard blocks, PENDING_VERIFICATION has not
     * completed activation (docs/08 §4 account-state validation).
     */
    public function canAuthenticate(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Lowercase, trimmed normalization — the only email form stored. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
