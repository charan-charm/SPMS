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
    private readonly InsightRepository $repository;
    private readonly InsightEngine $engine;

    public function __construct(
        ?InsightRepository $repository = null,
        ?InsightEngine $engine = null
    ) {
        $this->repository = $repository ?? new InsightRepository();
        $this->engine = $engine ?? new InsightEngine();
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

        if ($householdId === null) {
            $evidence = EnergyIntelligenceAdapter::fromF4F5Results(
                $balanceService->unavailableResult(null),
                $costService->historicalResult(null),
                null
            );

            $persisted = [];
            foreach ($this->engine->evaluate($evidence, $now) as $insight) {
                $persisted[] = $this->repository->upsert($insight, $now);
            }

            return $persisted;
        }

        // Assemble actual evidence for the specified household
        $billRepo = new \App\Repositories\BillRepository();
        $allBills = $billRepo->listForHousehold($householdId);
        $verifiedBills = array_values(array_filter($allBills, static fn ($b): bool => $b->status() === 'VERIFIED'));
        usort($verifiedBills, static fn ($a, $b): int => strcmp($a->value('billing_period_start') ?? '', $b->value('billing_period_start') ?? ''));

        $consumptionPeriods = [];
        foreach ($verifiedBills as $vb) {
            $cons = (float) ($vb->value('consumption_kwh') ?? 0.0);
            $start = $vb->value('billing_period_start');
            $end = $vb->value('billing_period_end');
            if ($start !== null && $end !== null && $cons > 0.0) {
                $consumptionPeriods[] = [
                    'period' => [
                        'start' => $start,
                        'end' => $end,
                        'resolution' => 'MONTH',
                    ],
                    'value_kwh' => $cons,
                    'data_nature' => \App\Support\Config::get('SPMS_DATA_ENVIRONMENT') === 'demo' ? 'SIMULATED' : 'ACTUAL',
                    'data_status' => 'FRESH',
                    'quality' => 'VERIFIED',
                ];
            }
        }

        $solarPeriods = [];
        try {
            $db = \App\Support\Database::connection();
            $stmt = $db->prepare('SELECT DATE(measurement_time) as mdate, MAX(daily_generation_kwh) as daily_kwh, data_nature, data_status, data_quality FROM solar_readings WHERE household_id = :hid AND daily_generation_kwh IS NOT NULL GROUP BY DATE(measurement_time), data_nature, data_status, data_quality ORDER BY mdate ASC');
            $stmt->execute(['hid' => $householdId]);
            $dailyRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $byDate = [];
            foreach ($dailyRows as $row) {
                $mdate = (string) $row['mdate'];
                $kwh = (float) $row['daily_kwh'];
                $status = (string) ($row['data_status'] ?? 'FRESH');
                $quality = (string) ($row['data_quality'] ?? 'GOOD');
                $nature = (string) ($row['data_nature'] ?? 'ACTUAL');

                if (!isset($byDate[$mdate])) {
                    $byDate[$mdate] = [
                        'mdate' => $mdate,
                        'kwh' => $kwh,
                        'statuses' => [$status],
                        'qualities' => [$quality],
                        'natures' => [$nature],
                    ];
                } else {
                    $byDate[$mdate]['kwh'] = max($byDate[$mdate]['kwh'], $kwh);
                    $byDate[$mdate]['statuses'][] = $status;
                    $byDate[$mdate]['qualities'][] = $quality;
                    $byDate[$mdate]['natures'][] = $nature;
                }
            }

            foreach ($byDate as $mdate => $d) {
                $solarPeriods[] = [
                    'period' => [
                        'start' => $mdate,
                        'end' => $mdate,
                        'resolution' => 'DAY',
                    ],
                    'value_kwh' => $d['kwh'],
                    'data_nature' => EvidenceSemantics::worstNature($d['natures']) ?? 'ACTUAL',
                    'data_status' => EvidenceSemantics::worstStatus($d['statuses']) ?? 'FRESH',
                    'quality' => EvidenceSemantics::worstQuality($d['qualities']) ?? 'GOOD',
                ];
            }
        } catch (\Throwable) {
            // Degrade gracefully
        }

        $balanceResults = [];
        $forecastPeriods = [];
        $fc = null;
        try {
            $forecastService = new \App\Domain\Forecast\ForecastService();
            $fc = $forecastService->getForecast($householdId, 'DAY_AHEAD', $now, false);
            if (isset($fc['energy_balance_projection']) && ($fc['energy_balance_projection']['state'] ?? '') === 'CLASSIFIED') {
                $eb = $fc['energy_balance_projection'];
                $balanceResults[] = [
                    'analysis' => [
                        'type' => 'AGGREGATE',
                        'formula' => 'solar_generation_kwh - household_consumption_kwh',
                        'period' => [
                            'start' => $fc['solar_forecast']['period']['start'] ?? null,
                            'end' => $fc['solar_forecast']['period']['end'] ?? null,
                            'resolution' => 'DAY',
                        ],
                    ],
                    'scope' => ['household_id' => $householdId],
                    'inputs' => null,
                    'result' => [
                        'state' => 'CLASSIFIED',
                        'classification' => $eb['classification'],
                        'balance_kwh' => $eb['balance_kwh'],
                        'explanation' => $eb['explanation'],
                        'data_nature' => $eb['data_nature'],
                        'data_status' => 'FRESH',
                        'measurement_context' => $eb['measurement_context'],
                        'confidence_level' => $eb['confidence'],
                        'quality' => 'GOOD',
                        'tolerance' => ['applied_kwh' => 0.1],
                    ],
                    'notes' => [],
                ];
            }

            if (isset($fc['time_series']) && is_array($fc['time_series'])) {
                foreach ($fc['time_series'] as $pt) {
                    if (isset($pt['timestamp']) && isset($pt['projected_balance_kwh'])) {
                        $balKwh = (float) $pt['projected_balance_kwh'];
                        $class = $balKwh > 0.1 ? 'POTENTIAL_SURPLUS' : ($balKwh < -0.1 ? 'POTENTIAL_DEFICIT' : 'BALANCED');
                        $balanceResults[] = [
                            'analysis' => [
                                'type' => 'INTERVAL',
                                'formula' => 'predicted_solar_kwh - predicted_consumption_kwh',
                                'period' => [
                                    'start' => $pt['timestamp'],
                                    'end' => $pt['timestamp'],
                                    'resolution' => 'HOUR',
                                ],
                            ],
                            'scope' => ['household_id' => $householdId],
                            'inputs' => null,
                            'result' => [
                                'state' => 'CLASSIFIED',
                                'classification' => $class,
                                'balance_kwh' => $balKwh,
                                'explanation' => "Hourly projected balance: {$balKwh} kWh ({$class}).",
                                'data_nature' => $fc['data_nature'] ?? 'PREDICTED',
                                'data_status' => 'FRESH',
                                'measurement_context' => 'POTENTIAL',
                                'confidence_level' => $fc['combined_confidence'] ?? null,
                                'quality' => 'GOOD',
                                'tolerance' => ['applied_kwh' => 0.1],
                            ],
                            'notes' => [],
                        ];
                    }
                }
            }

            if (isset($fc['solar_forecast']) && isset($fc['solar_forecast']['predicted_value'])) {
                $sf = $fc['solar_forecast'];
                $forecastPeriods[] = [
                    'period' => [
                        'start' => $sf['period']['start'] ?? null,
                        'end' => $sf['period']['end'] ?? null,
                        'resolution' => $sf['period']['resolution'] ?? 'HOURLY',
                    ],
                    'value_kwh' => (float) $sf['predicted_value'],
                    'data_nature' => $sf['data_nature'] ?? 'PREDICTED',
                    'data_status' => 'FRESH',
                    'quality' => $sf['data_quality'] ?? 'GOOD',
                    'confidence_level' => $sf['confidence'] ?? null,
                ];
            }
        } catch (\Throwable) {
            // Degrade gracefully
        }

        if ($balanceResults === []) {
            $balanceResults[] = $balanceService->unavailableResult($householdId);
        }

        $costResults = [$costService->historicalResult($householdId)];
        if ($fc !== null && isset($fc['energy_cost_projection']) && is_array($fc['energy_cost_projection'])) {
            $ec = $fc['energy_cost_projection'];
            $costResults[] = [
                'period' => [
                    'start' => $fc['solar_forecast']['period']['start'] ?? null,
                    'end' => $fc['solar_forecast']['period']['end'] ?? null,
                    'resolution' => $fc['solar_forecast']['period']['resolution'] ?? 'DAY',
                ],
                'result' => [
                    'state' => $ec['state'] ?? 'ESTIMATED',
                    'cost_type' => $ec['cost_type'] ?? 'FUTURE_ESTIMATE',
                    'estimated_variable_cost' => isset($ec['estimated_cost_inr']) ? (float) $ec['estimated_cost_inr'] : null,
                    'currency' => $ec['currency'] ?? 'INR',
                    'pricing_basis' => $ec['pricing_basis'] ?? null,
                    'data_nature' => 'PREDICTED',
                    'data_status' => 'FRESH',
                    'confidence_level' => $fc['combined_confidence'] ?? ($ec['confidence'] ?? null),
                    'explanation' => $ec['explanation'] ?? null,
                ],
            ];
        }

        $allStatuses = [];
        foreach ($consumptionPeriods as $cp) {
            $allStatuses[] = $cp['data_status'] ?? null;
        }
        foreach ($solarPeriods as $sp) {
            $allStatuses[] = $sp['data_status'] ?? null;
        }
        foreach ($forecastPeriods as $fp) {
            $allStatuses[] = $fp['data_status'] ?? null;
        }
        foreach ($balanceResults as $br) {
            $allStatuses[] = $br['result']['data_status'] ?? null;
        }
        foreach ($costResults as $cr) {
            $allStatuses[] = $cr['result']['data_status'] ?? null;
        }
        $overallStatus = EvidenceSemantics::worstStatus($allStatuses) ?? 'FRESH';

        $allQualities = [];
        foreach ($consumptionPeriods as $cp) {
            $allQualities[] = $cp['quality'] ?? null;
        }
        foreach ($solarPeriods as $sp) {
            $allQualities[] = $sp['quality'] ?? null;
        }
        foreach ($forecastPeriods as $fp) {
            $allQualities[] = $fp['quality'] ?? null;
        }
        foreach ($balanceResults as $br) {
            $allQualities[] = $br['result']['quality'] ?? null;
        }
        $overallQuality = EvidenceSemantics::worstQuality($allQualities) ?? ($consumptionPeriods !== [] ? 'VERIFIED' : 'UNVERIFIED');

        $sources = ['APSPDCL_VERIFIED_BILLS', 'F3_FORECAST_PIPELINE'];
        if ($solarPeriods !== []) {
            $sources[] = 'GOODWE_SOLAR_TELEMETRY';
        }

        $weatherData = null;
        try {
            $weatherService = new \App\Domain\Weather\WeatherService();
            $weatherData = $weatherService->getWeatherForHousehold($householdId, $now);
            if ($weatherData !== null && !empty($weatherData['available'])) {
                $sources[] = 'OPEN_METEO_WEATHER_FORECAST';
            }
        } catch (\Throwable) {
            // Weather provider failure is non-blocking to solar/bill intelligence
        }

        $solarConnData = null;
        try {
            $connRepo = new \App\Repositories\SolarConnectionRepository();
            $conn = $connRepo->findByHouseholdId($householdId);
            if ($conn !== null) {
                $readingsRepo = new \App\Repositories\SolarReadingRepository();
                $latestReading = $readingsRepo->findLatestByHouseholdId($householdId);

                $solarConnData = [
                    'household_id' => $householdId,
                    'station_id' => $conn['station_id'] ?? null,
                    'status' => $conn['connection_status'] ?? $conn['status'] ?? 'CONNECTED',
                    'inverter_status' => is_array($latestReading)
                        ? ($latestReading['inverter_status'] ?? $conn['connection_status'] ?? 'ONLINE')
                        : ($latestReading?->inverterStatus() ?? $conn['status'] ?? 'ONLINE'),
                    'last_telemetry_at' => is_array($latestReading)
                        ? ($latestReading['measurement_time'] ?? null)
                        : ($latestReading?->measurementTime() ?? null),
                    'power_w' => is_array($latestReading)
                        ? (float) ($latestReading['power_w'] ?? 0.0)
                        : ($latestReading?->powerW() ?? 0.0),
                    'daily_generation_kwh' => is_array($latestReading)
                        ? (float) ($latestReading['daily_generation_kwh'] ?? 0.0)
                        : ($latestReading?->dailyGenerationKwh() ?? 0.0),
                    'data_status' => is_array($latestReading)
                        ? ($latestReading['data_status'] ?? 'FRESH')
                        : ($latestReading?->dataStatus() ?? 'FRESH'),
                    'data_nature' => is_array($latestReading)
                        ? ($latestReading['data_nature'] ?? 'ACTUAL')
                        : ($latestReading?->dataNature() ?? 'ACTUAL'),
                ];
            }
        } catch (\Throwable) {
            // Degrade gracefully
        }

        $evidence = InsightEvidenceInput::fromArray([
            'household_id' => $householdId,
            'consumption' => $consumptionPeriods,
            'solar' => $solarPeriods,
            'forecast' => $forecastPeriods,
            'balance' => $balanceResults,
            'cost' => $costResults,
            'weather' => $weatherData,
            'solar_connection' => $solarConnData,
            'quality' => [
                'overall_status' => $overallStatus,
                'overall_quality' => $overallQuality,
            ],
            'provenance' => [
                'sources' => $sources,
                'pipeline' => ['SPMS_REAL_DATA_PIPELINE'],
            ],
        ]);

        $persisted = [];
        $evaluated = $this->engine->evaluate($evidence, $now);
        foreach ($evaluated as $insight) {
            $persisted[] = $this->repository->upsert($insight, $now);
        }

        // Safe Condition Resolution (CHECK 2):
        // Only resolve ongoing conditions when the detector has successfully evaluated
        // sufficient positive evidence establishing that the condition no longer applies.
        $detectedTypes = array_map(static fn (Insight $i): string => $i->type(), $persisted);

        // 1. Solar Telemetry Stale: Resolve only when fresh telemetry is confirmed received
        if (
            $solarConnData !== null
            && ($solarConnData['data_status'] ?? '') === 'FRESH'
            && !empty($solarConnData['last_telemetry_at'])
            && !in_array(InsightTaxonomy::TYPE_SOLAR_TELEMETRY_STALE, $detectedTypes, true)
        ) {
            $this->repository->resolveCondition($householdId, InsightTaxonomy::TYPE_SOLAR_TELEMETRY_STALE, $now);
        }

        // 2. Inverter Disconnected: Resolve only when inverter communication is confirmed online
        $inverterStatus = strtoupper(trim((string) ($solarConnData['inverter_status'] ?? $solarConnData['status'] ?? '')));
        if (
            $solarConnData !== null
            && !in_array($inverterStatus, ['OFFLINE', 'FAULT', 'DISCONNECTED', ''], true)
            && ($solarConnData['data_status'] ?? '') === 'FRESH'
            && !in_array(InsightTaxonomy::TYPE_INVERTER_DISCONNECTED, $detectedTypes, true)
        ) {
            $this->repository->resolveCondition($householdId, InsightTaxonomy::TYPE_INVERTER_DISCONNECTED, $now);
        }

        // 3. Weather Data Unavailable: Resolve only when weather service is confirmed active with forecast
        if (
            $weatherData !== null
            && !empty($weatherData['available'])
            && ($weatherData['status'] ?? '') === 'ACTIVE'
            && !empty($weatherData['hourly'])
            && !in_array(InsightTaxonomy::TYPE_WEATHER_DATA_UNAVAILABLE, $detectedTypes, true)
        ) {
            $this->repository->resolveCondition($householdId, InsightTaxonomy::TYPE_WEATHER_DATA_UNAVAILABLE, $now);
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
