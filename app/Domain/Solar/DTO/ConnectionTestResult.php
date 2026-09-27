<?php

declare(strict_types=1);

namespace App\Domain\Solar\DTO;

use DateTimeImmutable;

/**
 * SPMS O4 — Connection Test Result DTO (docs/10 D-059).
 *
 * Encapsulates the outcome of probing an external solar provider connection.
 */
final class ConnectionTestResult
{
    /**
     * @param list<array{station_id: string, station_name: string, capacity_kw: ?float}> $stations
     */
    public function __construct(
        private readonly bool $success,
        private readonly string $status,
        private readonly ?string $stationId = null,
        private readonly ?string $stationName = null,
        private readonly ?string $inverterModel = null,
        private readonly ?string $inverterSn = null,
        private readonly ?float $ratedPowerKw = null,
        private readonly array $stations = [],
        private readonly ?string $errorCode = null,
        private readonly ?string $errorMessage = null,
        private readonly ?DateTimeImmutable $testedAt = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function stationId(): ?string
    {
        return $this->stationId;
    }

    public function stationName(): ?string
    {
        return $this->stationName;
    }

    public function inverterModel(): ?string
    {
        return $this->inverterModel;
    }

    public function inverterSn(): ?string
    {
        return $this->inverterSn;
    }

    public function ratedPowerKw(): ?float
    {
        return $this->ratedPowerKw;
    }

    /**
     * @return list<array{station_id: string, station_name: string, capacity_kw: ?float}>
     */
    public function stations(): array
    {
        return $this->stations;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function testedAt(): DateTimeImmutable
    {
        return $this->testedAt ?? new DateTimeImmutable('now');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'station_id' => $this->stationId,
            'station_name' => $this->stationName,
            'inverter_model' => $this->inverterModel,
            'inverter_sn' => $this->inverterSn,
            'rated_power_kw' => $this->ratedPowerKw,
            'stations' => $this->stations,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'tested_at' => $this->testedAt()->format(DateTimeImmutable::ATOM),
        ];
    }
}
