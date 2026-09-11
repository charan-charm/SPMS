<?php

declare(strict_types=1);

namespace App\Domain\Energy;

use App\Support\AppException;

/**
 * F5 — Energy Cost Analysis (docs/02 §2, docs/01 §19, docs/05 §12,
 * docs/10 D-024/D-042).
 *
 * Backend-authoritative ESTIMATE of future variable energy cost from
 * validated predicted/estimated energy inputs and an explicitly declared
 * pricing basis. Historical verified cost is a separate capability and
 * stays honestly UNAVAILABLE until verified bill persistence exists.
 *
 * Hard rules (owner-locked F5.1–F5.9):
 * - Pipeline-first: estimates are computed from supplied inputs; nothing
 *   is persisted, nothing is invented.
 * - Currency is explicit data (INR only in v1), never presentation.
 * - Pricing evidence ladder: verified tariff evidence → verified
 *   historical bill relationship → user-provided rate → insufficient
 *   evidence. Tariff information is never invented.
 * - A numeric range is mandatory whenever fallback pricing evidence is
 *   used, the worst input confidence is LOW/VERY_LOW, or prediction
 *   uncertainty (non-ACTUAL input nature) materially affects the result.
 *   An uncertain future cost is never presented as an exact bill.
 * - Fixed charges stay separate: never multiplied by kWh, never folded
 *   into an inferred unit rate, never silently added into the variable
 *   estimate.
 * - Other charges (FPPCA, duty, taxes, …) are never predicted and never
 *   silently included.
 * - The cost result is an ESTIMATE (measurement context ESTIMATED); the
 *   echoed energy position stays POTENTIAL and is never reinterpreted as
 *   measured grid import/export.
 * - BALANCED within the disclosed F4 tolerance (docs/10 D-040) means NO
 *   material potential deficit: the cost basis grid_dependent_kwh is 0
 *   and the estimated variable cost is 0.00 (owner-ratified, docs/10
 *   D-042). The raw difference remains preserved in balance_kwh.
 * - Insufficient evidence degrades honestly instead of fabricating zero.
 *
 * No AI/Bedrock calls: this calculation is deterministic backend logic.
 */
final class EnergyCostService
{
    /** Pricing evidence ladder (F5.3) — explicit, whitelisted, declared. */
    public const BASIS_TARIFF_EVIDENCE = 'TARIFF_EVIDENCE';
    public const BASIS_HISTORICAL_BILL_RELATIONSHIP = 'HISTORICAL_BILL_RELATIONSHIP';
    public const BASIS_USER_PROVIDED_RATE = 'USER_PROVIDED_RATE';
    public const BASIS_INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';

    public const CURRENCY_INR = 'INR';

    public const RESULT_STATE_ESTIMATED = 'ESTIMATED';
    public const RESULT_STATE_INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';
    public const RESULT_STATE_UNAVAILABLE = 'UNAVAILABLE';

    public const COST_TYPE_FUTURE_ESTIMATE = 'FUTURE_ESTIMATE';
    public const COST_TYPE_HISTORICAL_VERIFIED = 'HISTORICAL_VERIFIED';

    public const MEASUREMENT_CONTEXT_ESTIMATED = 'ESTIMATED';
    public const MEASUREMENT_CONTEXT_POTENTIAL = 'POTENTIAL';

    /**
     * Disclosed default range half-width factors (percent of the estimate).
     * The WIDEST applicable factor wins. These are v1 implementation
     * defaults only — not scientifically calibrated uncertainty, not
     * statistically validated prediction intervals, and not permanent
     * domain truth (owner-ratified, docs/10 D-042). Constants are fixed in
     * code and deliberately not environment-configurable; the applied
     * factor and its triggers are disclosed in every ranged response.
     */
    private const RANGE_FACTOR_BASIS = [
        self::BASIS_HISTORICAL_BILL_RELATIONSHIP => 15.0,
        self::BASIS_USER_PROVIDED_RATE => 25.0,
    ];

    /** Prediction-uncertainty factors by worst INPUT nature (ACTUAL → none). */
    private const RANGE_FACTOR_NATURE = [
        'ESTIMATED' => 10.0,
        'PREDICTED' => 15.0,
        'SIMULATED' => 20.0,
    ];

    private const RANGE_FACTOR_CONFIDENCE = [
        'LOW' => 25.0,
        'VERY_LOW' => 40.0,
    ];

    /** Data nature severity — worst wins (SIMULATED is least trustworthy). */
    private const NATURE_SEVERITY = [
        'ACTUAL' => 0,
        'ESTIMATED' => 1,
        'PREDICTED' => 2,
        'SIMULATED' => 3,
    ];

    /** Data status severity — worst wins. */
    private const STATUS_SEVERITY = [
        'FRESH' => 0,
        'STALE' => 1,
        'DEGRADED' => 2,
    ];

    /** Quality severity — worst wins. */
    private const QUALITY_SEVERITY = [
        'VERIFIED' => 0,
        'GOOD' => 1,
        'PARTIAL' => 2,
        'UNVERIFIED' => 3,
        'POOR' => 4,
    ];

    /** Confidence severity — worst wins. */
    private const CONFIDENCE_SEVERITY = [
        'HIGH' => 0,
        'MODERATE' => 1,
        'LOW' => 2,
        'VERY_LOW' => 3,
    ];

    private const EVIDENCE_LEVELS = [
        self::BASIS_TARIFF_EVIDENCE => 1,
        self::BASIS_HISTORICAL_BILL_RELATIONSHIP => 2,
        self::BASIS_USER_PROVIDED_RATE => 3,
    ];

    /** F4's ratified balanced tolerance (docs/10 D-040) reused for the echoed potential position. */
    private const BALANCED_ABSOLUTE_TOLERANCE_KWH = 0.1;
    private const BALANCED_RELATIVE_TOLERANCE = 0.05;

    /**
     * Estimate the future variable cost of the potential grid-dependent
     * energy from validated inputs and a declared pricing basis.
     *
     * @param array{solar: array, consumption: array, pricing: array, fixed_charge?: array|null} $inputs
     *        validated inputs (value_kwh, nature, status, optional
     *        quality/confidence_level; pricing: basis, unit_rate, currency,
     *        optional source; fixed_charge: amount, currency, optional label)
     * @param array{start?:string,end?:string,resolution?:string}|null $period
     * @param string|null $householdId optional scope identifier (echoed, not resolved — no auth yet)
     */
    public function estimate(array $inputs, ?array $period = null, ?string $householdId = null): array
    {
        $solar = $inputs['solar'];
        $consumption = $inputs['consumption'];
        $pricing = $inputs['pricing'];

        $solarValue = (float) $solar['value_kwh'];
        $consumptionValue = (float) $consumption['value_kwh'];
        $balance = round($solarValue - $consumptionValue, 4);

        $position = $this->buildPosition($balance, $solarValue, $consumptionValue);
        $materialDeficitKwh = $position['grid_dependent_kwh'];

        $resultNature = $this->floorToEstimated(
            $this->worstOf([$solar['nature'], $consumption['nature']], self::NATURE_SEVERITY)
        );
        $resultStatus = $this->worstOf([$solar['status'], $consumption['status']], self::STATUS_SEVERITY);
        $confidence = $this->worstOfOptional(
            [$solar['confidence_level'] ?? null, $consumption['confidence_level'] ?? null],
            self::CONFIDENCE_SEVERITY
        );
        $quality = $this->worstOfOptional(
            [$solar['quality'] ?? null, $consumption['quality'] ?? null],
            self::QUALITY_SEVERITY
        );

        if ($pricing['basis'] === self::BASIS_INSUFFICIENT_EVIDENCE) {
            return $this->insufficientEvidenceResult(
                $inputs,
                $period,
                $householdId,
                $position,
                $resultNature,
                $resultStatus,
                $confidence,
                $quality
            );
        }

        $unitRate = (float) $pricing['unit_rate'];
        $currency = $pricing['currency'];
        $estimatedCost = round($materialDeficitKwh * $unitRate, 2);

        $range = $this->buildRange($estimatedCost, $pricing['basis'], $solar, $consumption, $confidence);

        $explanation = $position['classification'] === 'BALANCED'
            ? sprintf(
                'Energy position is BALANCED within the disclosed tolerance (%.4f kWh): no material potential deficit is identified, so the estimated variable cost is %.2f %s.',
                $position['tolerance']['applied_kwh'],
                $estimatedCost,
                $currency
            )
            : sprintf(
                'Estimated variable cost of the potential grid-dependent energy (%.4f kWh × %.4f %s/kWh, basis %s) is %.2f %s.',
                $materialDeficitKwh,
                $unitRate,
                $currency,
                $pricing['basis'],
                $estimatedCost,
                $currency
            );

        $data = [
            'analysis' => [
                'type' => 'COST_ESTIMATE',
                'formula' => 'max(0, household_consumption_kwh - solar_generation_kwh) * unit_rate',
                'period' => $period,
            ],
            'scope' => [
                'household_id' => $householdId,
            ],
            'inputs' => $this->echoInputs($solar, $consumption, $pricing, $inputs['fixed_charge'] ?? null),
            'energy_position' => $position,
            'result' => [
                'state' => self::RESULT_STATE_ESTIMATED,
                'cost_type' => self::COST_TYPE_FUTURE_ESTIMATE,
                'estimated_variable_cost' => $estimatedCost,
                'currency' => $currency,
                'range' => $range,
                'pricing_basis' => $pricing['basis'],
                'evidence_level' => self::EVIDENCE_LEVELS[$pricing['basis']],
                'pricing_source' => $pricing['source'] ?? null,
                'fixed_charge' => $this->echoFixedCharge($inputs['fixed_charge'] ?? null),
                'data_nature' => $resultNature,
                'data_status' => $resultStatus,
                'measurement_context' => self::MEASUREMENT_CONTEXT_ESTIMATED,
                'confidence_level' => $confidence,
                'quality' => $quality,
                'explanation' => $explanation,
            ],
            'notes' => $this->buildNotes($inputs, $range, $resultNature, $resultStatus),
        ];

        return $data;
    }

    /**
     * GET behavior for historical verified cost — no verified bill
     * persistence exists yet (F13/F2 bill storage is not implemented), so
     * the honest answer is UNAVAILABLE, never a fabricated amount.
     */
    public function historicalResult(?string $householdId = null): array
    {
        return [
            'analysis' => [
                'type' => 'HISTORICAL_COST',
                'formula' => null,
                'period' => null,
            ],
            'scope' => [
                'household_id' => $householdId,
            ],
            'inputs' => null,
            'result' => [
                'state' => self::RESULT_STATE_UNAVAILABLE,
                'cost_type' => self::COST_TYPE_HISTORICAL_VERIFIED,
                'amount' => null,
                'currency' => null,
                'data_nature' => null,
                'data_status' => 'UNAVAILABLE',
                'measurement_context' => null,
                'confidence_level' => null,
                'quality' => null,
                'explanation' => 'Verified historical cost requires verified electricity bill records. Bill persistence and verification are not implemented yet, so no historical cost can be shown.',
            ],
            'notes' => [
                'Historical verified cost and future estimated cost are kept strictly separate; this endpoint will only ever return amounts backed by verified bill records.',
                'Once verified bill data exists, historical cost analysis will report the amounts with their provenance, quality, and verification status.',
            ],
        ];
    }

    private function insufficientEvidenceResult(
        array $inputs,
        ?array $period,
        ?string $householdId,
        array $position,
        string $resultNature,
        string $resultStatus,
        ?string $confidence,
        ?string $quality
    ): array {
        return [
            'analysis' => [
                'type' => 'COST_ESTIMATE',
                'formula' => 'max(0, household_consumption_kwh - solar_generation_kwh) * unit_rate',
                'period' => $period,
            ],
            'scope' => [
                'household_id' => $householdId,
            ],
            'inputs' => $this->echoInputs(
                $inputs['solar'],
                $inputs['consumption'],
                $inputs['pricing'],
                $inputs['fixed_charge'] ?? null
            ),
            'energy_position' => $position,
            'result' => [
                'state' => self::RESULT_STATE_INSUFFICIENT_EVIDENCE,
                'cost_type' => self::COST_TYPE_FUTURE_ESTIMATE,
                'estimated_variable_cost' => null,
                'currency' => null,
                'range' => null,
                'pricing_basis' => self::BASIS_INSUFFICIENT_EVIDENCE,
                'evidence_level' => null,
                'pricing_source' => null,
                'fixed_charge' => $this->echoFixedCharge($inputs['fixed_charge'] ?? null),
                'data_nature' => $resultNature,
                'data_status' => $resultStatus,
                'measurement_context' => self::MEASUREMENT_CONTEXT_ESTIMATED,
                'confidence_level' => $confidence,
                'quality' => $quality,
                'explanation' => 'No reliable pricing evidence was declared, so no cost estimate can be calculated. SPMS does not invent tariff information.',
            ],
            'notes' => [
                'A cost estimate requires one of: verified tariff/rate evidence, a reliable verified historical bill relationship, or an explicitly provided rate.',
                'This is a future estimated cost capability — it never fabricates a zero or an exact bill when pricing evidence is insufficient.',
                'Fixed charges, taxes and other adjustments are not predicted in this version and are not silently included.',
            ],
        ];
    }

    /**
     * Potential energy position context, echoed honestly. The classification
     * reuses the owner-ratified F4 tolerance (docs/10 D-040) on F5's own
     * inputs; it remains POTENTIAL and is never a measured grid flow.
     *
     * Owner-ratified F5 balanced semantics (docs/10 D-042): when the
     * position is BALANCED within the disclosed tolerance, the
     * sub-tolerance residual is treated as NO material potential deficit —
     * grid_dependent_kwh (the cost basis) is 0 and the estimated variable
     * cost is 0.00. The raw difference remains preserved in balance_kwh.
     */
    private function buildPosition(float $balance, float $solarValue, float $consumptionValue): array
    {
        $tolerance = max(
            self::BALANCED_ABSOLUTE_TOLERANCE_KWH,
            self::BALANCED_RELATIVE_TOLERANCE * max(abs($solarValue), abs($consumptionValue))
        );

        if (abs($balance) <= $tolerance) {
            $classification = 'BALANCED';
        } elseif ($balance > 0) {
            $classification = 'POTENTIAL_SURPLUS';
        } else {
            $classification = 'POTENTIAL_DEFICIT';
        }

        $rawGridDependentKwh = round(max(0.0, $consumptionValue - $solarValue), 4);
        $materialGridDependentKwh = $classification === 'BALANCED' ? 0.0 : $rawGridDependentKwh;

        $position = [
            'balance_kwh' => $balance,
            'grid_dependent_kwh' => $materialGridDependentKwh,
            'classification' => $classification,
            'measurement_context' => self::MEASUREMENT_CONTEXT_POTENTIAL,
            'tolerance' => [
                'absolute_kwh' => self::BALANCED_ABSOLUTE_TOLERANCE_KWH,
                'relative' => self::BALANCED_RELATIVE_TOLERANCE,
                'applied_kwh' => round($tolerance, 4),
            ],
            'note' => 'Potential energy position derived from the provided inputs — never a measured grid import or export.',
        ];

        if ($classification === 'BALANCED' && $rawGridDependentKwh > 0.0) {
            $position['balanced_note'] = sprintf(
                'The raw difference (%.4f kWh) is within the disclosed tolerance (%.4f kWh), so it is treated as no material potential deficit for cost analysis.',
                $rawGridDependentKwh,
                round($tolerance, 4)
            );
        }

        return $position;
    }

    /**
     * Build the mandatory-when-triggered cost range (F5.4). The widest
     * applicable disclosed factor wins; the applied factor and its triggers
     * are returned so the frontend can present them verbatim.
     */
    private function buildRange(
        float $estimatedCost,
        string $basis,
        array $solar,
        array $consumption,
        ?string $confidence
    ): ?array {
        $factors = [];
        $triggers = [];

        if (isset(self::RANGE_FACTOR_BASIS[$basis])) {
            $factors[] = self::RANGE_FACTOR_BASIS[$basis];
            $triggers[] = sprintf(
                'Fallback pricing evidence (%s, evidence level %d of 3)',
                $basis,
                self::EVIDENCE_LEVELS[$basis]
            );
        }

        $worstNature = $this->worstOf([$solar['nature'], $consumption['nature']], self::NATURE_SEVERITY);
        if (isset(self::RANGE_FACTOR_NATURE[$worstNature])) {
            $factors[] = self::RANGE_FACTOR_NATURE[$worstNature];
            $triggers[] = sprintf('Prediction uncertainty materially affects the result (non-ACTUAL input nature: %s)', $worstNature);
        }

        if ($confidence !== null && isset(self::RANGE_FACTOR_CONFIDENCE[$confidence])) {
            $factors[] = self::RANGE_FACTOR_CONFIDENCE[$confidence];
            $triggers[] = sprintf('Worst input confidence is %s', $confidence);
        }

        if ($factors === []) {
            return null;
        }

        $halfWidthPercent = max($factors);
        $low = round($estimatedCost * (1 - $halfWidthPercent / 100), 2);
        $high = round($estimatedCost * (1 + $halfWidthPercent / 100), 2);

        return [
            'low' => $low,
            'high' => $high,
            'half_width_percent' => $halfWidthPercent,
            'triggers' => $triggers,
            'basis' => 'DISCLOSED_DEFAULT_FACTORS',
        ];
    }

    private function echoInputs(array $solar, array $consumption, array $pricing, ?array $fixedCharge): array
    {
        $echo = [
            'solar_generation' => $this->echoInput($solar, 'kWh of solar generation'),
            'household_consumption' => $this->echoInput($consumption, 'kWh of household consumption'),
            'pricing' => [
                'basis' => $pricing['basis'],
                'currency' => $pricing['currency'],
            ],
        ];

        if (isset($pricing['unit_rate'])) {
            $echo['pricing']['unit_rate'] = (float) $pricing['unit_rate'];
        }

        if (isset($pricing['source'])) {
            $echo['pricing']['source'] = $pricing['source'];
        }

        if ($fixedCharge !== null) {
            $echo['fixed_charge'] = $this->echoFixedCharge($fixedCharge);
        }

        return $echo;
    }

    private function echoInput(array $input, string $role): array
    {
        $echo = [
            'value_kwh' => (float) $input['value_kwh'],
            'data_nature' => $input['nature'],
            'data_status' => $input['status'],
        ];

        if (isset($input['quality'])) {
            $echo['quality'] = $input['quality'];
        }

        if (isset($input['confidence_level'])) {
            $echo['confidence_level'] = $input['confidence_level'];
        }

        return $echo;
    }

    private function echoFixedCharge(?array $fixedCharge): ?array
    {
        if ($fixedCharge === null) {
            return null;
        }

        $echo = [
            'amount' => (float) $fixedCharge['amount'],
            'currency' => $fixedCharge['currency'],
            'treatment' => 'SEPARATE_NOT_INCLUDED',
        ];

        if (isset($fixedCharge['label'])) {
            $echo['label'] = $fixedCharge['label'];
        }

        return $echo;
    }

    private function buildNotes(array $inputs, ?array $range, string $resultNature, string $resultStatus): array
    {
        $pricing = $inputs['pricing'];
        $fixedCharge = $inputs['fixed_charge'] ?? null;

        $notes = [
            'This is a future ESTIMATED cost based on the declared pricing basis. It is not a confirmed future electricity bill, not a verified historical amount, and not a guaranteed saving.',
            'The underlying energy position is a potential surplus/deficit (measurement context POTENTIAL) — a potential deficit is never a measured grid import.',
            sprintf(
                'Pricing basis %s was declared for this estimate (evidence level %d of 3); SPMS has not yet verified it against persisted tariff or bill records.',
                $pricing['basis'],
                self::EVIDENCE_LEVELS[$pricing['basis']]
            ),
            'Fixed charges, FPPCA, electricity duty, taxes and similar adjustments are not predicted in this version and are not silently included in the variable estimate.',
        ];

        if ($fixedCharge !== null) {
            $notes[] = sprintf(
                'A fixed charge of %.2f %s was supplied and is kept strictly separate: it is not multiplied by kWh and is not included in the estimated variable cost.',
                (float) $fixedCharge['amount'],
                $fixedCharge['currency']
            );
        }

        if ($range !== null) {
            $notes[] = sprintf(
                'A cost range is shown because: %s. The range is an uncertainty band, not a guarantee.',
                implode('; ', $range['triggers'])
            );
        } else {
            $notes[] = 'No range triggers applied, so a single point estimate is shown. It remains an estimate.';
        }

        if ($resultNature === 'SIMULATED') {
            $notes[] = 'Inputs are simulated (demo pipeline) — the estimate demonstrates the real calculation pipeline and does not describe real household energy.';
        }

        if ($resultStatus === 'STALE') {
            $notes[] = 'At least one input is stale — the estimate reflects outdated information.';
        }

        if ($resultStatus === 'DEGRADED') {
            $notes[] = 'At least one input is degraded — the estimate should be treated with additional caution.';
        }

        return $notes;
    }

    /**
     * Result nature floor: a future cost estimate is never labeled ACTUAL,
     * even from actual inputs. Predicted inputs stay PREDICTED, estimated
     * stay ESTIMATED, simulated stay SIMULATED.
     */
    private function floorToEstimated(string $nature): string
    {
        return self::NATURE_SEVERITY[$nature] < self::NATURE_SEVERITY['ESTIMATED']
            ? 'ESTIMATED'
            : $nature;
    }

    /**
     * Worst-of over required enum values (all must be present and known).
     *
     * @param string[] $values
     * @param array<string,int> $severity
     */
    private function worstOf(array $values, array $severity): string
    {
        $worst = null;
        $worstSeverity = -1;

        foreach ($values as $value) {
            if (!isset($severity[$value])) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    'Unknown data nature/status value: ' . (string) $value,
                    422
                );
            }

            if ($severity[$value] > $worstSeverity) {
                $worstSeverity = $severity[$value];
                $worst = $value;
            }
        }

        if ($worst === null) {
            throw new AppException(
                'VALIDATION_ERROR',
                'At least one input value is required to derive result semantics.',
                422
            );
        }

        return $worst;
    }

    /**
     * Worst-of over optional enum values (nulls ignored; all-null → null).
     *
     * @param array<string|null> $values
     * @param array<string,int> $severity
     */
    private function worstOfOptional(array $values, array $severity): ?string
    {
        $present = array_values(array_filter(
            $values,
            static fn ($value): bool => $value !== null
        ));

        if ($present === []) {
            return null;
        }

        return $this->worstOf($present, $severity);
    }
}
