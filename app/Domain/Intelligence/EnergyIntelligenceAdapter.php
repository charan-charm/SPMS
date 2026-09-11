<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — F4/F5 evidence adapter (docs/10 D-044/D-045).
 *
 * Maps result payloads produced by the locked energy balance (F4) and
 * energy cost (F5) services into the canonical evidence input. The adapter
 * copies evidence verbatim into the F6 shape: it never recalculates a
 * balance, never recomputes a cost, and never upgrades a data nature,
 * status, or confidence value.
 */
final class EnergyIntelligenceAdapter
{
    public const SOURCE_BALANCE_PIPELINE = 'F4_ENERGY_BALANCE_PIPELINE';
    public const SOURCE_COST_PIPELINE = 'F5_ENERGY_COST_PIPELINE';

    /**
     * @param array<string, mixed>|null $balanceResult F4 classify()/unavailableResult() payload
     * @param array<string, mixed>|null $costResult    F5 estimate()/historicalResult() payload
     * @param array{sources?: array<int, string>, pipeline?: array<int, string>} $provenance
     */
    public static function fromF4F5Results(
        ?array $balanceResult,
        ?array $costResult,
        ?string $householdId = null,
        array $provenance = []
    ): InsightEvidenceInput {
        return InsightEvidenceInput::fromArray(self::payload($balanceResult, $costResult, [], $householdId, $provenance));
    }

    /**
     * Extend F4/F5 results with additional period evidence (solar,
     * consumption, forecast). Used by deterministic evaluation paths that
     * already hold pipeline outputs plus period records.
     *
     * @param array<string, mixed> $evidence additional evidence sections
     *        (solar, consumption, forecast, historical, quality)
     */
    public static function fromF4F5ResultsWithEvidence(
        ?array $balanceResult,
        ?array $costResult,
        array $evidence,
        ?string $householdId = null
    ): InsightEvidenceInput {
        return InsightEvidenceInput::fromArray(self::payload($balanceResult, $costResult, $evidence, $householdId));
    }

    /**
     * @param array<string, mixed> $extra
     * @param array{sources?: array<int, string>, pipeline?: array<int, string>} $provenance
     * @return array<string, mixed>
     */
    private static function payload(?array $balanceResult, ?array $costResult, array $extra, ?string $householdId, array $provenance = []): array
    {
        $sources = $provenance['sources'] ?? [];
        $pipeline = $provenance['pipeline'] ?? [];

        if ($balanceResult !== null) {
            $sources[] = self::SOURCE_BALANCE_PIPELINE;
            $pipeline[] = 'EnergyBalanceService (F4, locked)';
        }

        if ($costResult !== null) {
            $sources[] = self::SOURCE_COST_PIPELINE;
            $pipeline[] = 'EnergyCostService (F5, locked)';
        }

        return array_merge([
            'household_id' => $householdId,
            'balance' => $balanceResult === null ? [] : [self::normalizeBalanceResult($balanceResult)],
            'cost' => $costResult === null ? [] : [$costResult],
            'provenance' => [
                'sources' => $sources,
                'pipeline' => $pipeline,
            ],
        ], $extra);
    }

    /**
     * F4 payloads echo the period inside analysis.period; the canonical
     * evidence shape expects it at the top level. Values are moved, never
     * altered.
     *
     * @param array<string, mixed> $balanceResult
     * @return array<string, mixed>
     */
    private static function normalizeBalanceResult(array $balanceResult): array
    {
        if (!isset($balanceResult['period']) && isset($balanceResult['analysis']['period'])) {
            $balanceResult['period'] = $balanceResult['analysis']['period'];
        }

        return $balanceResult;
    }
}
