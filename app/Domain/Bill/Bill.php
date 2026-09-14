<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * B1 — The bill aggregate root (docs/10 D-067).
 *
 * Carries ownership (household_id, never reassignable), the lifecycle
 * status, the current normalized values, and the verification stamp.
 * Immutability-by-copy like every other SPMS entity: transitions and
 * value changes return NEW objects; the aggregate never self-mutates.
 *
 * Invariants enforced here (not just "documented"):
 * - status changes only through BillLifecycle (transitionTo/verify);
 * - VERIFIED only through verify(), which also stamps verified_at —
 *   extraction, corrections and every other path cannot produce it (§3,
 *   §17: AI completion and human acceptance are different facts);
 * - field values only through the BillField whitelist (§2: PII-shaped
 *   keys are rejected structurally, not filtered);
 * - period chronology re-checked on EVERY value change (§13: persistence
 *   rejects reversed dates instead of normalizing them);
 * - household_id is fixed at construction — there is no method, anywhere
 *   on this object, that moves a bill to another household (§4).
 */
final class Bill
{
    /** @var array<string, string|null> field => canonical value or null */
    private readonly array $values;

    private function __construct(
        private readonly string $id,
        private readonly string $householdId,
        private readonly string $status,
        array $values,
        private readonly ?string $verifiedAt,
        private readonly string $createdAt,
        private readonly string $updatedAt
    ) {
        $normalized = [];
        foreach (BillField::ALL as $field) {
            $normalized[$field] = BillField::validate($field, $values[$field] ?? null);
        }
        BillField::assertChronology(
            $normalized[BillField::BILLING_PERIOD_START],
            $normalized[BillField::BILLING_PERIOD_END]
        );

        $this->values = $normalized;
    }

    /**
     * A fresh bill enters at UPLOADED with zero knowledge: every value
     * column NULL (§15 — nothing is defaulted to 0 or ""), because at
     * this point the system has not even read a document.
     */
    public static function create(
        string $householdId,
        ?string $billingPeriodStart,
        ?string $billingPeriodEnd,
        DateTimeImmutable $now
    ): self {
        if ($householdId === '' || mb_strlen($householdId) > 36) {
            throw new InvalidArgumentException('household id is invalid.');
        }

        $stamp = self::stamp($now);

        return new self(
            id: self::uuid(),
            householdId: $householdId,
            status: BillStatus::UPLOADED,
            values: [
                BillField::BILLING_PERIOD_START => $billingPeriodStart,
                BillField::BILLING_PERIOD_END => $billingPeriodEnd,
            ],
            verifiedAt: null,
            createdAt: $stamp,
            updatedAt: $stamp
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
            householdId: (string) $row['household_id'],
            status: (string) $row['status'],
            values: $values,
            verifiedAt: $row['verified_at'] !== null ? (string) $row['verified_at'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    /**
     * Lifecycle move. VERIFIED is rejected here on purpose: becoming
     * authoritative is not a plain transition, it is the verify()
     * operation (which stamps verified_at as one indivisible act).
     */
    public function transitionTo(string $newStatus, DateTimeImmutable $now): self
    {
        if ($newStatus === BillStatus::VERIFIED) {
            throw new InvalidArgumentException('use verify() to make a bill authoritative.');
        }

        BillLifecycle::assert($this->status, $newStatus);

        return new self(
            $this->id,
            $this->householdId,
            $newStatus,
            $this->values,
            $this->verifiedAt,
            $this->createdAt,
            self::stamp($now)
        );
    }

    /**
     * The single path to authoritative data (§17): explicit, auditable
     * (verified_at), lifecycle-guarded, and draft/provenance-preserving
     * (this object cannot touch either — they are separate records).
     */
    public function verify(DateTimeImmutable $now): self
    {
        BillLifecycle::assert($this->status, BillStatus::VERIFIED);

        return new self(
            $this->id,
            $this->householdId,
            BillStatus::VERIFIED,
            $this->values,
            self::stamp($now),
            $this->createdAt,
            self::stamp($now)
        );
    }

    /**
     * Replace selected current values (used for applying a draft and for
     * user corrections). Unknown keys throw — the whitelist is the only
     * door (§2). Provided keys are written verbatim (including explicit
     * nulls, which honestly clear a value back to "unknown"); absent keys
     * keep their current value.
     *
     * @param array<string, string|null> $patch
     */
    public function withValues(array $patch, DateTimeImmutable $now): self
    {
        $merged = $this->values;

        foreach ($patch as $field => $value) {
            if (!is_string($field)) {
                throw new InvalidArgumentException('bill value keys must be field names.');
            }
            $merged[$field] = BillField::validate($field, $value);
        }

        return new self(
            $this->id,
            $this->householdId,
            $this->status,
            $merged,
            $this->verifiedAt,
            $this->createdAt,
            self::stamp($now)
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

    public function status(): string
    {
        return $this->status;
    }

    public function verifiedAt(): ?string
    {
        return $this->verifiedAt;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    public function value(string $field): ?string
    {
        if (!BillField::isPersistable($field)) {
            throw new InvalidArgumentException('bill field is not persistable: ' . $field);
        }

        return $this->values[$field];
    }

    /** @return array<string, string|null> */
    public function values(): array
    {
        return $this->values;
    }

    public function isAuthoritative(): bool
    {
        return BillStatus::isAuthoritative($this->status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageRow(): array
    {
        return array_merge($this->values, [
            'id' => $this->id,
            'household_id' => $this->householdId,
            'status' => $this->status,
            'verified_at' => $this->verifiedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ]);
    }

    private static function stamp(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
