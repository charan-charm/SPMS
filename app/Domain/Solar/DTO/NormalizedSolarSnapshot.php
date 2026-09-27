<?php

declare(strict_types=1);

namespace App\Domain\Solar\DTO;

use DateTimeImmutable;

/**
 * SPMS O4 — Normalized Solar Telemetry Snapshot (docs/10 D-059, D-060, D-061).
 *
 * Immutable, domain-canonical representation of a single solar telemetry interval.
 * Decoupled from provider-specific JSON schemas (e.g. SEMS+ dict structures).
 */
final class NormalizedSolarSnapshot
{
    public function __construct(
        private readonly string $stationId,
        private readonly ?string $inverterSn,
        private readonly DateTimeImmutable $measurementTime,
        private readonly DateTimeImmutable $receivedAt,
        private readonly float $powerW,
        private readonly float $dailyGenerationKwh,
        private readonly ?float $totalGenerationKwh = null,
        private readonly ?float $pvPowerW = null,
        private readonly ?float $acVoltageV = null,
        private readonly ?float $acCurrentA = null,
        private readonly ?float $acFrequencyHz = null,
        private readonly ?float $temperatureC = null,
        private readonly ?float $gridPowerW = null,
        private readonly ?float $consumptionPowerW = null,
        private readonly string $inverterStatus = 'UNKNOWN',
        private readonly string $dataNature = 'ACTUAL',
        private readonly string $dataStatus = 'FRESH',
        private readonly string $dataQuality = 'GOOD'
    ) {
    }

    public function stationId(): string
    {
        return $this->stationId;
    }

    public function inverterSn(): ?string
    {
        return $this->inverterSn;
    }

    public function measurementTime(): DateTimeImmutable
    {
        return $this->measurementTime;
    }

    public function receivedAt(): DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function powerW(): float
    {
        return $this->powerW;
    }

    public function dailyGenerationKwh(): float
    {
        return $this->dailyGenerationKwh;
    }

    public function totalGenerationKwh(): ?float
    {
        return $this->totalGenerationKwh;
    }

    public function pvPowerW(): ?float
    {
        return $this->pvPowerW;
    }

    public function acVoltageV(): ?float
    {
        return $this->acVoltageV;
    }

    public function acCurrentA(): ?float
    {
        return $this->acCurrentA;
    }

    public function acFrequencyHz(): ?float
    {
        return $this->acFrequencyHz;
    }

    public function temperatureC(): ?float
    {
        return $this->temperatureC;
    }

    public function gridPowerW(): ?float
    {
        return $this->gridPowerW;
    }

    public function consumptionPowerW(): ?float
    {
        return $this->consumptionPowerW;
    }

    public function inverterStatus(): string
    {
        return $this->inverterStatus;
    }

    public function dataNature(): string
    {
        return $this->dataNature;
    }

    public function dataStatus(): string
    {
        return $this->dataStatus;
    }

    public function dataQuality(): string
    {
        return $this->dataQuality;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'station_id' => $this->stationId,
            'inverter_sn' => $this->inverterSn,
            'measurement_time' => $this->measurementTime->format(DateTimeImmutable::ATOM),
            'received_at' => $this->receivedAt->format(DateTimeImmutable::ATOM),
            'power_w' => $this->powerW,
            'daily_generation_kwh' => $this->dailyGenerationKwh,
            'total_generation_kwh' => $this->totalGenerationKwh,
            'pv_power_w' => $this->pvPowerW,
            'ac_voltage_v' => $this->acVoltageV,
            'ac_current_a' => $this->acCurrentA,
            'ac_frequency_hz' => $this->acFrequencyHz,
            'temperature_c' => $this->temperatureC,
            'grid_power_w' => $this->gridPowerW,
            'consumption_power_w' => $this->consumptionPowerW,
            'inverter_status' => $this->inverterStatus,
            'data_nature' => $this->dataNature,
            'data_status' => $this->dataStatus,
            'data_quality' => $this->dataQuality,
        ];
    }
}
