<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

/**
 * F6 — Minimal formatting for evidence facts and explanations.
 *
 * Facts are analytical strings stored with the insight (docs/05 §3 — no
 * presentation strings as authoritative values; the authoritative values
 * remain the structured semantic fields).
 */
final class EvidenceFormat
{
    public static function kwh(float $value): string
    {
        return self::number($value) . ' kWh';
    }

    public static function money(float $value, string $currency): string
    {
        return self::number($value) . ' ' . $currency;
    }

    public static function percent(float $ratio): string
    {
        return self::number($ratio * 100.0) . '%';
    }

    /**
     * @param array{start: ?string, end: ?string, resolution: ?string}|null $period
     */
    public static function periodLabel(?array $period): string
    {
        if ($period === null) {
            return 'unspecified period';
        }

        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;

        if ($start !== null && $end !== null) {
            return $start . ' to ' . $end;
        }

        return $start ?? $end ?? 'unspecified period';
    }

    private static function number(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
