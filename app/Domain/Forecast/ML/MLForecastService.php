<?php

declare(strict_types=1);

namespace App\Domain\Forecast\ML;

use App\Domain\Forecast\ConsumptionForecastModel;
use App\Domain\Forecast\Contracts\WeatherProviderInterface;
use App\Domain\Forecast\ForecastSnapshot;
use App\Domain\Forecast\SolarForecastModel;
use App\Integrations\Weather\OpenMeteoGeocodingClient;
use App\Repositories\BillRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\SolarReadingRepository;
use DateTimeImmutable;

/**
 * F3 ML — Isolated ML Forecasting Coordination Layer (docs/01 §14, docs/02 §2, docs/10 D-040).
 *
 * Coordinates execution of MLSolarForecastModel and MLConsumptionForecastModel,
 * manages backtesting evaluation and validation against deterministic baselines,
 * and enforces transparent fallback state semantics.
 *
 * Fallback States:
 *  - ML_AVAILABLE: ML model backtested, beat baseline, selected and active.
 *  - ML_INSUFFICIENT_DATA: Insufficient real evidence (< 24 daylight solar readings or < 3 verified bills).
 *  - ML_LOW_CONFIDENCE: Residual variance high or R2 < 0.2.
 *  - ML_EVALUATION_FAILED: ML MAE > baseline MAE on chronological test set.
 *  - DETERMINISTIC_FALLBACK: Gracefully uses validated deterministic baseline.
 *  - FORECAST_UNAVAILABLE: No verified data exists (e.g. 0 verified bills).
 */
final class MLForecastService
{
    public const STATE_ML_AVAILABLE = 'ML_AVAILABLE';
    public const STATE_ML_INSUFFICIENT_DATA = 'ML_INSUFFICIENT_DATA';
    public const STATE_ML_LOW_CONFIDENCE = 'ML_LOW_CONFIDENCE';
    public const STATE_ML_EVALUATION_FAILED = 'ML_EVALUATION_FAILED';
    public const STATE_DETERMINISTIC_FALLBACK = 'DETERMINISTIC_FALLBACK';
    public const STATE_FORECAST_UNAVAILABLE = 'FORECAST_UNAVAILABLE';

    private readonly MLSolarForecastModel $solarML;
    private readonly MLConsumptionForecastModel $consumptionML;
    private readonly ForecastModelRegistry $registry;

    public function __construct(
        SolarReadingRepository $readings,
        WeatherProviderInterface $weather,
        BillRepository $bills,
        ?HouseholdRepository $households = null,
        ?OpenMeteoGeocodingClient $geocoding = null,
        ?ForecastFeatureBuilder $featureBuilder = null,
        ?ForecastEvaluator $evaluator = null,
        ?ForecastModelRegistry $registry = null
    ) {
        $featureBuilder = $featureBuilder ?? new ForecastFeatureBuilder();
        $evaluator = $evaluator ?? new ForecastEvaluator();
        $this->registry = $registry ?? new ForecastModelRegistry();

        $this->solarML = new MLSolarForecastModel(
            readings: $readings,
            weather: $weather,
            featureBuilder: $featureBuilder,
            evaluator: $evaluator,
            registry: $this->registry,
            households: $households,
            geocoding: $geocoding,
            bills: $bills
        );

        $this->consumptionML = new MLConsumptionForecastModel(
            bills: $bills,
            readings: $readings,
            featureBuilder: $featureBuilder,
            evaluator: $evaluator,
            registry: $this->registry
        );
    }

    /**
     * Generate solar forecast with ML and baseline fallback.
     *
     * @param string $householdId
     * @param string $horizon
     * @param DateTimeImmutable $referenceTime
     * @param array<string, mixed>|null $solarConnection
     * @param SolarForecastModel $baselineModel
     * @return array{
     *     snapshot: ForecastSnapshot,
     *     status: string,
     *     forecast_source: string,
     *     fallback_reason: ?string,
     *     evaluation: ?array<string, mixed>
     * }
     */
    public function generateSolarForecast(
        string $householdId,
        string $horizon,
        DateTimeImmutable $referenceTime,
        ?array $solarConnection,
        SolarForecastModel $baselineModel
    ): array {
        return $this->solarML->generate(
            householdId: $householdId,
            horizon: $horizon,
            referenceTime: $referenceTime,
            solarConnection: $solarConnection,
            baselineModel: $baselineModel
        );
    }

    /**
     * Generate consumption forecast with ML and baseline fallback.
     *
     * @param string $householdId
     * @param string $horizon
     * @param DateTimeImmutable $referenceTime
     * @param ConsumptionForecastModel $baselineModel
     * @return array{
     *     snapshot: ForecastSnapshot,
     *     status: string,
     *     forecast_source: string,
     *     fallback_reason: ?string,
     *     evaluation: ?array<string, mixed>
     * }
     */
    public function generateConsumptionForecast(
        string $householdId,
        string $horizon,
        DateTimeImmutable $referenceTime,
        ConsumptionForecastModel $baselineModel
    ): array {
        return $this->consumptionML->generate(
            householdId: $householdId,
            horizon: $horizon,
            referenceTime: $referenceTime,
            baselineModel: $baselineModel
        );
    }

    public function registry(): ForecastModelRegistry
    {
        return $this->registry;
    }
}
