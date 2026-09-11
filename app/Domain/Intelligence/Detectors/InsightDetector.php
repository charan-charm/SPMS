<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\InsightEvidenceInput;

/**
 * F6 — Deterministic detector contract (docs/10 D-043).
 *
 * A detector reads validated evidence only, recomputes nothing that F4/F5
 * already classified, and returns zero or more candidates. It never
 * persists anything and never invents data that is not present.
 */
interface InsightDetector
{
    /**
     * @return array<int, \App\Domain\Intelligence\DetectedCandidate>
     */
    public function detect(InsightEvidenceInput $evidence): array;
}
