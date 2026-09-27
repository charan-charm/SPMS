<?php

declare(strict_types=1);

namespace App\Domain\Forecast;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * F3 — Normalized Forecast Snapshot DTO (docs/01 §13-14, docs/06 §3, docs/10 D-040).
 *
 * Immutable contract holding the normalized forecast results:
 * - Period & resolution
 * - Predicted value & uncertainty range (lower, upper)
 * - Confidence & Data Nature (PREDICTED | ESTIMATED, never ACTUAL)
 * - Data Quality & Provenance
 * - Explicit list of limitations / caveats
 * - Optional time-series decomposition
 */
final class ForecastSnapshot
{
    public const NATURE_PREDICTED = 'PREDICTED';
    public const NATURE_ESTIMATED = 'ESTIMATED';
    public const NATURE_SIMULATED = 'SIMULATED';

    public const QUALITY_GOOD = 'GOOD';
    public const QUALITY_PARTIAL = 'PARTIAL';
    public const QUALITY_UNVERIFIED = 'UNVERIFIED';

    /**
     * @param array{start: string, end: string, resolution: string} $period
     * @param array{lower: ?float, upper: ?float} $uncertaintyRange
     * @param array<string, mixed> $provenance
     * @param list<string> $limitations
     * @param list<array<string, mixed>> $timeSeriesPoints
     */
    public function __construct(
        private readonly string $forecastType, // 'SOLAR' | 'CONSUMPTION' | 'COMBINED'
        private readonly string $horizon,      // 'DAY_AHEAD' | 'SEVEN_DAY' | 'MONTHLY'
        private readonly array $period,
        private readonly ?float $predictedValue,
        private readonly array $uncertaintyRange,
        private readonly string $confidence,
        private readonly string $dataNature,
        private readonly string $dataQuality,
        private readonly array $provenance,
        private readonly array $limitations = [],
        private readonly array $timeSeriesPoints = []
    ) {
        if (!in_array($this->dataNature, [self::NATURE_PREDICTED, self::NATURE_ESTIMATED, self::NATURE_SIMULATED], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid forecast data nature "%s". Must be PREDICTED, ESTIMATED, or SIMULATED (never ACTUAL).',
                $this->dataNature
            ));
        }

        ForecastConfidence::validate($this->confidence);
        ForecastHorizon::validate($this->horizon);
    }

    public function forecastType(): string
    {
        return $this->forecastType;
    }

    public function horizon(): string
    {
        return $this->horizon;
    }

    /**
     * @return array{start: string, end: string, resolution: string}
     */
    public function period(): array
    {
        return $this->period;
    }

    public function predictedValue(): ?float
    {
        return $this->predictedValue;
    }

    /**
     * @return array{lower: float, upper: float}
     */
    public function uncertaintyRange(): array
    {
        return $this->uncertaintyRange;
    }

    public function confidence(): string
    {
        return $this->confidence;
    }

    public function dataNature(): string
    {
        return $this->dataNature;
    }

    public function dataQuality(): string
    {
        return $this->dataQuality;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /**
     * @return list<string>
     */
    public function limitations(): array
    {
        return $this->limitations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeSeriesPoints(): array
    {
        return $this->timeSeriesPoints;
    }

    /**
     * Serialize to API/frontend representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'forecast_type' => $this->forecastType,
            'horizon' => $this->horizon,
            'period' => $this->period,
            'predicted_value' => $this->predictedValue !== null ? round($this->predictedValue, 3) : null,
            'uncertainty_range' => [
                'lower' => ($this->uncertaintyRange['lower'] ?? null) !== null ? round((float) $this->uncertaintyRange['lower'], 3) : null,
                'upper' => ($this->uncertaintyRange['upper'] ?? null) !== null ? round((float) $this->uncertaintyRange['upper'], 3) : null,
            ],
            'confidence' => $this->confidence,
            'data_nature' => $this->dataNature,
            'data_quality' => $this->dataQuality,
            'provenance' => $this->provenance,
            'limitations' => $this->limitations,
            'time_series' => $this->timeSeriesPoints,
        ];
    }
}
