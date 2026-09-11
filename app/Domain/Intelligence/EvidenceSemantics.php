<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Severity orderings for semantic evidence values.
 *
 * These maps mirror the semantics already locked in F4/F5 (data nature,
 * data status, docs/05 §7) without modifying those locked services.
 * "Worst of" semantics combine evidence: degradation is propagated, never
 * upgraded (docs/10 D-048 — SIMULATED stays SIMULATED).
 */
final class EvidenceSemantics
{
    public const NATURES = ['ACTUAL', 'PREDICTED', 'ESTIMATED', 'SIMULATED'];
    public const STATUSES = ['FRESH', 'STALE', 'DEGRADED', 'UNAVAILABLE'];
    public const QUALITIES = ['VERIFIED', 'GOOD', 'PARTIAL', 'UNVERIFIED', 'POOR'];

    private const NATURE_SEVERITY = [
        'ACTUAL' => 0,
        'ESTIMATED' => 1,
        'PREDICTED' => 2,
        'SIMULATED' => 3,
    ];

    private const STATUS_SEVERITY = [
        'FRESH' => 0,
        'STALE' => 1,
        'DEGRADED' => 2,
        'UNAVAILABLE' => 3,
    ];

    private const QUALITY_SEVERITY = [
        'VERIFIED' => 0,
        'GOOD' => 1,
        'PARTIAL' => 2,
        'UNVERIFIED' => 3,
        'POOR' => 4,
    ];

    /**
     * Worst (least trustworthy) nature among the given values.
     * Returns null when no nature is present at all — meaning no
     * analytical value exists, which is honest, not zero.
     *
     * @param array<int, string|null> $natures
     */
    public static function worstNature(array $natures): ?string
    {
        $worst = null;
        $worstSeverity = -1;
        foreach ($natures as $nature) {
            if (!is_string($nature) || !isset(self::NATURE_SEVERITY[$nature])) {
                continue;
            }
            if (self::NATURE_SEVERITY[$nature] > $worstSeverity) {
                $worstSeverity = self::NATURE_SEVERITY[$nature];
                $worst = $nature;
            }
        }

        return $worst;
    }

    /**
     * Worst status among the given values. UNAVAILABLE is the floor when
     * nothing usable exists.
     *
     * @param array<int, string|null> $statuses
     */
    public static function worstStatus(array $statuses, bool $defaultUnavailable = false): ?string
    {
        $worst = null;
        $worstSeverity = -1;
        foreach ($statuses as $status) {
            if (!is_string($status) || !isset(self::STATUS_SEVERITY[$status])) {
                continue;
            }
            if (self::STATUS_SEVERITY[$status] > $worstSeverity) {
                $worstSeverity = self::STATUS_SEVERITY[$status];
                $worst = $status;
            }
        }

        if ($worst === null && $defaultUnavailable) {
            return 'UNAVAILABLE';
        }

        return $worst;
    }

    /**
     * Worst quality among the given values; null when none was declared.
     * Quality is never invented and never upgraded.
     *
     * @param array<int, string|null> $qualities
     */
    public static function worstQuality(array $qualities): ?string
    {
        $worst = null;
        $worstSeverity = -1;
        foreach ($qualities as $quality) {
            if (!is_string($quality) || !isset(self::QUALITY_SEVERITY[$quality])) {
                continue;
            }
            if (self::QUALITY_SEVERITY[$quality] > $worstSeverity) {
                $worstSeverity = self::QUALITY_SEVERITY[$quality];
                $worst = $quality;
            }
        }

        return $worst;
    }
}
