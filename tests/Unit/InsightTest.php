<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Intelligence\ConfidenceModel;
use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\Detectors\ConsumptionChangeDetector;
use App\Domain\Intelligence\Detectors\ConsumptionPatternDetector;
use App\Domain\Intelligence\Detectors\CostPatternDetector;
use App\Domain\Intelligence\Detectors\DataLimitationDetector;
use App\Domain\Intelligence\Detectors\EnergySituationPatternDetector;
use App\Domain\Intelligence\Detectors\InsightDetector;
use App\Domain\Intelligence\Detectors\SolarPatternDetector;
use App\Domain\Intelligence\Detectors\SolarProductionChangeDetector;
use App\Domain\Intelligence\EnergyIntelligenceAdapter;
use App\Domain\Intelligence\EvidenceSemantics;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\InsightConfidence;
use App\Domain\Intelligence\InsightEngine;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightLifecycle;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;
use App\Domain\Intelligence\SignificanceModel;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * F6 — Deterministic intelligence domain tests (docs/10 D-043..D-048).
 *
 * Pure domain coverage: taxonomy, canonical evidence input, the F4/F5
 * adapter, every detector, the significance and confidence models, the
 * engine (determinism + dedup) and the insight lifecycle. No database.
 */
final class InsightTest
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-11 12:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function periodRecord(float $value, string $start, string $nature = 'ACTUAL', string $status = 'FRESH', ?string $quality = null): array
    {
        return [
            'period' => ['start' => $start, 'end' => $start, 'resolution' => 'DAY'],
            'value_kwh' => $value,
            'data_nature' => $nature,
            'data_status' => $status,
            'quality' => $quality,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function classifiedBalance(string $classification, float $balanceKwh, ?string $start = null, string $nature = 'ACTUAL'): array
    {
        return [
            'period' => $start !== null ? ['start' => $start, 'end' => $start, 'resolution' => 'DAY'] : null,
            'result' => [
                'state' => 'CLASSIFIED',
                'classification' => $classification,
                'balance_kwh' => $balanceKwh,
                'explanation' => 'F4 classified result (simulated evidence).',
                'data_nature' => $nature,
                'data_status' => 'FRESH',
                'measurement_context' => 'POTENTIAL',
            ],
            'notes' => [],
        ];
    }

    /**
     * Real F4 payload (period lifted from analysis.period, mirroring the
     * adapter normalization) with declared input quality.
     *
     * @return array<string, mixed>
     */
    private static function f4BalancePayload(float $solarKwh, float $consumptionKwh, string $start, string $quality): array
    {
        $payload = (new EnergyBalanceService())->classify(
            [
                'solar' => ['value_kwh' => $solarKwh, 'nature' => 'ACTUAL', 'status' => 'FRESH', 'quality' => $quality],
                'consumption' => ['value_kwh' => $consumptionKwh, 'nature' => 'ACTUAL', 'status' => 'FRESH', 'quality' => $quality],
            ],
            'TIME_PERIOD',
            ['start' => $start, 'end' => $start, 'resolution' => 'HOUR']
        );

        $payload['period'] = $payload['analysis']['period'] ?? null;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function estimatedCost(float $amount, string $start, string $nature = 'ESTIMATED'): array
    {
        return [
            'period' => ['start' => $start, 'end' => $start, 'resolution' => 'DAY'],
            'result' => [
                'state' => 'ESTIMATED',
                'cost_type' => 'FUTURE_ESTIMATE',
                'estimated_variable_cost' => $amount,
                'currency' => 'INR',
                'pricing_basis' => 'USER_PROVIDED_RATE',
                'data_nature' => $nature,
                'data_status' => 'FRESH',
                'measurement_context' => 'ESTIMATED',
                'explanation' => 'Estimated from declared pricing evidence.',
            ],
            'notes' => [],
        ];
    }

    public static function run(): array
    {
        return [
            TestResult::check('Taxonomy contains every controlled type with a valid category', function (): void {
                $types = InsightTaxonomy::types();
                Assert::same(20, count($types));

                foreach ($types as $type) {
                    Assert::true(InsightTaxonomy::isValidType($type), 'Type must be valid: ' . $type);
                    Assert::true(InsightTaxonomy::isValidCategory((string) InsightTaxonomy::categoryForType($type)), 'Category must be valid for ' . $type);
                    Assert::true(InsightTaxonomy::descriptionForType($type) !== null, 'Description required for ' . $type);
                }

                Assert::false(InsightTaxonomy::isValidType('MADE_UP_TYPE'));
                Assert::false(InsightTaxonomy::isValidCategory('MAGIC'));
                Assert::same(4, count(InsightTaxonomy::typesForCategory(InsightTaxonomy::CATEGORY_COST)));
            }),

            TestResult::check('Evidence input defaults missing sections and preserves household scope', function (): void {
                $evidence = InsightEvidenceInput::fromArray(['household_id' => 'hh-1']);

                Assert::same('hh-1', $evidence->householdId());
                Assert::same([], $evidence->solarPeriods());
                Assert::same([], $evidence->consumptionPeriods());
                Assert::same([], $evidence->forecastPeriods());
                Assert::same([], $evidence->balanceResults());
                Assert::same([], $evidence->costResults());
                Assert::same(['overall_status' => null, 'overall_quality' => null], $evidence->qualityContext());
            }),

            TestResult::check('Evidence input validates semantic values and rejects contradictions', function (): void {
                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    InsightEvidenceInput::fromArray([
                        'solar' => [['value_kwh' => 1.0, 'data_nature' => 'MAGIC', 'data_status' => 'FRESH']],
                    ]);
                });

                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    InsightEvidenceInput::fromArray([
                        'solar' => [['value_kwh' => 1.0, 'data_nature' => 'ACTUAL', 'data_status' => 'UNAVAILABLE']],
                    ]);
                });

                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    InsightEvidenceInput::fromArray(['household_id' => str_repeat('x', 65)]);
                });

                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    InsightEvidenceInput::fromArray([
                        'consumption' => [['value_kwh' => -1.0, 'data_nature' => 'ACTUAL', 'data_status' => 'FRESH']],
                    ]);
                });
            }),

            TestResult::check('Evidence input orders periods deterministically by period start', function (): void {
                $evidence = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(2.0, '2026-09-03'),
                        self::periodRecord(1.0, '2026-09-01'),
                        self::periodRecord(3.0, '2026-09-02'),
                    ],
                ]);

                $starts = array_map(static fn (array $record): ?string => $record['period']['start'], $evidence->solarPeriods());
                Assert::same(['2026-09-01', '2026-09-02', '2026-09-03'], $starts);
            }),

            TestResult::check('Adapter maps locked F4/F5 unavailable results into evidence without modification', function (): void {
                $evidence = EnergyIntelligenceAdapter::fromF4F5Results(
                    (new EnergyBalanceService())->unavailableResult(),
                    (new EnergyCostService())->historicalResult()
                );

                Assert::same(1, count($evidence->balanceResults()));
                Assert::same('UNAVAILABLE', $evidence->balanceResults()[0]['result']['state']);
                Assert::same(null, $evidence->balanceResults()[0]['result']['classification']);

                Assert::same(1, count($evidence->costResults()));
                Assert::same('UNAVAILABLE', $evidence->costResults()[0]['result']['state']);
                Assert::same(null, $evidence->costResults()[0]['result']['estimated_variable_cost']);

                $provenance = $evidence->provenanceContext();
                Assert::true(in_array(EnergyIntelligenceAdapter::SOURCE_BALANCE_PIPELINE, $provenance['sources'], true));
                Assert::true(in_array(EnergyIntelligenceAdapter::SOURCE_COST_PIPELINE, $provenance['sources'], true));
            }),

            TestResult::check('Adapter simulated pipeline path keeps SIMULATED nature verbatim', function (): void {
                $balance = (new EnergyBalanceService())->classify(
                    [
                        'solar' => ['value_kwh' => 6.4, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'UNVERIFIED'],
                        'consumption' => ['value_kwh' => 4.1, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'UNVERIFIED'],
                    ],
                    'AGGREGATE',
                    ['start' => '2026-09-10T12:00:00', 'end' => '2026-09-10T18:00:00', 'resolution' => 'HOUR']
                );
                $cost = (new EnergyCostService())->estimate(
                    [
                        'solar' => ['value_kwh' => 6.4, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                        'consumption' => ['value_kwh' => 4.1, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                        'pricing' => ['basis' => 'USER_PROVIDED_RATE', 'unit_rate' => 8.5, 'currency' => 'INR'],
                    ],
                    ['start' => '2026-09-10', 'end' => '2026-09-10', 'resolution' => 'DAY']
                );

                Assert::same('SIMULATED', $balance['result']['data_nature']);
                Assert::same('SIMULATED', $cost['result']['data_nature']);

                $evidence = EnergyIntelligenceAdapter::fromF4F5Results($balance, $cost);

                Assert::same('CLASSIFIED', $evidence->balanceResults()[0]['result']['state']);
                Assert::same('POTENTIAL_SURPLUS', $evidence->balanceResults()[0]['result']['classification']);
                Assert::same('SIMULATED', $evidence->balanceResults()[0]['result']['data_nature']);
                Assert::same('ESTIMATED', $evidence->costResults()[0]['result']['state']);
                Assert::same('SIMULATED', $evidence->costResults()[0]['result']['data_nature']);
            }),

            TestResult::check('Solar change detector reports decline and improvement with disclosed thresholds', function (): void {
                $detector = new SolarProductionChangeDetector();

                $evidence = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(10.0, '2026-09-01'),
                        self::periodRecord(6.0, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($evidence);
                Assert::same(1, count($candidates));
                Assert::same('SOLAR_PRODUCTION_DECLINE', $candidates[0]->type);
                Assert::same(InsightSignificance::MODERATE, $candidates[0]->significanceHint);
                Assert::same('2026-09-02', $candidates[0]->affectedPeriod['start']);

                $improvement = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(4.0, '2026-09-01'),
                        self::periodRecord(6.5, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($improvement);
                Assert::same(1, count($candidates));
                Assert::same('SOLAR_PRODUCTION_IMPROVEMENT', $candidates[0]->type);

                $quiet = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(5.0, '2026-09-01'),
                        self::periodRecord(5.1, '2026-09-02'),
                    ],
                ]);
                Assert::same([], $detector->detect($quiet));

                $zeroBase = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(0.0, '2026-09-01'),
                        self::periodRecord(2.0, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($zeroBase);
                Assert::same(1, count($candidates));
                Assert::same('SOLAR_PRODUCTION_CHANGE', $candidates[0]->type);
            }),

            TestResult::check('Consumption change detector reports increase and decrease', function (): void {
                $detector = new ConsumptionChangeDetector();

                $increase = InsightEvidenceInput::fromArray([
                    'consumption' => [
                        self::periodRecord(4.0, '2026-09-01'),
                        self::periodRecord(6.0, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($increase);
                Assert::same(1, count($candidates));
                Assert::same('CONSUMPTION_INCREASE', $candidates[0]->type);
                Assert::same(InsightSignificance::HIGH, $candidates[0]->significanceHint);

                $decrease = InsightEvidenceInput::fromArray([
                    'consumption' => [
                        self::periodRecord(6.0, '2026-09-01'),
                        self::periodRecord(4.0, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($decrease);
                Assert::same(1, count($candidates));
                Assert::same('CONSUMPTION_DECREASE', $candidates[0]->type);
            }),

            TestResult::check('Solar pattern detector requires a stable band across three periods', function (): void {
                $detector = new SolarPatternDetector();

                $stable = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(5.0, '2026-09-01'),
                        self::periodRecord(5.1, '2026-09-02'),
                        self::periodRecord(4.9, '2026-09-03'),
                    ],
                ]);
                $candidates = $detector->detect($stable);
                Assert::same(1, count($candidates));
                Assert::same('RECURRING_SOLAR_PATTERN', $candidates[0]->type);
                Assert::same(3, $candidates[0]->comparisonCount);

                $volatile = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(2.0, '2026-09-01'),
                        self::periodRecord(6.0, '2026-09-02'),
                        self::periodRecord(2.0, '2026-09-03'),
                    ],
                ]);
                Assert::same([], $detector->detect($volatile));

                $twoPeriods = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(5.0, '2026-09-01'),
                        self::periodRecord(5.0, '2026-09-02'),
                    ],
                ]);
                Assert::same([], $detector->detect($twoPeriods));
            }),

            TestResult::check('Consumption pattern detector reports high demand and unusual deviation', function (): void {
                $detector = new ConsumptionPatternDetector();

                $highDemand = InsightEvidenceInput::fromArray([
                    'consumption' => [
                        self::periodRecord(5.0, '2026-09-01'),
                        self::periodRecord(6.0, '2026-09-02'),
                        self::periodRecord(7.0, '2026-09-03'),
                    ],
                ]);
                $candidates = $detector->detect($highDemand);
                $types = array_map(static fn (DetectedCandidate $c): string => $c->type, $candidates);
                Assert::true(in_array('RECURRING_HIGH_DEMAND', $types, true));

                $unusual = InsightEvidenceInput::fromArray([
                    'consumption' => [
                        self::periodRecord(4.0, '2026-09-01'),
                        self::periodRecord(4.0, '2026-09-02'),
                        self::periodRecord(4.0, '2026-09-03'),
                        self::periodRecord(8.0, '2026-09-04'),
                    ],
                ]);
                $candidates = $detector->detect($unusual);
                $types = array_map(static fn (DetectedCandidate $c): string => $c->type, $candidates);
                Assert::true(in_array('UNUSUAL_CONSUMPTION_PATTERN', $types, true));

                $calm = InsightEvidenceInput::fromArray([
                    'consumption' => [
                        self::periodRecord(4.0, '2026-09-01'),
                        self::periodRecord(4.1, '2026-09-02'),
                        self::periodRecord(3.9, '2026-09-03'),
                    ],
                ]);
                Assert::same([], $detector->detect($calm));
            }),

            TestResult::check('Energy situation detector reads F4 classifications without recomputation', function (): void {
                $detector = new EnergySituationPatternDetector();

                $surplus = InsightEvidenceInput::fromArray([
                    'balance' => [
                        self::classifiedBalance('POTENTIAL_SURPLUS', 2.0, '2026-09-01'),
                        self::classifiedBalance('POTENTIAL_SURPLUS', 3.0, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($surplus);
                Assert::same(1, count($candidates));
                Assert::same('RECURRING_POTENTIAL_SURPLUS', $candidates[0]->type);

                $mixed = InsightEvidenceInput::fromArray([
                    'balance' => [
                        self::classifiedBalance('POTENTIAL_SURPLUS', 2.0, '2026-09-01'),
                        self::classifiedBalance('POTENTIAL_DEFICIT', -2.0, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($mixed);
                $types = array_map(static fn (DetectedCandidate $c): string => $c->type, $candidates);
                Assert::true(in_array('SOLAR_DEMAND_MISMATCH', $types, true));
                Assert::false(in_array('DAYTIME_SURPLUS_EVENING_DEFICIT', $types, true), 'Without declared times no daytime/evening claim is made');

                $daytimeEvening = InsightEvidenceInput::fromArray([
                    'balance' => [
                        self::classifiedBalance('POTENTIAL_SURPLUS', 2.0, '2026-09-01T12:00:00'),
                        self::classifiedBalance('POTENTIAL_DEFICIT', -2.0, '2026-09-01T19:00:00'),
                    ],
                ]);
                $candidates = $detector->detect($daytimeEvening);
                $types = array_map(static fn (DetectedCandidate $c): string => $c->type, $candidates);
                Assert::true(in_array('DAYTIME_SURPLUS_EVENING_DEFICIT', $types, true));
            }),

            TestResult::check('Cost detector reports increase and recurring impact, never from insufficient evidence', function (): void {
                $detector = new CostPatternDetector();

                $increase = InsightEvidenceInput::fromArray([
                    'cost' => [
                        self::estimatedCost(100.0, '2026-09-01'),
                        self::estimatedCost(200.0, '2026-09-02'),
                    ],
                ]);
                $candidates = $detector->detect($increase);
                $types = array_map(static fn (DetectedCandidate $c): string => $c->type, $candidates);
                Assert::true(in_array('COST_INCREASE', $types, true));

                $pattern = InsightEvidenceInput::fromArray([
                    'cost' => [
                        self::estimatedCost(80.0, '2026-09-01'),
                        self::estimatedCost(90.0, '2026-09-02'),
                        self::estimatedCost(85.0, '2026-09-03'),
                    ],
                ]);
                $candidates = $detector->detect($pattern);
                $types = array_map(static fn (DetectedCandidate $c): string => $c->type, $candidates);
                Assert::true(in_array('COST_IMPACT_PATTERN', $types, true));

                $insufficient = InsightEvidenceInput::fromArray([
                    'cost' => [
                        [
                            'period' => ['start' => '2026-09-01', 'end' => '2026-09-01', 'resolution' => 'DAY'],
                            'result' => [
                                'state' => 'INSUFFICIENT_EVIDENCE',
                                'cost_type' => 'FUTURE_ESTIMATE',
                                'estimated_variable_cost' => null,
                                'currency' => null,
                                'pricing_basis' => 'INSUFFICIENT_EVIDENCE',
                                'data_nature' => 'ESTIMATED',
                                'data_status' => 'FRESH',
                                'explanation' => 'No reliable pricing evidence.',
                            ],
                            'notes' => [],
                        ],
                    ],
                ]);
                Assert::same([], $detector->detect($insufficient));
            }),

            TestResult::check('Data limitation detector states what is missing and invents nothing', function (): void {
                $detector = new DataLimitationDetector();

                $evidence = EnergyIntelligenceAdapter::fromF4F5Results(
                    (new EnergyBalanceService())->unavailableResult(),
                    (new EnergyCostService())->historicalResult()
                );

                $candidates = $detector->detect($evidence);
                Assert::same(1, count($candidates));
                Assert::same('DATA_LIMITATION', $candidates[0]->type);
                Assert::same(null, $candidates[0]->affectedPeriod);
                Assert::same([], $candidates[0]->natures);
                Assert::true(count($candidates[0]->limitations) >= 2);
                Assert::true($candidates[0]->affectedPeriod === null);

                $richEvidence = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(5.0, '2026-09-01'),
                        self::periodRecord(5.0, '2026-09-02'),
                        self::periodRecord(5.0, '2026-09-03'),
                    ],
                ]);
                Assert::same([], $detector->detect($richEvidence));
            }),

            TestResult::check('Engine over system context produces exactly one honest limitation insight', function (): void {
                $engine = new InsightEngine();
                $evidence = EnergyIntelligenceAdapter::fromF4F5Results(
                    (new EnergyBalanceService())->unavailableResult(),
                    (new EnergyCostService())->historicalResult()
                );

                $insights = $engine->evaluate($evidence, self::now());
                Assert::same(1, count($insights));

                $insight = $insights[0];
                Assert::same('DATA_LIMITATION', $insight->type());
                Assert::same(InsightSignificance::LOW, $insight->significance());
                Assert::same(InsightConfidence::INSUFFICIENT, $insight->confidence());
                Assert::same(null, $insight->dataNature());
                Assert::same('UNAVAILABLE', $insight->dataStatus());
                Assert::same(InsightLifecycle::DETECTED, $insight->status());
                Assert::same(null, $insight->affectedPeriod());
                Assert::true(count($insight->toArray()['limitations']) >= 2);
            }),

            TestResult::check('Engine deduplicates candidates by household, type and period, deterministically', function (): void {
                $duplicate = new class implements InsightDetector {
                    public function detect(InsightEvidenceInput $evidence): array
                    {
                        $build = static fn (): DetectedCandidate => new DetectedCandidate(
                            type: 'CONSUMPTION_INCREASE',
                            title: 'Household consumption increased',
                            summary: 'Summary.',
                            affectedPeriod: ['start' => '2026-09-02', 'end' => '2026-09-02', 'resolution' => 'DAY'],
                            supportingFacts: ['Fact.'],
                            limitations: [],
                            evidenceSummary: 'Evidence.',
                            explanation: 'Explanation.',
                            explanationSource: 'TEST_DETECTOR_V1',
                            evidenceDomains: ['consumption'],
                            natures: ['ACTUAL'],
                            statuses: ['FRESH'],
                            qualities: [],
                            detectors: ['TEST_DETECTOR_V1'],
                            significanceHint: 'HIGH',
                            comparisonCount: 2
                        );

                        return [$build(), $build()];
                    }
                };

                $engine = new InsightEngine(detectors: [$duplicate]);
                $evidence = InsightEvidenceInput::fromArray([
                    'household_id' => 'hh-dedup',
                    'consumption' => [
                        self::periodRecord(4.0, '2026-09-01'),
                        self::periodRecord(6.0, '2026-09-02'),
                    ],
                ]);

                $insights = $engine->evaluate($evidence, self::now());
                Assert::same(1, count($insights));
                Assert::same('CONSUMPTION_INCREASE', $insights[0]->type());
            }),

            TestResult::check('Significance model applies honesty ceilings, never upgrades', function (): void {
                $model = new SignificanceModel();

                $simulated = new DetectedCandidate(
                    type: 'CONSUMPTION_INCREASE',
                    title: 'Household consumption increased',
                    summary: 'Summary.',
                    affectedPeriod: ['start' => '2026-09-02', 'end' => '2026-09-02', 'resolution' => 'DAY'],
                    supportingFacts: [],
                    limitations: [],
                    evidenceSummary: null,
                    explanation: 'Explanation.',
                    explanationSource: 'TEST_DETECTOR_V1',
                    evidenceDomains: ['consumption'],
                    natures: ['SIMULATED'],
                    statuses: ['FRESH'],
                    qualities: [],
                    detectors: ['TEST_DETECTOR_V1'],
                    significanceHint: 'HIGH'
                );
                Assert::same(InsightSignificance::MODERATE, $model->assess($simulated, InsightConfidence::HIGH));

                Assert::same(InsightSignificance::LOW, $model->assess($simulated, InsightConfidence::INSUFFICIENT));
            }),

            TestResult::check('Confidence model is independent, breadth-based and capped by degradation', function (): void {
                $model = new ConfidenceModel();

                $twoPeriod = new DetectedCandidate(
                    type: 'CONSUMPTION_INCREASE',
                    title: 'Household consumption increased',
                    summary: 'Summary.',
                    affectedPeriod: ['start' => '2026-09-02', 'end' => '2026-09-02', 'resolution' => 'DAY'],
                    supportingFacts: [],
                    limitations: [],
                    evidenceSummary: null,
                    explanation: 'Explanation.',
                    explanationSource: 'TEST_DETECTOR_V1',
                    evidenceDomains: ['consumption'],
                    natures: ['ACTUAL', 'ACTUAL'],
                    statuses: ['FRESH', 'FRESH'],
                    qualities: ['GOOD', 'GOOD'],
                    detectors: ['TEST_DETECTOR_V1'],
                    significanceHint: 'HIGH',
                    comparisonCount: 2
                );
                $evidence = InsightEvidenceInput::fromArray([
                    'consumption' => [
                        self::periodRecord(4.0, '2026-09-01'),
                        self::periodRecord(6.0, '2026-09-02'),
                    ],
                ]);
                Assert::same(InsightConfidence::LOW, $model->assess($twoPeriod, $evidence));

                $simulated = new DetectedCandidate(
                    type: 'CONSUMPTION_INCREASE',
                    title: 'Household consumption increased',
                    summary: 'Summary.',
                    affectedPeriod: ['start' => '2026-09-02', 'end' => '2026-09-02', 'resolution' => 'DAY'],
                    supportingFacts: [],
                    limitations: [],
                    evidenceSummary: null,
                    explanation: 'Explanation.',
                    explanationSource: 'TEST_DETECTOR_V1',
                    evidenceDomains: ['consumption'],
                    natures: ['SIMULATED', 'SIMULATED'],
                    statuses: ['FRESH', 'FRESH'],
                    qualities: ['GOOD', 'GOOD'],
                    detectors: ['TEST_DETECTOR_V1'],
                    significanceHint: 'HIGH',
                    comparisonCount: 2
                );
                Assert::same(InsightConfidence::LOW, $model->assess($simulated, $evidence));

                $emptyEvidence = InsightEvidenceInput::fromArray([]);
                Assert::same(InsightConfidence::INSUFFICIENT, $model->assess($twoPeriod, $emptyEvidence));
            }),

            TestResult::check('Status ceilings: STALE/DEGRADED cap at MODERATE, UNAVAILABLE/ERROR at INSUFFICIENT', function (): void {
                $model = new ConfidenceModel();

                // Depth-3 ACTUAL/GOOD single-domain pattern would reach HIGH
                // on breadth/depth alone; only the status cap can lower it.
                $build = static fn (string $status): DetectedCandidate => new DetectedCandidate(
                    type: 'RECURRING_SOLAR_PATTERN',
                    title: 'Recurring stable solar pattern',
                    summary: 'Summary.',
                    affectedPeriod: ['start' => '2026-09-01', 'end' => '2026-09-03', 'resolution' => 'DAY'],
                    supportingFacts: [],
                    limitations: [],
                    evidenceSummary: null,
                    explanation: 'Explanation.',
                    explanationSource: 'TEST_DETECTOR_V1',
                    evidenceDomains: ['solar'],
                    natures: ['ACTUAL', 'ACTUAL', 'ACTUAL'],
                    statuses: [$status, $status, $status],
                    qualities: ['GOOD', 'GOOD', 'GOOD'],
                    detectors: ['TEST_DETECTOR_V1'],
                    significanceHint: 'MODERATE',
                    comparisonCount: 3
                );

                $freshEvidence = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(5.0, '2026-09-01'),
                        self::periodRecord(5.0, '2026-09-02'),
                        self::periodRecord(5.0, '2026-09-03'),
                    ],
                ]);

                // Control: fresh, good, actual evidence reaches HIGH.
                Assert::same(InsightConfidence::HIGH, $model->assess($build('FRESH'), $freshEvidence));

                Assert::same(InsightConfidence::MODERATE, $model->assess($build('STALE'), $freshEvidence));
                Assert::same(InsightConfidence::MODERATE, $model->assess($build('DEGRADED'), $freshEvidence));
                Assert::same(InsightConfidence::INSUFFICIENT, $model->assess($build('UNAVAILABLE'), $freshEvidence));
                Assert::same(InsightConfidence::INSUFFICIENT, $model->assess($build('ERROR'), $freshEvidence));
            }),

            TestResult::check('POOR-quality quality ceiling applies directly (VERY_LOW)', function (): void {
                $model = new ConfidenceModel();

                $poor = new DetectedCandidate(
                    type: 'RECURRING_SOLAR_PATTERN',
                    title: 'Recurring stable solar pattern',
                    summary: 'Summary.',
                    affectedPeriod: ['start' => '2026-09-01', 'end' => '2026-09-03', 'resolution' => 'DAY'],
                    supportingFacts: [],
                    limitations: [],
                    evidenceSummary: null,
                    explanation: 'Explanation.',
                    explanationSource: 'TEST_DETECTOR_V1',
                    evidenceDomains: ['solar'],
                    natures: ['ACTUAL', 'ACTUAL', 'ACTUAL'],
                    statuses: ['FRESH', 'FRESH', 'FRESH'],
                    qualities: ['POOR', 'POOR', 'POOR'],
                    detectors: ['TEST_DETECTOR_V1'],
                    significanceHint: 'MODERATE',
                    comparisonCount: 3
                );

                $evidence = InsightEvidenceInput::fromArray([
                    'solar' => [
                        self::periodRecord(5.0, '2026-09-01', 'ACTUAL', 'FRESH', 'POOR'),
                        self::periodRecord(5.0, '2026-09-02', 'ACTUAL', 'FRESH', 'POOR'),
                        self::periodRecord(5.0, '2026-09-03', 'ACTUAL', 'FRESH', 'POOR'),
                    ],
                ]);

                Assert::same(InsightConfidence::VERY_LOW, $model->assess($poor, $evidence));
            }),

            TestResult::check('F4 result-level quality reaches the confidence ceiling end to end', function (): void {
                // Two real F4 classify results with POOR input quality; the
                // F4 result carries quality POOR (worst-of inputs) and the
                // F6 evidence layer must preserve it.
                $evidence = InsightEvidenceInput::fromArray([
                    'balance' => [
                        self::f4BalancePayload(2.0, 0.0, '2026-09-01T12:00:00', 'POOR'),
                        self::f4BalancePayload(2.0, 0.0, '2026-09-01T13:00:00', 'POOR'),
                    ],
                ]);

                $balanceResult = $evidence->balanceResults()[0]['result'];
                Assert::same('CLASSIFIED', $balanceResult['state']);
                Assert::same('POTENTIAL_SURPLUS', $balanceResult['classification']);
                Assert::same('POOR', $balanceResult['quality']);

                $engine = new InsightEngine();
                $insights = $engine->evaluate($evidence, self::now());
                Assert::same(1, count($insights));
                Assert::same('RECURRING_POTENTIAL_SURPLUS', $insights[0]->type());

                // Without the quality fidelity fix, this evidence (ACTUAL,
                // FRESH, depth 2) would look healthy; POOR must force the
                // VERY_LOW ceiling and persist on the insight.
                Assert::same(InsightConfidence::VERY_LOW, $insights[0]->confidence());
                Assert::same('POOR', $insights[0]->quality());
                Assert::same('ACTUAL', $insights[0]->dataNature());
            }),

            TestResult::check('Lifecycle ladder is deterministic and ONGOING is an alias only', function (): void {
                Assert::same('VALIDATED', InsightLifecycle::nextOnObservation('DETECTED', false));
                Assert::same('ACTIVE', InsightLifecycle::nextOnObservation('VALIDATED', false));
                Assert::same('ACTIVE', InsightLifecycle::nextOnObservation('ACTIVE', false));
                Assert::same('UPDATED', InsightLifecycle::nextOnObservation('ACTIVE', true));
                Assert::same('DETECTED', InsightLifecycle::nextOnObservation('DETECTED', true));

                Assert::same('UPDATED', InsightLifecycle::normalize('ONGOING'));
                Assert::same('RESOLVED', InsightLifecycle::normalize('RESOLVED'));
                Assert::same(null, InsightLifecycle::normalize('MAGIC'));
            }),

            TestResult::check('Re-observation merges lifecycle history with refreshed evidence', function (): void {
                $candidate = new DetectedCandidate(
                    type: 'CONSUMPTION_INCREASE',
                    title: 'Household consumption increased',
                    summary: 'Summary.',
                    affectedPeriod: ['start' => '2026-09-02', 'end' => '2026-09-02', 'resolution' => 'DAY'],
                    supportingFacts: ['Fact one.'],
                    limitations: [],
                    evidenceSummary: null,
                    explanation: 'Explanation.',
                    explanationSource: 'TEST_DETECTOR_V1',
                    evidenceDomains: ['consumption'],
                    natures: ['ACTUAL'],
                    statuses: ['FRESH'],
                    qualities: [],
                    detectors: ['TEST_DETECTOR_V1'],
                    significanceHint: 'MODERATE'
                );

                $original = Insight::detected($candidate, InsightSignificance::MODERATE, InsightConfidence::LOW, self::now(), 'hh-merge');
                $later = self::now()->modify('+1 hour');

                $sameEvidence = Insight::detected($candidate, InsightSignificance::MODERATE, InsightConfidence::LOW, $later, 'hh-merge');
                $merged = $original->withObservationMerged($sameEvidence, InsightLifecycle::nextOnObservation($original->status(), false), $later);

                Assert::same(2, $merged->observationCount());
                Assert::same('VALIDATED', $merged->status());
                Assert::same($original->toArray()['first_detected_at'], $merged->toArray()['first_detected_at']);
                Assert::same($original->evidenceFingerprint(), $merged->evidenceFingerprint());

                $changed = Insight::detected(
                    new DetectedCandidate(
                        type: 'CONSUMPTION_INCREASE',
                        title: 'Household consumption increased',
                        summary: 'Changed summary.',
                        affectedPeriod: ['start' => '2026-09-02', 'end' => '2026-09-02', 'resolution' => 'DAY'],
                        supportingFacts: ['Fact one.', 'Fact two.'],
                        limitations: [],
                        evidenceSummary: null,
                        explanation: 'Explanation.',
                        explanationSource: 'TEST_DETECTOR_V1',
                        evidenceDomains: ['consumption'],
                        natures: ['ACTUAL'],
                        statuses: ['FRESH'],
                        qualities: [],
                        detectors: ['TEST_DETECTOR_V1'],
                        significanceHint: 'MODERATE'
                    ),
                    InsightSignificance::MODERATE,
                    InsightConfidence::LOW,
                    $later,
                    'hh-merge'
                );

                Assert::true($original->evidenceFingerprint() !== $changed->evidenceFingerprint());
            }),

            TestResult::check('Insight construction validates semantic levels', function (): void {
                $candidate = new DetectedCandidate(
                    type: 'CONSUMPTION_INCREASE',
                    title: 'Household consumption increased',
                    summary: 'Summary.',
                    affectedPeriod: ['start' => '2026-09-02', 'end' => '2026-09-02', 'resolution' => 'DAY'],
                    supportingFacts: [],
                    limitations: [],
                    evidenceSummary: null,
                    explanation: 'Explanation.',
                    explanationSource: 'TEST_DETECTOR_V1',
                    evidenceDomains: ['consumption'],
                    natures: ['ACTUAL'],
                    statuses: ['FRESH'],
                    qualities: [],
                    detectors: ['TEST_DETECTOR_V1']
                );

                Assert::throws(\InvalidArgumentException::class, static function () use ($candidate): void {
                    Insight::detected($candidate, 'MAGIC', InsightConfidence::LOW, self::now());
                });

                Assert::throws(\InvalidArgumentException::class, static function (): void {
                    new DetectedCandidate(
                        type: 'NOT_IN_TAXONOMY',
                        title: 'T',
                        summary: 'S',
                        affectedPeriod: null,
                        supportingFacts: [],
                        limitations: [],
                        evidenceSummary: null,
                        explanation: 'E',
                        explanationSource: 'X_V1',
                        evidenceDomains: ['consumption'],
                        natures: [],
                        statuses: [],
                        qualities: [],
                        detectors: ['X_V1']
                    );
                });
            }),

            TestResult::check('Evidence semantics propagate the worst value and never upgrade', function (): void {
                Assert::same('SIMULATED', EvidenceSemantics::worstNature(['ACTUAL', 'ESTIMATED', 'SIMULATED']));
                Assert::same(null, EvidenceSemantics::worstNature([null, null]));
                Assert::same('UNAVAILABLE', EvidenceSemantics::worstStatus(['FRESH', 'UNAVAILABLE']));
                Assert::same('UNAVAILABLE', EvidenceSemantics::worstStatus([null], true));
                Assert::same(null, EvidenceSemantics::worstStatus([null]));
                Assert::same('POOR', EvidenceSemantics::worstQuality(['VERIFIED', 'POOR', null]));
                Assert::same(null, EvidenceSemantics::worstQuality([]));
            }),
        ];
    }
}
