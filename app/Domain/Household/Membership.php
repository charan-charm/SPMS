<?php

declare(strict_types=1);

namespace App\Domain\Household;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * W3 — Household membership (docs/10 D-050).
 *
 * The authorizing link between an account and a household. Only OWNER is
 * implemented — MEMBER/VIEWER are future roles and must not be
 * speculatively accepted. Membership never carries data semantics; it
 * answers exactly one question: may this account act within this
 * household.
 */
final class Membership
{
    public const ROLE_OWNER = 'OWNER';

    public const ROLES = [self::ROLE_OWNER];

    private function __construct(
        private readonly string $id,
        private readonly string $householdId,
        private readonly string $accountId,
        private readonly string $role,
        private readonly string $createdAt,
        private readonly string $updatedAt
    ) {
    }

    public static function create(string $householdId, string $accountId, string $role, DateTimeImmutable $now): self
    {
        if ($householdId === '' || mb_strlen($householdId) > 36) {
            throw new InvalidArgumentException('household id is invalid.');
        }

        if ($accountId === '' || mb_strlen($accountId) > 36) {
            throw new InvalidArgumentException('account id is invalid.');
        }

        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('unsupported membership role: ' . $role);
        }

        $stamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return new self(
            id: self::uuid(),
            householdId: $householdId,
            accountId: $accountId,
            role: $role,
            createdAt: $stamp,
            updatedAt: $stamp
        );
    }

    public static function fromStorageRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            householdId: (string) $row['household_id'],
            accountId: (string) $row['account_id'],
            role: (string) $row['role'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function householdId(): string
    {
        return $this->householdId;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function role(): string
    {
        return $this->role;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageRow(): array
    {
        return [
            'id' => $this->id,
            'household_id' => $this->householdId,
            'account_id' => $this->accountId,
            'role' => $this->role,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
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
