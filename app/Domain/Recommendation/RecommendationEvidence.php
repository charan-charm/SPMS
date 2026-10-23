<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

/**
 * SPMS F7 — Structured Machine-Readable Recommendation Evidence (docs/01 §15, docs/02 §2 F7).
 *
 * Captures the authoritative facts behind a recommendation:
 * - solar_forecast (predicted value, unit, period, confidence, source)
 * - consumption_forecast (predicted value, unit, period, confidence, source)
 * - energy_balance (balance_kwh, state, classification, period)
 * - cost (estimated_variable_cost_inr, cost_range, currency, source)
 * - insights (matching F6 insight facts, significance, confidence)
 * - limitations (data gaps, stale telemetry notes, etc.)
 * - provenance (source systems involved)
 */
final class RecommendationEvidence
{
    /**
     * @param array<string, mixed>|null $solarForecast
     * @param array<string, mixed>|null $consumptionForecast
     * @param array<string, mixed>|null $energyBalance
     * @param array<string, mixed>|null $cost
     * @param list<array<string, mixed>> $insights
     * @param list<string> $limitations
     * @param list<string> $provenance
     * @param array<string, mixed>|null $weather
     */
    public function __construct(
        public readonly ?array $solarForecast = null,
        public readonly ?array $consumptionForecast = null,
        public readonly ?array $energyBalance = null,
        public readonly ?array $cost = null,
        public readonly array $insights = [],
        public readonly array $limitations = [],
        public readonly array $provenance = [],
        public readonly ?string $quality = null,
        public readonly ?string $dataNature = null,
        public readonly ?array $weather = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'solar_forecast' => $this->solarForecast,
            'consumption_forecast' => $this->consumptionForecast,
            'energy_balance' => $this->energyBalance,
            'cost' => $this->cost,
            'weather' => $this->weather,
            'insights' => !empty($this->insights) ? $this->insights : null,
            'limitations' => !empty($this->limitations) ? $this->limitations : null,
            'provenance' => $this->provenance,
            'quality' => $this->quality,
            'data_nature' => $this->dataNature,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            solarForecast: isset($data['solar_forecast']) && is_array($data['solar_forecast']) ? $data['solar_forecast'] : null,
            consumptionForecast: isset($data['consumption_forecast']) && is_array($data['consumption_forecast']) ? $data['consumption_forecast'] : null,
            energyBalance: isset($data['energy_balance']) && is_array($data['energy_balance']) ? $data['energy_balance'] : null,
            cost: isset($data['cost']) && is_array($data['cost']) ? $data['cost'] : null,
            insights: isset($data['insights']) && is_array($data['insights']) ? $data['insights'] : [],
            limitations: isset($data['limitations']) && is_array($data['limitations']) ? $data['limitations'] : [],
            provenance: isset($data['provenance']) && is_array($data['provenance']) ? $data['provenance'] : [],
            quality: $data['quality'] ?? null,
            dataNature: $data['data_nature'] ?? null,
            weather: isset($data['weather']) && is_array($data['weather']) ? $data['weather'] : null
        );
    }
}
