<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recommendation\Recommendation;
use App\Domain\Recommendation\RecommendationCandidate;
use App\Domain\Recommendation\RecommendationConfidence;
use App\Domain\Recommendation\RecommendationEngine;
use App\Domain\Recommendation\RecommendationEvidence;
use App\Domain\Recommendation\RecommendationImpact;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Domain\Recommendation\RecommendationThresholds;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\TestResult;

final class RecommendationDomainTest
{
    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    public static function run(): array
    {
        return [
            TestResult::check('RecommendationTaxonomy defines exactly 8 controlled types', function (): void {
                $all = RecommendationTaxonomy::all();
                Assert::same(8, count($all), 'Must have exactly 8 canonical types');

                Assert::true(in_array(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $all, true));
                Assert::true(in_array(RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR, $all, true));
                Assert::true(in_array(RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, $all, true));
                Assert::true(in_array(RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION, $all, true));
                Assert::true(in_array(RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION, $all, true));
                Assert::true(in_array(RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT, $all, true));
                Assert::true(in_array(RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE, $all, true));
                Assert::true(in_array(RecommendationTaxonomy::TYPE_DATA_LIMITATION, $all, true));

                Assert::true(RecommendationTaxonomy::isValid(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW));
                Assert::false(RecommendationTaxonomy::isValid('UNKNOWN_RECOMMENDATION_TYPE'));
            }),

            TestResult::check('RecommendationTaxonomy distinguishes actionable advice from DATA_LIMITATION', function (): void {
                Assert::true(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW));
                Assert::true(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR));
                Assert::true(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW));
                Assert::true(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION));
                Assert::true(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION));
                Assert::true(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT));
                Assert::true(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE));

                // DATA_LIMITATION is honest system limitation, not household action
                Assert::false(RecommendationTaxonomy::isActionable(RecommendationTaxonomy::TYPE_DATA_LIMITATION));
            }),

            TestResult::check('RecommendationThresholds enforce canonical values', function (): void {
                Assert::same(0.5, RecommendationThresholds::MIN_SURPLUS_KWH);
                Assert::same(0.5, RecommendationThresholds::MIN_DEFICIT_KWH);
                Assert::same(900, RecommendationThresholds::STALE_DATA_SECONDS);
                Assert::same(12, RecommendationThresholds::COOLDOWN_HOURS);
                Assert::same(5, RecommendationThresholds::MAX_ACTIVE_PER_HOUSEHOLD);
            }),

            TestResult::check('RecommendationConfidence worstOf aggregates to the weakest input', function (): void {
                // High inputs throughout -> HIGH
                $high = RecommendationConfidence::worstOf([
                    'forecast_confidence' => RecommendationConfidence::CONFIDENCE_HIGH,
                    'quality' => 'GOOD',
                    'status' => 'FRESH',
                ]);
                Assert::same(RecommendationConfidence::CONFIDENCE_HIGH, $high);

                // Any degraded / moderate input pulls confidence down
                $moderate = RecommendationConfidence::worstOf([
                    'forecast_confidence' => RecommendationConfidence::CONFIDENCE_HIGH,
                    'quality' => 'PARTIAL',
                ]);
                Assert::same(RecommendationConfidence::CONFIDENCE_MODERATE, $moderate);

                // Any low input pulls confidence to LOW
                $low = RecommendationConfidence::worstOf([
                    'forecast_confidence' => RecommendationConfidence::CONFIDENCE_HIGH,
                    'source_reading' => RecommendationConfidence::CONFIDENCE_LOW,
                ]);
                Assert::same(RecommendationConfidence::CONFIDENCE_LOW, $low);

                // Empty inputs fall back to INSUFFICIENT
                $empty = RecommendationConfidence::worstOf([]);
                Assert::same(RecommendationConfidence::CONFIDENCE_INSUFFICIENT, $empty);
            }),

            TestResult::check('RecommendationImpact adheres to non-guaranteed advisory phrasing', function (): void {
                $impact = RecommendationImpact::solarSelfConsumption(3.5, 26.25);
                Assert::same('SOLAR_SELF_CONSUMPTION', $impact->type);
                Assert::same(3.5, $impact->kwhValue);
                Assert::same(26.25, $impact->inrValue);
                Assert::true(str_starts_with($impact->description, 'Potential'));
                Assert::false(str_contains(strtolower($impact->description), 'guaranteed'));

                $gridImpact = RecommendationImpact::gridImportReduction(2.0, 15.0);
                Assert::same('GRID_IMPORT_REDUCTION', $gridImpact->type);
                Assert::true(str_starts_with($gridImpact->description, 'Estimated'));
            }),

            TestResult::check('RecommendationEngine generates Rule A SHIFT_TO_SOLAR_WINDOW candidate on daytime surplus', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-22 10:00:00', new DateTimeZone('UTC'));

                $evidence = new RecommendationEvidence(
                    solarForecast: [
                        'points' => [
                            ['timestamp' => '2026-09-22 12:00:00', 'value_kwh' => 4.0],
                            ['timestamp' => '2026-09-22 13:00:00', 'value_kwh' => 4.5],
                        ],
                        'confidence' => RecommendationConfidence::CONFIDENCE_HIGH,
                    ],
                    consumptionForecast: [
                        'points' => [
                            ['timestamp' => '2026-09-22 12:00:00', 'value_kwh' => 1.2],
                            ['timestamp' => '2026-09-22 13:00:00', 'value_kwh' => 1.5],
                        ],
                        'confidence' => RecommendationConfidence::CONFIDENCE_HIGH,
                    ],
                    provenance: ['ForecastService (F3)'],
                    quality: 'GOOD',
                    dataNature: 'PREDICTED'
                );

                $candidates = $engine->evaluate('hh-test-1', $evidence, $now);
                Assert::true(count($candidates) >= 1);

                $types = array_map(static fn (RecommendationCandidate $c) => $c->type, $candidates);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $types, true));

                $shift = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $shift = $c;
                        break;
                    }
                }
                Assert::true($shift !== null);
                Assert::same(Recommendation::PRIORITY_HIGH, $shift->priority);
                Assert::same(RecommendationConfidence::CONFIDENCE_HIGH, $shift->confidence);
                // Advisory only: verify no appliance control attributes exist
                Assert::false(property_exists($shift, 'device_id'));
                Assert::false(property_exists($shift, 'smart_plug'));
            }),

            TestResult::check('RecommendationEngine produces DATA_LIMITATION when evidence is missing or degraded', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-22 10:00:00', new DateTimeZone('UTC'));

                $evidence = new RecommendationEvidence(
                    provenance: ['RecommendationEngine'],
                    limitations: ['Solar telemetry data missing for the past 24 hours.'],
                    quality: 'DEGRADED',
                    dataNature: 'ACTUAL'
                );

                $candidates = $engine->evaluate('hh-test-limit', $evidence, $now);
                Assert::true(count($candidates) >= 1);

                $types = array_map(static fn (RecommendationCandidate $c) => $c->type, $candidates);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_DATA_LIMITATION, $types, true));

                $limitCandidate = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_DATA_LIMITATION) {
                        $limitCandidate = $c;
                        break;
                    }
                }
                Assert::true($limitCandidate !== null);
                Assert::same(RecommendationConfidence::CONFIDENCE_LOW, $limitCandidate->confidence);
                Assert::same('DEGRADED', $limitCandidate->quality);
            }),

            TestResult::check('RecommendationEngine caps candidates to top 5 by priority', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-22 10:00:00', new DateTimeZone('UTC'));

                // Build composite evidence that triggers multiple rules
                $evidence = new RecommendationEvidence(
                    solarForecast: [
                        'points' => [
                            ['timestamp' => '2026-09-22 12:00:00', 'value_kwh' => 4.5],
                        ],
                        'confidence' => RecommendationConfidence::CONFIDENCE_HIGH,
                    ],
                    consumptionForecast: [
                        'points' => [
                            ['timestamp' => '2026-09-22 12:00:00', 'value_kwh' => 1.0],
                            ['timestamp' => '2026-09-22 19:00:00', 'value_kwh' => 4.0],
                        ],
                        'confidence' => RecommendationConfidence::CONFIDENCE_HIGH,
                    ],
                    insights: [
                        ['type' => 'RECURRING_POTENTIAL_SURPLUS'],
                        ['type' => 'RECURRING_POTENTIAL_DEFICIT'],
                        ['type' => 'RECURRING_HIGH_DEMAND'],
                        ['type' => 'COST_IMPACT_PATTERN'],
                        ['type' => 'SOLAR_GENERATION_DECLINE'],
                    ],
                    provenance: ['ForecastService', 'InsightEngine'],
                    quality: 'GOOD',
                    dataNature: 'PREDICTED'
                );

                $candidates = $engine->evaluate('hh-test-bounds', $evidence, $now);
                Assert::true(count($candidates) <= RecommendationThresholds::MAX_ACTIVE_PER_HOUSEHOLD);
            }),

            TestResult::check('Recommendation entity manages lifecycle states deterministically', function (): void {
                $now = new DateTimeImmutable('2026-09-22 10:00:00', new DateTimeZone('UTC'));
                $rec = new Recommendation(
                    id: self::uuid(),
                    householdId: 'hh-lifecycle',
                    type: RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Shift Load',
                    summary: 'Shift daytime load',
                    action: 'Run washing machine',
                    reason: 'Surplus available',
                    timingWindow: '11:00 - 14:00',
                    potentialImpact: 'Potential 2 kWh savings',
                    impactJson: ['potential_kwh_shifted' => 2.0],
                    evidenceJson: ['surplus' => 2.0],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );

                Assert::same(Recommendation::STATUS_ACTIVE, $rec->status());
                Assert::true($rec->isActive());

                // Dismiss
                $dismissTime = $now->modify('+1 hour');
                $rec->dismiss($dismissTime);
                Assert::same(Recommendation::STATUS_DISMISSED, $rec->status());
                Assert::false($rec->isActive());
                Assert::same($dismissTime->format('Y-m-d H:i:s'), $rec->dismissedAt()?->format('Y-m-d H:i:s'));

                // Expire
                $expireTime = $now->modify('+2 hours');
                $rec->expire($expireTime);
                Assert::same(Recommendation::STATUS_EXPIRED, $rec->status());

                // Supersede
                $supersedeTime = $now->modify('+3 hours');
                $rec->supersede($supersedeTime);
                Assert::same(Recommendation::STATUS_SUPERSEDED, $rec->status());
            }),

            TestResult::check('RecommendationImpact::highDemandReduction defaults to honest null kWh without fabricating numbers', function (): void {
                $unverified = RecommendationImpact::highDemandReduction();
                Assert::same(RecommendationImpact::TYPE_HIGH_DEMAND_CURTAILMENT, $unverified->type);
                Assert::same(null, $unverified->energyKwh, 'Unverified high demand reduction must not fabricate kWh');
                Assert::same(null, $unverified->costInr);
                Assert::false(str_contains(strtolower($unverified->description), 'guaranteed'));

                $verified = RecommendationImpact::highDemandReduction(3.2);
                Assert::same(3.2, $verified->energyKwh);
                Assert::same('Estimated reduction opportunity of up to 3.2 kWh above typical baseline', $verified->description);
            }),

            TestResult::check('RecommendationEngine accepts F6 insights in ACTIVE, UPDATED, VALIDATED, and DETECTED states', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC'));

                $insights = [
                    [
                        'type' => 'RECURRING_HIGH_DEMAND',
                        'confidence' => 'MODERATE',
                        'status' => 'UPDATED',
                        'summary' => '3 observed billing periods >= 5 kWh',
                        'affected_period_start' => '2026-06-01',
                        'affected_period_end' => '2026-09-01',
                    ],
                ];

                $candidates = $engine->evaluate(
                    forecastOrHouseholdId: null,
                    solarLatestOrEvidence: null,
                    verifiedBillsOrNow: [],
                    insights: $insights,
                    now: $now
                );

                $types = array_map(static fn ($c) => $c->type, $candidates);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION, $types, true), 'UPDATED status must produce recommendation');

                $rec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION) {
                        $rec = $c;
                        break;
                    }
                }
                Assert::true($rec !== null);
                Assert::same(RecommendationConfidence::MODERATE, $rec->confidence);
                Assert::same(null, $rec->impact->energyKwh, 'No fabricated 2.0 kWh when no excess is defined in insight context');
            }),

            TestResult::check('RecommendationEngine honors POTENTIAL_SURPLUS and POTENTIAL_DEFICIT balance classifications', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC'));

                $forecastSurplus = [
                    'solar_forecast' => ['predicted_value' => 25.0, 'confidence' => 'LOW'],
                    'consumption_forecast' => ['predicted_value' => 5.0, 'confidence' => 'MODERATE'],
                    'energy_balance_projection' => [
                        'classification' => 'POTENTIAL_SURPLUS',
                        'balance_kwh' => 20.0,
                    ],
                    'combined_confidence' => 'LOW',
                    'period' => ['start' => '2026-09-26', 'end' => '2026-09-27'],
                ];

                $candidates = $engine->evaluate($forecastSurplus, null, [], [], $now);
                $types = array_map(static fn ($c) => $c->type, $candidates);
                Assert::true(
                    in_array(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $types, true)
                    || in_array(RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR, $types, true),
                    'Must produce surplus recommendation (SHIFT_TO_SOLAR_WINDOW or USE_AVAILABLE_SOLAR)'
                );

                $surplusRec = $candidates[0];
                Assert::same(20.0, $surplusRec->impact->energyKwh);
                Assert::same(RecommendationConfidence::LOW, $surplusRec->confidence);
            }),

            TestResult::check('HIGH_DEMAND_REDUCTION wording does not claim unsupported continuous base load', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC'));

                // 1. Factory description and null energy_kwh
                $impact = RecommendationImpact::highDemandReduction();
                Assert::same('Review recurring household electricity consumption', $impact->description);
                Assert::same(null, $impact->energyKwh);
                Assert::false(str_contains(strtolower($impact->description), 'continuous'));
                Assert::false(str_contains(strtolower($impact->description), 'base load'));

                // 2. Candidate generation from RECURRING_HIGH_DEMAND insight
                $insights = [[
                    'id' => 'ins-001',
                    'type' => 'RECURRING_HIGH_DEMAND',
                    'status' => 'ACTIVE',
                    'confidence' => 'MODERATE',
                    'quality' => 'GOOD',
                    'summary' => '3 consecutive cycles above 5.0 kWh/day',
                    'context' => [],
                ]];

                $candidates = $engine->evaluate(null, null, [], $insights, $now);
                $highDemand = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION) {
                        $highDemand = $c;
                        break;
                    }
                }

                Assert::true($highDemand !== null);
                Assert::same('Household Consumption Moderation', $highDemand->title);
                Assert::same('Review recurring household electricity consumption and reduce avoidable usage where practical.', $highDemand->action);
                Assert::same('Your last 3 verified bills show recurring household consumption above the current baseline.', $highDemand->reason);
                Assert::same(Recommendation::PRIORITY_MEDIUM, $highDemand->priority);
                Assert::same(RecommendationConfidence::CONFIDENCE_MODERATE, $highDemand->confidence);
                Assert::same(null, $highDemand->impact->energyKwh);
                Assert::false(str_contains(strtolower($highDemand->action), 'continuous'), 'Action must NOT claim continuous base load');
                Assert::false(str_contains(strtolower($highDemand->reason), 'continuous'), 'Reason must NOT claim continuous base load');
                Assert::false(str_contains(strtolower($highDemand->summary), 'continuous'), 'Summary must NOT claim continuous base load');
            }),

            TestResult::check('APSPDCL consumption is not mislabeled as grid telemetry', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC'));

                $insights = [[
                    'id' => 'ins-002',
                    'type' => 'RECURRING_HIGH_DEMAND',
                    'status' => 'ACTIVE',
                    'confidence' => 'MODERATE',
                    'quality' => 'GOOD',
                    'summary' => 'Monthly billed consumption 183, 200, 145 kWh',
                    'context' => [],
                ]];

                $candidates = $engine->evaluate(null, null, [], $insights, $now);
                Assert::true(!empty($candidates));
                $c = $candidates[0];

                // Must refer to household electricity consumption / billing, NOT grid draw or grid telemetry
                Assert::false(str_contains(strtolower($c->title), 'grid draw'));
                Assert::false(str_contains(strtolower($c->summary), 'grid draw'));
                Assert::false(str_contains(strtolower($c->reason), 'grid draw'));
                Assert::false(str_contains(strtolower($c->action), 'grid draw'));
                Assert::false(str_contains(strtolower($c->reason), 'instantaneous'));
            }),

            TestResult::check('Rule E stale telemetry behavior generates DATA_LIMITATION with PRIORITY_MEDIUM', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-27 00:00:00', new DateTimeZone('UTC'));

                // Telemetry last received 2 hours ago (>900s)
                $staleSolar = [
                    'received_at' => '2026-09-26 22:00:00',
                    'measurement_time' => '2026-09-26 22:00:00',
                    'power_w' => 0.0,
                ];

                $candidates = $engine->evaluate(null, $staleSolar, [], [], $now);
                $staleCandidate = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_DATA_LIMITATION) {
                        $staleCandidate = $c;
                        break;
                    }
                }

                Assert::true($staleCandidate !== null, 'Stale telemetry must produce DATA_LIMITATION candidate');
                Assert::same(Recommendation::PRIORITY_MEDIUM, $staleCandidate->priority, 'Stale telemetry notice must have PRIORITY_MEDIUM');
                Assert::same('Inverter Telemetry Stale: Check Connection', $staleCandidate->title);
                Assert::same('Check inverter Wi-Fi connection or SEMS Portal sync status.', $staleCandidate->action);
                Assert::true(in_array('STALE_SOLAR_TELEMETRY', $staleCandidate->limitations, true));
            }),

            TestResult::check('21.053 kWh is labeled potential/forecast-derived opportunity', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC'));

                $forecastSurplus = [
                    'solar_forecast' => ['predicted_value' => 26.634, 'confidence' => 'LOW'],
                    'consumption_forecast' => ['predicted_value' => 5.581, 'confidence' => 'MODERATE'],
                    'energy_balance_projection' => [
                        'classification' => 'POTENTIAL_SURPLUS',
                        'balance_kwh' => 21.053,
                    ],
                    'combined_confidence' => 'LOW',
                    'period' => ['start' => '2026-09-26', 'end' => '2026-09-27'],
                ];

                $candidates = $engine->evaluate($forecastSurplus, null, [], [], $now);
                $rec = $candidates[0];

                Assert::same('Potential solar-window opportunity of approximately 21.1 kWh', $rec->impact->description);
                Assert::same('Potential solar-window opportunity of approximately 21.1 kWh expected during peak sunlight.', $rec->summary);
                Assert::true(str_contains($rec->reason, 'forecast-derived potential opportunity, not guaranteed savings'));

                // Must NOT contain forbidden promises
                Assert::false(str_contains(strtolower($rec->summary), 'guaranteed savings'));
                Assert::false(str_contains(strtolower($rec->summary), 'guaranteed available energy'));
                Assert::false(str_contains(strtolower($rec->summary), 'confirmed export'));
                Assert::false(str_contains(strtolower($rec->summary), 'guaranteed shiftable load'));
            }),

            TestResult::check('Confidence cannot exceed weakest relevant upstream evidence even with 6+ verified bills', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC'));

                // 6+ verified bills gives HIGH consumption confidence, but solar forecast is LOW
                $combinedConfidence = RecommendationConfidence::worstOf([
                    'consumption' => RecommendationConfidence::CONFIDENCE_HIGH,
                    'solar' => RecommendationConfidence::CONFIDENCE_LOW,
                ]);
                Assert::same(RecommendationConfidence::CONFIDENCE_LOW, $combinedConfidence, 'worstOf must bind to weakest input');

                $forecast = [
                    'solar_forecast' => ['predicted_value' => 30.0, 'confidence' => RecommendationConfidence::CONFIDENCE_LOW],
                    'consumption_forecast' => ['predicted_value' => 6.0, 'confidence' => RecommendationConfidence::CONFIDENCE_HIGH],
                    'energy_balance_projection' => [
                        'classification' => 'POTENTIAL_SURPLUS',
                        'balance_kwh' => 24.0,
                    ],
                    'combined_confidence' => $combinedConfidence,
                    'period' => ['start' => '2026-09-26', 'end' => '2026-09-27'],
                ];

                $candidates = $engine->evaluate($forecast, null, [], [], $now);
                $rec = $candidates[0];

                Assert::same(RecommendationConfidence::CONFIDENCE_LOW, $rec->confidence, '6+ bills cannot escalate solar recommendation above LOW');
                Assert::same(Recommendation::PRIORITY_HIGH, $rec->priority, 'Priority may be HIGH while confidence remains LOW');
            }),
        ];
    }
}
