<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

/**
 * F6 — Shared two-period comparison for ordered period records.
 *
 * Detectors compare the two most recent comparable records. The arithmetic
 * here (delta, relative change) is detection arithmetic on evidence the
 * pipeline already produced — F6 never recomputes an F4 balance or an F5
 * cost from raw inputs.
 */
final class TwoPeriodComparison
{
    /**
     * @param array<int, array<string, mixed>> $records ordered records, each
     *        with a numeric value under $valueKey
     * @return array{previous: array<string, mixed>, current: array<string, mixed>, previousValue: float, currentValue: float, delta: float, relative: ?float}|null
     */
    public static function evaluate(array $records, string $valueKey): ?array
    {
        $count = count($records);
        if ($count < 2) {
            return null;
        }

        $previous = $records[$count - 2];
        $current = $records[$count - 1];

        $previousValue = (float) $previous[$valueKey];
        $currentValue = (float) $current[$valueKey];

        $delta = $currentValue - $previousValue;
        $relative = $previousValue != 0.0 ? $delta / abs($previousValue) : null;

        return [
            'previous' => $previous,
            'current' => $current,
            'previousValue' => $previousValue,
            'currentValue' => $currentValue,
            'delta' => $delta,
            'relative' => $relative,
        ];
    }
}
