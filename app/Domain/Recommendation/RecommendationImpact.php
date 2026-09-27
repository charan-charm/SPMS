<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

/**
 * SPMS F7 — Recommendation Impact Model (docs/01 §15, docs/02 §2 F7).
 *
 * Impact must be evidence-based and use strictly careful language:
 * - "Potential", "Estimated", "Expected".
 * - Never "Guaranteed", "Zero grid usage guaranteed", etc.
 * - Monetary impact is included ONLY when F5 provides sufficient cost evidence.
 */
final class RecommendationImpact
{
    public const TYPE_SOLAR_UTILIZATION = 'SOLAR_UTILIZATION';
    public const TYPE_SOLAR_SELF_CONSUMPTION = 'SOLAR_SELF_CONSUMPTION';
    public const TYPE_AVOIDED_DEFICIT = 'AVOIDED_DEFICIT';
    public const TYPE_GRID_IMPORT_REDUCTION = 'GRID_IMPORT_REDUCTION';
    public const TYPE_LOAD_SHIFT = 'LOAD_SHIFT';
    public const TYPE_HIGH_DEMAND_CURTAILMENT = 'HIGH_DEMAND_CURTAILMENT';
    public const TYPE_COST_EXPOSURE_REDUCTION = 'COST_EXPOSURE_REDUCTION';
    public const TYPE_INFORMATIONAL = 'INFORMATIONAL';

    public readonly ?float $kwhValue;
    public readonly ?float $inrValue;

    /**
     * @param string $type
     * @param string $description User-facing description with careful wording
     * @param float|null $energyKwh Expected/potential energy quantity in kWh
     * @param float|null $costInr Estimated cost reduction in INR (null if unsupported)
     * @param array<string, mixed> $context Additional structured metrics
     * @param float|null $kwhValue Alias for energyKwh
     * @param float|null $inrValue Alias for costInr
     */
    public function __construct(
        public readonly string $type,
        public readonly string $description,
        public readonly ?float $energyKwh = null,
        public readonly ?float $costInr = null,
        public readonly array $context = [],
        ?float $kwhValue = null,
        ?float $inrValue = null
    ) {
        $this->kwhValue = $kwhValue ?? $energyKwh;
        $this->inrValue = $inrValue ?? $costInr;
    }

    /**
     * Factory for solar self-consumption opportunity.
     */
    public static function solarSelfConsumption(float $kwh, ?float $inr = null): self
    {
        $roundedKwh = round($kwh, 1);
        $roundedInr = $inr !== null ? round($inr, 2) : null;
        $desc = sprintf('Potential +%.1f kWh solar self-consumption opportunity', $roundedKwh);

        return new self(
            type: self::TYPE_SOLAR_SELF_CONSUMPTION,
            description: $desc,
            energyKwh: $roundedKwh,
            costInr: $roundedInr,
            context: ['potential_surplus_kwh' => $roundedKwh],
            kwhValue: $roundedKwh,
            inrValue: $roundedInr
        );
    }

    /**
     * Factory for grid import reduction.
     */
    public static function gridImportReduction(?float $kwh = null, ?float $inr = null): self
    {
        if ($kwh !== null && $kwh > 0.0) {
            $roundedKwh = round($kwh, 1);
            $roundedInr = $inr !== null ? round($inr, 2) : null;
            $desc = sprintf('Estimated avoidance of up to %.1f kWh grid reliance during deficit window', $roundedKwh);

            return new self(
                type: self::TYPE_GRID_IMPORT_REDUCTION,
                description: $desc,
                energyKwh: $roundedKwh,
                costInr: $roundedInr,
                context: ['potential_deficit_kwh' => $roundedKwh],
                kwhValue: $roundedKwh,
                inrValue: $roundedInr
            );
        }

        return new self(
            type: self::TYPE_GRID_IMPORT_REDUCTION,
            description: 'Moderate discretionary electricity use during evening deficit window',
            energyKwh: null,
            costInr: null,
            context: []
        );
    }

    /**
     * Factory for solar utilization opportunity.
     */
    public static function solarUtilization(float $surplusKwh, ?float $estimatedRateInr = null): self
    {
        $roundedKwh = round($surplusKwh, 1);
        $desc = sprintf('Potential solar-window opportunity of approximately %.1f kWh', $roundedKwh);
        $costInr = null;
        if ($estimatedRateInr !== null && $estimatedRateInr > 0) {
            $costInr = round($surplusKwh * $estimatedRateInr, 2);
        }

        return new self(
            type: self::TYPE_SOLAR_UTILIZATION,
            description: $desc,
            energyKwh: $roundedKwh,
            costInr: $costInr,
            context: ['potential_surplus_kwh' => $roundedKwh]
        );
    }

    /**
     * Factory for avoided deficit window.
     */
    public static function avoidedDeficit(float $deficitKwh, ?float $estimatedRateInr = null): self
    {
        $roundedKwh = round($deficitKwh, 1);
        $desc = sprintf('Potential avoidance of up to %.1f kWh grid reliance during deficit window', $roundedKwh);
        $costInr = null;
        if ($estimatedRateInr !== null && $estimatedRateInr > 0) {
            $costInr = round($deficitKwh * $estimatedRateInr, 2);
        }

        return new self(
            type: self::TYPE_AVOIDED_DEFICIT,
            description: $desc,
            energyKwh: $roundedKwh,
            costInr: $costInr,
            context: ['potential_deficit_kwh' => $roundedKwh]
        );
    }

    /**
     * Factory for load shift.
     */
    public static function loadShift(float $shiftKwh, string $targetWindow): self
    {
        $roundedKwh = round($shiftKwh, 1);
        $desc = sprintf('Potential load shift of ≈%.1f kWh into the %s window', $roundedKwh, $targetWindow);

        return new self(
            type: self::TYPE_LOAD_SHIFT,
            description: $desc,
            energyKwh: $roundedKwh,
            costInr: null,
            context: ['shift_target_window' => $targetWindow]
        );
    }

    /**
     * Factory for high demand reduction.
     */
    public static function highDemandReduction(?float $excessKwh = null, ?float $costInr = null): self
    {
        if ($excessKwh !== null && $excessKwh > 0) {
            $roundedKwh = round($excessKwh, 1);
            $desc = sprintf('Estimated reduction opportunity of up to %.1f kWh above typical baseline', $roundedKwh);

            return new self(
                type: self::TYPE_HIGH_DEMAND_CURTAILMENT,
                description: $desc,
                energyKwh: $roundedKwh,
                costInr: $costInr,
                context: ['excess_kwh' => $roundedKwh]
            );
        }

        return new self(
            type: self::TYPE_HIGH_DEMAND_CURTAILMENT,
            description: 'Review recurring household electricity consumption',
            energyKwh: null,
            costInr: $costInr,
            context: []
        );
    }

    /**
     * Factory for cost-aware shift.
     */
    public static function costAwareShift(float $shiftKwh, float $estimatedSavingsInr): self
    {
        $roundedKwh = round($shiftKwh, 1);
        $roundedInr = round($estimatedSavingsInr, 2);
        $desc = sprintf('Estimated variable-cost exposure reduction of up to ₹%.2f by shifting ≈%.1f kWh', $roundedInr, $roundedKwh);

        return new self(
            type: self::TYPE_COST_EXPOSURE_REDUCTION,
            description: $desc,
            energyKwh: $roundedKwh,
            costInr: $roundedInr,
            context: ['estimated_savings_inr' => $roundedInr, 'shift_kwh' => $roundedKwh]
        );
    }

    /**
     * Factory for informational notice.
     */
    public static function informational(string $description): self
    {
        return new self(
            type: self::TYPE_INFORMATIONAL,
            description: $description,
            energyKwh: null,
            costInr: null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'description' => $this->description,
            'energy_kwh' => $this->energyKwh,
            'cost_inr' => $this->costInr,
            'context' => $this->context,
        ];
    }
}
