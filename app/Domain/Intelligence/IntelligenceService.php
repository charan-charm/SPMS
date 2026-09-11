<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Repositories\InsightRepository;

/**
 * F6 — Intelligence application service (docs/10 D-046/D-047).
 *
 * This service is the controlled internal generation seam: the ONLY place
 * where detection, evaluation and persistence happen. It is never invoked
 * by HTTP reads — GET /api/v1/intelligence/insights is strictly read-only.
 * Future ingestion (GoodWe synchronization, prediction, bills) will call
 * these methods from backend-controlled paths.
 *
 * Generation is deterministic: F4/F5 pipeline outputs are obtained
 * in-process, adapted to canonical evidence, evaluated, and upserted with
 * deduplication, so repeated controlled refreshes converge instead of
 * duplicating insights.
 */
final class IntelligenceService
{
    public function __construct(
        private readonly InsightRepository $repository,
        private readonly InsightEngine $engine = new InsightEngine()
    ) {
    }

    /**
     * Deterministic refresh from the F4/F5 read-only pipeline results.
     * The locked F4/F5 services are called as-is (never modified); their
     * outputs are adapted into evidence and evaluated. Persistence is
     * deduplicated by (household, type, affected period), so repeated
     * refreshes converge instead of duplicating insights.
     *
     * @return array<int, Insight> insights created or re-observed by this refresh
     */
    public function refreshFromEnergyPipeline(?string $householdId, \DateTimeImmutable $now): array
    {
        $balanceService = new \App\Domain\Energy\EnergyBalanceService();
        $costService = new \App\Domain\Energy\EnergyCostService();

        $evidence = EnergyIntelligenceAdapter::fromF4F5Results(
            $balanceService->unavailableResult($householdId),
            $costService->historicalResult($householdId),
            $householdId
        );

        $persisted = [];
        foreach ($this->engine->evaluate($evidence, $now) as $insight) {
            $persisted[] = $this->repository->upsert($insight, $now);
        }

        return $persisted;
    }

    /**
     * Evaluate arbitrary (e.g. simulated) F4/F5 payloads through the same
     * deterministic path without inventing a second engine.
     *
     * @return array<int, Insight>
     */
    public function refreshFromPayloads(
        ?array $balanceResult,
        ?array $costResult,
        ?string $householdId,
        \DateTimeImmutable $now
    ): array {
        $evidence = EnergyIntelligenceAdapter::fromF4F5Results($balanceResult, $costResult, $householdId);

        $persisted = [];
        foreach ($this->engine->evaluate($evidence, $now) as $insight) {
            $persisted[] = $this->repository->upsert($insight, $now);
        }

        return $persisted;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, Insight>
     */
    public function listInsights(array $filters): array
    {
        return $this->repository->list($filters);
    }

    public function findInsight(string $id): ?Insight
    {
        return $this->repository->findById($id);
    }
}
