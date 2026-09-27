<?php

declare(strict_types=1);

namespace App\Domain\Solar\DTO;

use InvalidArgumentException;

/**
 * SPMS O4 — Immutable Solar Credentials Value Object (docs/10 D-059).
 *
 * Holds external solar provider credentials before encryption or after decryption.
 * Never exposed verbatim in API responses or logs.
 */
final class SolarCredentials
{
    public function __construct(
        private readonly string $account,
        private readonly string $password,
        private readonly ?string $stationId = null,
        private readonly ?string $inverterSn = null
    ) {
        if (trim($this->account) === '') {
            throw new InvalidArgumentException('Solar account cannot be empty.');
        }
        if (trim($this->password) === '') {
            throw new InvalidArgumentException('Solar password cannot be empty.');
        }
    }

    public function account(): string
    {
        return $this->account;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function stationId(): ?string
    {
        return $this->stationId !== null && trim($this->stationId) !== '' ? trim($this->stationId) : null;
    }

    public function inverterSn(): ?string
    {
        return $this->inverterSn !== null && trim($this->inverterSn) !== '' ? trim($this->inverterSn) : null;
    }

    public function withStationId(string $stationId): self
    {
        if (trim($stationId) === '') {
            throw new InvalidArgumentException('Solar station ID cannot be empty.');
        }

        return new self(
            $this->account,
            $this->password,
            trim($stationId),
            $this->inverterSn
        );
    }

    public function withInverterSn(?string $inverterSn): self
    {
        return new self(
            $this->account,
            $this->password,
            $this->stationId,
            $inverterSn !== null && trim($inverterSn) !== '' ? trim($inverterSn) : null
        );
    }

    /**
     * Enforce strict persistence invariant.
     * Cannot persist or encrypt connection without a resolved, non-empty stationId.
     */
    public function assertPersistable(): void
    {
        if ($this->stationId() === null) {
            throw new InvalidArgumentException('Cannot persist solar connection without a resolved power station ID.');
        }
    }

    /**
     * Produce a safe, privacy-preserving masked identifier for UI display.
     * e.g. "john.doe@example.com" -> "j***e@example.com" or "user1234" -> "u***4"
     */
    public function maskedAccount(): string
    {
        $acc = trim($this->account);
        if (str_contains($acc, '@')) {
            [$user, $domain] = explode('@', $acc, 2);
            $len = strlen($user);
            if ($len <= 2) {
                $maskedUser = substr($user, 0, 1) . '***';
            } else {
                $maskedUser = substr($user, 0, 1) . '***' . substr($user, -1);
            }
            return $maskedUser . '@' . $domain;
        }

        $len = strlen($acc);
        if ($len <= 2) {
            return substr($acc, 0, 1) . '***';
        }
        return substr($acc, 0, 1) . '***' . substr($acc, -1);
    }

    /**
     * Serializes sensitive data for AEAD AES-256-GCM encryption.
     *
     * @return array{account: string, password: string, station_id: string, inverter_sn: ?string}
     */
    public function toEncryptedPayload(): array
    {
        $this->assertPersistable();

        return [
            'account' => $this->account,
            'password' => $this->password,
            'station_id' => (string) $this->stationId(),
            'inverter_sn' => $this->inverterSn(),
        ];
    }

    /**
     * Reconstruct from decrypted payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromEncryptedPayload(array $payload): self
    {
        return new self(
            (string) ($payload['account'] ?? ''),
            (string) ($payload['password'] ?? ''),
            (string) ($payload['station_id'] ?? ''),
            isset($payload['inverter_sn']) && (string) $payload['inverter_sn'] !== ''
                ? (string) $payload['inverter_sn']
                : null
        );
    }
}
