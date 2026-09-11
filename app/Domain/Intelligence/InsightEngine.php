<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Detectors\ConsumptionChangeDetector;
use App\Domain\Intelligence\Detectors\ConsumptionPatternDetector;
use App\Domain\Intelligence\Detectors\CostPatternDetector;
use App\Domain\Intelligence\Detectors\DataLimitationDetector;
use App\Domain\Intelligence\Detectors\EnergySituationPatternDetector;
use App\Domain\Intelligence\Detectors\InsightDetector;
use App\Domain\Intelligence\Detectors\SolarPatternDetector;
use App\Domain\Intelligence\Detectors\SolarProductionChangeDetector;

/**
 * F6 — Deterministic intelligence engine (docs/10 D-043..D-045).
 *
 * Runs the controlled detector set over validated evidence, assigns
 * significance and independent F6 confidence, deduplicates candidates by
 * household + type + affected period, and returns fully evaluated insights
 * in a deterministic order. The engine performs no persistence and no I/O.
 */
final class InsightEngine
{
    /** @var array<int, InsightDetector> */
    private array $detectors;

    public function __construct(
        private readonly SignificanceModel $significanceModel = new SignificanceModel(),
        private readonly ConfidenceModel $confidenceModel = new ConfidenceModel(),
        ?array $detectors = null
    ) {
        $this->detectors = $detectors ?? self::defaultDetectors();
    }

    /** @return array<int, InsightDetector> */
    public static function defaultDetectors(): array
    {
        return [
            new SolarProductionChangeDetector(),
            new SolarPatternDetector(),
            new ConsumptionChangeDetector(),
            new ConsumptionPatternDetector(),
            new EnergySituationPatternDetector(),
            new CostPatternDetector(),
            new DataLimitationDetector(),
        ];
    }

    /**
     * @return array<int, Insight>
     */
    public function evaluate(InsightEvidenceInput $evidence, \DateTimeImmutable $now): array
    {
        $provenanceContext = $evidence->provenanceContext();

        $unique = [];
        foreach ($this->detectors as $detector) {
            foreach ($detector->detect($evidence) as $candidate) {
                $key = implode('|', [
                    $evidence->householdId() ?? '',
                    $candidate->type,
                    $candidate->periodKey(),
                ]);

                if (!isset($unique[$key])) {
                    $unique[$key] = $candidate;
                }
            }
        }

        $insights = [];
        foreach ($unique as $candidate) {
            $confidence = $this->confidenceModel->assess($candidate, $evidence);
            $significance = $this->significanceModel->assess($candidate, $confidence);

            $insights[] = Insight::detected(
                $candidate,
                $significance,
                $confidence,
                $now,
                $evidence->householdId(),
                $provenanceContext
            );
        }

        usort($insights, static function (Insight $a, Insight $b): int {
            $bySignificance = InsightSignificance::rank($b->significance()) <=> InsightSignificance::rank($a->significance());
            if ($bySignificance !== 0) {
                return $bySignificance;
            }

            $byType = strcmp($a->type(), $b->type());
            if ($byType !== 0) {
                return $byType;
            }

            return strcmp($a->title(), $b->title());
        });

        return $insights;
    }
}
