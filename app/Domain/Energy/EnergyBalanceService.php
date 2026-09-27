<?php

declare(strict_types=1);

namespace App\Domain\Energy;

use App\Support\AppException;

/**
 * F4 — Energy Balance & Situation Analysis (docs/02 §2, docs/04 §60).
 *
 * Backend-authoritative classification of Potential Surplus / Balanced /
 * Potential Deficit from solar generation vs household consumption.
 *
 * Hard rules (owner-locked):
 * - Formula: Solar Generation − Household Consumption (docs/04 §60).
 * - Positive = Potential Surplus, negative = Potential Deficit — never
 *   presented as confirmed grid export/import (docs/06 §13, measurement
 *   context POTENTIAL).
 * - Input data nature/status/quality/confidence is preserved, never
 *   upgraded (a predicted input never becomes an actual result).
 * - No AI explanations (F4 scope), no invented confidence, no fabricated
 *   values when inputs are absent.
 */
final class EnergyBalanceService
{
    public const ANALYSIS_TIME_PERIOD = 'TIME_PERIOD';
    public const ANALYSIS_AGGREGATE = 'AGGREGATE';

    public const CLASSIFICATION_SURPLUS = 'POTENTIAL_SURPLUS';
    public const CLASSIFICATION_BALANCED = 'BALANCED';
    public const CLASSIFICATION_DEFICIT = 'POTENTIAL_DEFICIT';

    public const MEASUREMENT_CONTEXT_POTENTIAL = 'POTENTIAL';

    /** Balance tolerance: "balanced" when |balance| <= max(absolute, relative × max input). Owner-flagged default. */
    private const BALANCED_ABSOLUTE_TOLERANCE_KWH = 0.1;
    private const BALANCED_RELATIVE_TOLERANCE = 0.05;

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

    /** Confidence severity — worst wins (lower score = more confident). */
    private const CONFIDENCE_SEVERITY = [
        'HIGH' => 0,
        'MODERATE' => 1,
        'LOW' => 2,
        'VERY_LOW' => 3,
    ];

    /**
     * Classify the energy balance from two validated inputs.
     *
     * @param array{solar: array, consumption: array} $inputs validated inputs
     *        (value_kwh, nature, status, optional quality/confidence_level)
     * @param string $analysisType TIME_PERIOD | AGGREGATE
     * @param array{start?:string,end?:string,resolution?:string}|null $period
     * @param string|null $householdId optional scope identifier (echoed, not resolved — no auth yet)
     */
    public function classify(
        array $inputs,
        string $analysisType = self::ANALYSIS_AGGREGATE,
        ?array $period = null,
        ?string $householdId = null
    ): array {
        $solar = $inputs['solar'];
        $consumption = $inputs['consumption'];

        $solarValue = (float) $solar['value_kwh'];
        $consumptionValue = (float) $consumption['value_kwh'];
        $balance = round($solarValue - $consumptionValue, 4);

        [$classification, $explanation] = $this->classifyValue(
            $balance,
            $solarValue,
            $consumptionValue
        );

        $resultNature = $this->worstOf(
            [$solar['nature'], $consumption['nature']],
            self::NATURE_SEVERITY
        );
        $resultStatus = $this->worstOf(
            [$solar['status'], $consumption['status']],
            self::STATUS_SEVERITY
        );

        $confidence = $this->worstOfOptional(
            [$solar['confidence_level'] ?? null, $consumption['confidence_level'] ?? null],
            self::CONFIDENCE_SEVERITY
        );
        $quality = $this->worstOfOptional(
            [$solar['quality'] ?? null, $consumption['quality'] ?? null],
            self::QUALITY_SEVERITY
        );

        $data = [
            'analysis' => [
                'type' => $analysisType,
                'formula' => 'solar_generation_kwh - household_consumption_kwh',
                'period' => $period,
            ],
            'scope' => [
                'household_id' => $householdId,
            ],
            'inputs' => $this->echoInputs($solar, $consumption),
            'result' => [
                'state' => 'CLASSIFIED',
                'classification' => $classification,
                'balance_kwh' => $balance,
                'explanation' => $explanation,
                'data_nature' => $resultNature,
                'data_status' => $resultStatus,
                'measurement_context' => self::MEASUREMENT_CONTEXT_POTENTIAL,
                'confidence_level' => $confidence,
                'quality' => $quality,
                'tolerance' => [
                    'absolute_kwh' => self::BALANCED_ABSOLUTE_TOLERANCE_KWH,
                    'relative' => self::BALANCED_RELATIVE_TOLERANCE,
                ],
            ],
            'notes' => $this->buildNotes($resultNature, $solar, $consumption),
        ];

        return $data;
    }

    /**
     * GET behavior — no persisted data sources exist yet (no solar or
     * consumption tables). The honest answer is UNAVAILABLE, never a
     * fabricated zero or a classification from nothing.
     */
    public function unavailableResult(
        ?string $householdId = null,
        ?string $explanation = null,
        ?array $notes = null,
        ?string $limitation = null
    ): array {
        return [
            'analysis' => [
                'type' => self::ANALYSIS_AGGREGATE,
                'formula' => 'solar_generation_kwh - household_consumption_kwh',
                'period' => null,
            ],
            'scope' => [
                'household_id' => $householdId,
            ],
            'inputs' => null,
            'result' => [
                'state' => 'UNAVAILABLE',
                'classification' => null,
                'balance_kwh' => null,
                'explanation' => $explanation ?? 'No solar generation or household consumption data sources are connected yet, so no energy balance can be calculated.',
                'limitation' => $limitation ?? 'A balance can be calculated here once solar generation and household consumption data sources are connected.',
                'data_nature' => null,
                'data_status' => 'UNAVAILABLE',
                'measurement_context' => self::MEASUREMENT_CONTEXT_POTENTIAL,
                'confidence_level' => null,
                'quality' => null,
                'tolerance' => null,
            ],
            'notes' => $notes ?? [
                'Energy balance requires solar generation and household consumption data. Once connected data sources exist, this endpoint returns the classified balance.',
                'A balance result is a potential energy situation, never confirmed grid import/export.',
            ],
        ];
    }

    private function classifyValue(float $balance, float $solarValue, float $consumptionValue): array
    {
        $tolerance = max(
            self::BALANCED_ABSOLUTE_TOLERANCE_KWH,
            self::BALANCED_RELATIVE_TOLERANCE * max(abs($solarValue), abs($consumptionValue))
        );

        if (abs($balance) <= $tolerance) {
            return [
                self::CLASSIFICATION_BALANCED,
                sprintf(
                    'Solar generation (%.4f kWh) and household consumption (%.4f kWh) are within the balance tolerance (%.4f kWh).',
                    $solarValue,
                    $consumptionValue,
                    $tolerance
                ),
            ];
        }

        if ($balance > 0) {
            return [
                self::CLASSIFICATION_SURPLUS,
                sprintf(
                    'Solar generation (%.4f kWh) exceeds household consumption (%.4f kWh) by %.4f kWh — a potential surplus.',
                    $solarValue,
                    $consumptionValue,
                    $balance
                ),
            ];
        }

        return [
            self::CLASSIFICATION_DEFICIT,
            sprintf(
                'Household consumption (%.4f kWh) exceeds solar generation (%.4f kWh) by %.4f kWh — a potential deficit.',
                $consumptionValue,
                $solarValue,
                abs($balance)
            ),
        ];
    }

    private function echoInputs(array $solar, array $consumption): array
    {
        return [
            'solar_generation' => $this->echoInput($solar, 'kWh of solar generation'),
            'household_consumption' => $this->echoInput($consumption, 'kWh of household consumption'),
        ];
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

    private function buildNotes(string $resultNature, array $solar, array $consumption): array
    {
        $notes = [
            'This is a potential energy situation calculated as solar generation minus household consumption. It is not a confirmed grid export or import measurement.',
        ];

        if ($resultNature === 'PREDICTED') {
            $notes[] = 'At least one input is predicted — this balance describes an expected future or reconstructed situation, not a measured one.';
        }

        if ($resultNature === 'ESTIMATED') {
            $notes[] = 'At least one input is estimated — the balance is indicative only and not based on direct measurement.';
        }

        if ($resultNature === 'SIMULATED') {
            $notes[] = 'Inputs are simulated (demo pipeline) — the analysis demonstrates the real calculation pipeline and does not describe real household energy.';
        }

        return $notes;
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
