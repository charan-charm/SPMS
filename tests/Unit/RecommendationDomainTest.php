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
                Assert::same('Moderate household electricity consumption', $highDemand->title);
                Assert::same('Review discretionary household electricity consumption and reduce avoidable usage where practical.', $highDemand->action);
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
                Assert::same('Solar data is out of date', $staleCandidate->title);
                Assert::same('Check inverter Wi-Fi connection or SEMS Portal sync status to resume real-time recommendations.', $staleCandidate->action);
                Assert::true(in_array('STALE_SOLAR_TELEMETRY', $staleCandidate->limitations, true));
                Assert::true(str_contains($staleCandidate->reason, '03:30 IST'), 'Stale timestamp must be formatted in local IST time');
                Assert::true(str_contains($staleCandidate->reason, 'live inverter telemetry is marked STALE'), 'Must explicitly state live telemetry is STALE');
                Assert::true(str_contains($staleCandidate->reason, 'predictive modeling'), 'Must state recommendations continue via predictive modeling');
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

            TestResult::check('Rule A dynamically derives solar surplus window from time_series in household local time (IST)', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                // Build a 24-hour time_series where solar peaks between 08:00 and 13:00 IST
                $timeSeries = [];
                for ($h = 0; $h < 24; $h++) {
                    $localHour = $h;
                    $solarKwh = 0.0;
                    $balanceKwh = -0.5;
                    // Peak between 08:00 and 13:00 IST
                    if ($localHour >= 8 && $localHour <= 13) {
                        $solarKwh = 2.5; // daily peak is 2.5 kWh
                        $balanceKwh = 2.0; // surplus >= 0.5 kWh
                    } elseif ($localHour >= 6 && $localHour <= 17) {
                        $solarKwh = 0.5; // < 68% of peak
                        $balanceKwh = 0.1;
                    }

                    $timeSeries[] = [
                        'timestamp' => sprintf('2026-10-06T%02d:00:00Z', ($h + 18) % 24),
                        'local_time' => sprintf('%02d:00', $localHour),
                        'local_hour' => $localHour,
                        'solar_forecast_kwh' => $solarKwh,
                        'consumption_forecast_kwh' => 0.5,
                        'balance_kwh' => $balanceKwh,
                        'projected_balance_kwh' => $balanceKwh,
                    ];
                }

                $forecast = [
                    'solar_forecast' => ['predicted_value' => 17.5, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 6.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'POTENTIAL_SURPLUS',
                        'balance_kwh' => 11.5,
                    ],
                    'time_series' => $timeSeries,
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                $candidates = $engine->evaluate($forecast, null, [], [], $now);
                Assert::true(!empty($candidates), 'Should generate candidates');
                $shiftRec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $shiftRec = $c;
                        break;
                    }
                }
                Assert::true($shiftRec !== null, 'Must generate SHIFT_TO_SOLAR_WINDOW candidate');
                Assert::same('08:00–13:00', $shiftRec->timingWindow);
                Assert::true(str_contains($shiftRec->reason, 'forecast-derived potential opportunity, not guaranteed savings'));
                Assert::true(str_contains($shiftRec->reason, 'estimated household consumption'));
                Assert::same('Potential solar-window opportunity of approximately 11.5 kWh', $shiftRec->impact->description);
            }),

            TestResult::check('Rule B avoid deficit window derives local evening hours from time_series in IST', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                $timeSeries = [];
                for ($h = 0; $h < 24; $h++) {
                    $localHour = $h;
                    $balanceKwh = 0.5;
                    // Deficit in evening 18:00 to 22:00 IST
                    if ($localHour >= 18 && $localHour <= 22) {
                        $balanceKwh = -1.2;
                    }

                    $timeSeries[] = [
                        'timestamp' => sprintf('2026-10-06T%02d:00:00Z', ($h + 18) % 24),
                        'local_time' => sprintf('%02d:00', $localHour),
                        'local_hour' => $localHour,
                        'solar_forecast_kwh' => 0.0,
                        'consumption_forecast_kwh' => 1.2,
                        'balance_kwh' => $balanceKwh,
                        'projected_balance_kwh' => $balanceKwh,
                    ];
                }

                $forecast = [
                    'solar_forecast' => ['predicted_value' => 5.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 12.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'POTENTIAL_DEFICIT',
                        'balance_kwh' => -7.0,
                    ],
                    'time_series' => $timeSeries,
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                $candidates = $engine->evaluate($forecast, null, [], [], $now);
                $deficitRec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW) {
                        $deficitRec = $c;
                        break;
                    }
                }
                Assert::true($deficitRec !== null, 'Must generate AVOID_DEFICIT_WINDOW candidate');
                Assert::same('18:00–22:00', $deficitRec->timingWindow);
                Assert::true(str_contains($deficitRec->reason, 'Household demand is expected to exceed solar availability'));
                Assert::same('Potential avoidance of up to 7.0 kWh grid reliance during deficit window', $deficitRec->impact->description);
            }),

            TestResult::check('Cost-aware shift: Authoritative unit rate calculates monetary estimate without fallback', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                $forecastWithTariff = [
                    'solar_forecast' => ['predicted_value' => 8.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 4.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'SURPLUS',
                        'balance_kwh' => 4.0,
                    ],
                    'energy_cost_projection' => [
                        'state' => 'CALCULATED',
                        'estimated_cost_inr' => 45.00,
                        'pricing' => ['unit_rate' => 6.20],
                    ],
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                $candidates = $engine->evaluate($forecastWithTariff, null, [], [], $now);
                $costRec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT) {
                        $costRec = $c;
                        break;
                    }
                }

                Assert::true($costRec !== null, 'Cost-aware shift candidate must be generated');
                Assert::same(24.8, $costRec->impact->costInr, 'Monetary estimate must be shiftKwh (4.0) * unitRate (6.20) = 24.80');
                Assert::true(str_contains($costRec->summary, '₹24.80'), 'Summary must contain calculated rupee amount');
                Assert::same('Shift discretionary usage to avoid peak cost', $costRec->title);
            }),

            TestResult::check('Cost-aware shift: Missing unit rate yields null costInr without arbitrary ₹7.50 fallback', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                // F5 has cost estimate but lacks pricing unit_rate
                $forecastWithoutTariff = [
                    'solar_forecast' => ['predicted_value' => 8.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 4.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'SURPLUS',
                        'balance_kwh' => 4.0,
                    ],
                    'energy_cost_projection' => [
                        'state' => 'CALCULATED',
                        'estimated_cost_inr' => 45.00,
                        // pricing unit_rate intentionally missing
                    ],
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                $candidates = $engine->evaluate($forecastWithoutTariff, null, [], [], $now);
                $costRec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT) {
                        $costRec = $c;
                        break;
                    }
                }

                Assert::true($costRec !== null, 'Cost-aware shift candidate must be generated');
                Assert::same(null, $costRec->impact->costInr, 'Must NOT invent a tariff; costInr must be null');
                Assert::false(str_contains($costRec->summary, '₹'), 'Summary must not contain any rupee amount');
                Assert::false(str_contains($costRec->impact->description, '₹'), 'Impact description must not contain any rupee amount');
                Assert::true(str_contains($costRec->summary, '4.0 kWh'), 'Summary must communicate energy shift in kWh instead');
            }),

            TestResult::check('Mutual suppression Case A: SHIFT_TO_SOLAR_WINDOW suppresses USE_AVAILABLE_SOLAR', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                // Forecast generates SHIFT_TO_SOLAR_WINDOW
                $forecast = [
                    'solar_forecast' => ['predicted_value' => 12.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 4.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'SURPLUS',
                        'balance_kwh' => 8.0,
                    ],
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                // Insight generates USE_AVAILABLE_SOLAR
                $insights = [[
                    'id' => 'ins-solar-surplus',
                    'type' => 'RECURRING_POTENTIAL_SURPLUS',
                    'status' => 'ACTIVE',
                    'confidence' => 'HIGH',
                    'quality' => 'GOOD',
                    'summary' => 'Historical midday surplus pattern',
                    'context' => [],
                ]];

                $candidates = $engine->evaluate($forecast, null, [], $insights, $now);
                $types = array_map(static fn (RecommendationCandidate $c) => $c->type, $candidates);

                Assert::true(in_array(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $types, true), 'SHIFT_TO_SOLAR_WINDOW must be present');
                Assert::false(in_array(RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR, $types, true), 'USE_AVAILABLE_SOLAR must be suppressed');
            }),

            TestResult::check('Mutual suppression Case B: EVENING_DEFICIT_PREPARATION suppresses AVOID_DEFICIT_WINDOW', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                // Forecast generates AVOID_DEFICIT_WINDOW
                $forecast = [
                    'solar_forecast' => ['predicted_value' => 2.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 9.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'DEFICIT',
                        'balance_kwh' => -7.0,
                    ],
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                // Insight generates EVENING_DEFICIT_PREPARATION
                $insights = [[
                    'id' => 'ins-deficit-prep',
                    'type' => 'DAYTIME_SURPLUS_EVENING_DEFICIT',
                    'status' => 'ACTIVE',
                    'confidence' => 'HIGH',
                    'quality' => 'GOOD',
                    'summary' => 'Diurnal mismatch pattern',
                    'context' => [],
                ]];

                $candidates = $engine->evaluate($forecast, null, [], $insights, $now);
                $types = array_map(static fn (RecommendationCandidate $c) => $c->type, $candidates);

                Assert::true(in_array(RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION, $types, true), 'EVENING_DEFICIT_PREPARATION must be present');
                Assert::false(in_array(RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, $types, true), 'AVOID_DEFICIT_WINDOW must be suppressed');
            }),

            TestResult::check('Independent recommendations coexist when addressing distinct needs', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                // Forecast generates SHIFT_TO_SOLAR_WINDOW
                $forecast = [
                    'solar_forecast' => ['predicted_value' => 10.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 4.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'SURPLUS',
                        'balance_kwh' => 6.0,
                    ],
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                // Insight generates HIGH_DEMAND_REDUCTION (distinct from solar shift)
                $insights = [[
                    'id' => 'ins-high-demand',
                    'type' => 'RECURRING_HIGH_DEMAND',
                    'status' => 'ACTIVE',
                    'confidence' => 'HIGH',
                    'quality' => 'GOOD',
                    'summary' => 'Recurring high consumption above baseline',
                    'context' => [],
                ]];

                $candidates = $engine->evaluate($forecast, null, [], $insights, $now);
                $types = array_map(static fn (RecommendationCandidate $c) => $c->type, $candidates);

                Assert::true(in_array(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $types, true), 'SHIFT_TO_SOLAR_WINDOW must coexist');
                Assert::true(in_array(RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION, $types, true), 'HIGH_DEMAND_REDUCTION must coexist');
            }),

            TestResult::check('Fallback timing ranges are clean without duplicated descriptive labels', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                // Forecast with empty time_series (forces fallback)
                $forecastSurplusNoSeries = [
                    'solar_forecast' => ['predicted_value' => 8.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 3.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'SURPLUS',
                        'balance_kwh' => 5.0,
                    ],
                    'time_series' => [], // Empty time series -> fallback timing
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                $candidates = $engine->evaluate($forecastSurplusNoSeries, null, [], [], $now);
                $shiftRec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $shiftRec = $c;
                        break;
                    }
                }

                Assert::true($shiftRec !== null);
                Assert::same('10:00–15:00', $shiftRec->timingWindow, 'Fallback surplus timing must be clean range 10:00–15:00');
                Assert::false(str_contains($shiftRec->timingWindow, 'Daytime'), 'Must not duplicate UI label Daytime');

                $forecastDeficitNoSeries = [
                    'solar_forecast' => ['predicted_value' => 2.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 8.0, 'confidence' => 'HIGH'],
                    'energy_balance_projection' => [
                        'classification' => 'DEFICIT',
                        'balance_kwh' => -6.0,
                    ],
                    'time_series' => [], // Empty time series -> fallback timing
                    'combined_confidence' => 'HIGH',
                    'period' => ['start' => '2026-10-06', 'end' => '2026-10-07'],
                ];

                $candidatesDeficit = $engine->evaluate($forecastDeficitNoSeries, null, [], [], $now);
                $deficitRec = null;
                foreach ($candidatesDeficit as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW) {
                        $deficitRec = $c;
                        break;
                    }
                }

                Assert::true($deficitRec !== null);
                Assert::same('18:00–22:00', $deficitRec->timingWindow, 'Fallback deficit timing must be clean range 18:00–22:00');
                Assert::false(str_contains($deficitRec->timingWindow, 'Evening'), 'Must not duplicate UI label Evening');
            }),

            TestResult::check('Verified bill trust boundary: Unverified bills do not feed recommendation demand', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));

                // Household has NO verified bills (only empty list passed)
                // And forecast consumption confidence is INSUFFICIENT
                $forecast = [
                    'solar_forecast' => ['predicted_value' => 10.0, 'confidence' => 'HIGH'],
                    'consumption_forecast' => ['predicted_value' => 0.0, 'confidence' => RecommendationConfidence::INSUFFICIENT],
                    'energy_balance_projection' => ['classification' => 'UNAVAILABLE', 'balance_kwh' => null],
                    'combined_confidence' => RecommendationConfidence::INSUFFICIENT,
                ];

                $candidates = $engine->evaluate($forecast, null, [], [], $now);
                $types = array_map(static fn (RecommendationCandidate $c) => $c->type, $candidates);

                Assert::true(in_array(RecommendationTaxonomy::TYPE_DATA_LIMITATION, $types, true), 'Must produce DATA_LIMITATION candidate when verified bills missing');
                Assert::false(in_array(RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, $types, true), 'Must not fabricate deficit recommendations without verified demand');
                Assert::false(in_array(RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT, $types, true), 'Must not fabricate cost shift recommendations without verified demand');
            }),

            // Section 9 & 10 — Weather evidence attached to recommendation
            TestResult::check('Weather evidence attached to actionable recommendations and enriches reasons without notification spam', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-09 11:00:00', new DateTimeZone('UTC'));

                $forecast = [
                    'household_id' => 'hh-test-weather-rec',
                    'period' => ['start' => '2026-10-09T06:00:00Z', 'end' => '2026-10-09T18:00:00Z'],
                    'combined_confidence' => RecommendationConfidence::HIGH,
                    'solar_forecast' => [
                        'predicted_value' => 12.5,
                        'confidence' => RecommendationConfidence::HIGH,
                        'data_quality' => 'GOOD',
                        'data_nature' => 'PREDICTED',
                        'provenance' => ['source' => 'SOLAR_CALIBRATED_V2'],
                    ],
                    'consumption_forecast' => [
                        'predicted_value' => 6.0,
                        'confidence' => RecommendationConfidence::HIGH,
                        'data_quality' => 'GOOD',
                        'data_nature' => 'PREDICTED',
                    ],
                    'energy_balance_projection' => [
                        'classification' => 'SURPLUS',
                        'balance_kwh' => 6.5,
                    ],
                    'energy_cost_projection' => [
                        'state' => 'CALCULATED',
                        'estimated_cost_inr' => 45.0,
                        'unit_rate' => 7.0,
                    ],
                    'time_series' => [
                        ['hour' => 11, 'net_balance_kwh' => 1.5],
                        ['hour' => 12, 'net_balance_kwh' => 2.0],
                        ['hour' => 13, 'net_balance_kwh' => 1.8],
                    ],
                    'weather' => [
                        'status' => 'ACTIVE',
                        'available' => true,
                        'source_label' => 'Open-Meteo',
                        'cloud_cover_pct' => 15.0,
                        'solar_irradiance_wm2' => 580.0,
                        'temperature_c' => 31.0,
                        'precipitation_probability_pct' => 0.0,
                        'condition' => 'CLEAR',
                        'weather_code' => 0,
                        'solar_impact' => 'STRONG',
                    ],
                ];

                $candidates = $engine->evaluate($forecast, null, [], [], $now);
                Assert::true(count($candidates) > 0, 'Candidates generated');

                $shiftRec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $shiftRec = $c;
                        break;
                    }
                }

                Assert::true($shiftRec !== null, 'Rule A candidate generated');
                Assert::same('Use flexible appliances during solar hours', $shiftRec->title, 'Action-first title preserved');
                Assert::true($shiftRec->evidence->weather !== null, 'Weather evidence attached to recommendation');

                $wEv = $shiftRec->evidence->weather;
                Assert::same('Open-Meteo', $wEv['source']);
                Assert::same('ACTIVE', $wEv['status']);
                Assert::same(15.0, $wEv['cloud_cover_pct']);
                Assert::same(580.0, $wEv['irradiance_wm2']);
                Assert::same('STRONG', $wEv['solar_impact']);
                Assert::true(str_contains($wEv['explanation'], 'Clear atmospheric conditions'), 'Atmospheric explanation is present');

                // Verify provenance includes OPEN_METEO
                Assert::true(in_array('OPEN_METEO', $shiftRec->provenance, true), 'OPEN_METEO recorded in candidate provenance');

                // Verify reason includes the atmospheric explanation without changing action
                Assert::true(str_contains($shiftRec->reason, 'Clear atmospheric conditions'), 'Atmospheric reason enriches recommendation reason');
            }),

            // Section 4 & 10 — Fallback weather in recommendation
            TestResult::check('Weather fallback propagates honestly into recommendation evidence with zero fabricated values', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-09 11:00:00', new DateTimeZone('UTC'));

                $forecast = [
                    'household_id' => 'hh-test-weather-fallback-rec',
                    'period' => ['start' => '2026-10-09T06:00:00Z', 'end' => '2026-10-09T18:00:00Z'],
                    'combined_confidence' => RecommendationConfidence::HIGH,
                    'solar_forecast' => [
                        'predicted_value' => 10.0,
                        'confidence' => RecommendationConfidence::HIGH,
                        'data_quality' => 'GOOD',
                        'data_nature' => 'PREDICTED',
                        'provenance' => ['source' => 'SOLAR_CALIBRATED_V2'],
                    ],
                    'consumption_forecast' => [
                        'predicted_value' => 5.0,
                        'confidence' => RecommendationConfidence::HIGH,
                    ],
                    'energy_balance_projection' => [
                        'classification' => 'SURPLUS',
                        'balance_kwh' => 5.0,
                    ],
                    'time_series' => [
                        ['hour' => 12, 'net_balance_kwh' => 2.0],
                    ],
                    'weather' => [
                        'status' => 'FALLBACK',
                        'available' => false,
                        'source_label' => 'Astronomical Clear-Sky Model',
                        'error' => 'Connection timed out',
                    ],
                ];

                $candidates = $engine->evaluate($forecast, null, [], [], $now);
                $shiftRec = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $shiftRec = $c;
                        break;
                    }
                }

                Assert::true($shiftRec !== null);
                Assert::true($shiftRec->evidence->weather !== null);
                $wEv = $shiftRec->evidence->weather;
                Assert::same('FALLBACK', $wEv['status']);
                Assert::false(isset($wEv['cloud_cover_pct']), 'No fabricated cloud cover in fallback');
                Assert::false(isset($wEv['irradiance_wm2']), 'No fabricated irradiance in fallback');
                Assert::true(str_contains($wEv['explanation'], 'clear-sky'), 'Explanation indicates clear-sky fallback');
            }),

            TestResult::check('WEATHER_PLANNING_WINDOW without surplus forecast yields qualified informational impact with zero fabricated numbers', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-21 10:00:00', new DateTimeZone('UTC'));

                $insight = [
                    'id' => 'ins-wpw-test',
                    'type' => 'WEATHER_PLANNING_WINDOW',
                    'title' => 'Clear Skies Expected to Support Solar Production',
                    'summary' => 'Tomorrow forecast indicates low cloud cover and high solar irradiance.',
                    'significance' => 'MODERATE',
                    'confidence' => 'HIGH',
                    'quality' => 'GOOD',
                    'data_nature' => 'PREDICTED',
                    'affected_period_start' => '2026-10-22',
                    'affected_period_end' => '2026-10-22',
                ];

                // Evaluate with insights but NO forecast surplus
                $candidates = $engine->evaluate(null, null, [], [$insight], $now);

                $wpwCand = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $wpwCand = $c;
                        break;
                    }
                }

                Assert::true($wpwCand !== null, 'Weather planning window candidate must be generated');
                Assert::same(RecommendationImpact::TYPE_INFORMATIONAL, $wpwCand->impact->type, 'Must use informational impact when no surplus is justified');
                Assert::same(null, $wpwCand->impact->kwhValue, 'Must not claim unsupported numerical kWh');
                Assert::same(null, $wpwCand->impact->inrValue, 'Must not claim unsupported monetary savings');
                Assert::true(str_contains($wpwCand->impact->description, 'Maximize solar self-consumption'), 'Uses qualified advisory language');
            }),

            TestResult::check('WEATHER_PLANNING_WINDOW with classified forecast surplus adopts forecast surplus kWh', function (): void {
                $engine = new RecommendationEngine();
                $now = new DateTimeImmutable('2026-10-21 10:00:00', new DateTimeZone('UTC'));

                $insight = [
                    'id' => 'ins-wpw-test-surplus',
                    'type' => 'WEATHER_PLANNING_WINDOW',
                    'title' => 'Clear Skies Expected to Support Solar Production',
                    'summary' => 'Tomorrow forecast indicates low cloud cover and high solar irradiance.',
                    'significance' => 'HIGH',
                    'confidence' => 'HIGH',
                    'quality' => 'GOOD',
                    'data_nature' => 'PREDICTED',
                    'affected_period_start' => '2026-10-22',
                    'affected_period_end' => '2026-10-22',
                ];

                $forecast = [
                    'household_id' => 'hh-wpw',
                    'horizon' => 'DAY_AHEAD',
                    'combined_confidence' => RecommendationConfidence::HIGH,
                    'solar_forecast' => [
                        'predicted_value' => 15.0,
                        'confidence' => RecommendationConfidence::HIGH,
                        'data_quality' => 'GOOD',
                        'data_nature' => 'PREDICTED',
                    ],
                    'consumption_forecast' => [
                        'predicted_value' => 8.0,
                        'confidence' => RecommendationConfidence::HIGH,
                    ],
                    'energy_balance_projection' => [
                        'state' => 'CLASSIFIED',
                        'classification' => 'POTENTIAL_SURPLUS',
                        'balance_kwh' => 7.0,
                    ],
                ];

                $candidates = $engine->evaluate($forecast, null, [], [$insight], $now);

                $wpwCand = null;
                foreach ($candidates as $c) {
                    if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $wpwCand = $c;
                        break;
                    }
                }

                Assert::true($wpwCand !== null);
                Assert::true(in_array($wpwCand->impact->type, [RecommendationImpact::TYPE_SOLAR_UTILIZATION, RecommendationImpact::TYPE_SOLAR_SELF_CONSUMPTION], true));
                Assert::same(7.0, $wpwCand->impact->kwhValue, 'Must adopt forecast surplus kWh');
            }),
        ];
    }
}
