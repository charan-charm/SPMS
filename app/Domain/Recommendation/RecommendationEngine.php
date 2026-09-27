<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SPMS F7 — Deterministic Recommendation Rule Engine (docs/01 §15, docs/02 §2 F7, docs/10 D-072).
 *
 * Evaluates authoritative inputs from F3, F4, F5, F6, GoodWe and bills:
 * - Deterministic rule evaluation (no LLM reasoning or arbitrary scores).
 * - Honest confidence propagation (worst-of aggregation for critical inputs).
 * - Controlled taxonomy of 8 types.
 * - Enforces no-direct-appliance-control boundary (household-level advisory only).
 * - Avoids over-generation ("wall of cards") through prioritization and bounded selection.
 */
final class RecommendationEngine
{
    public function __construct(
        private readonly RecommendationThresholds $thresholds = new RecommendationThresholds()
    ) {
    }

    /**
     * Evaluate authoritative inputs and produce prioritized recommendation candidates.
     *
     * @param array<string, mixed>|string|null $forecastOrHouseholdId Combined forecast snapshot OR household ID
     * @param array<string, mixed>|RecommendationEvidence|null $solarLatestOrEvidence Latest solar telemetry OR RecommendationEvidence
     * @param list<array<string, mixed>>|DateTimeImmutable $verifiedBillsOrNow List of verified bills OR evaluation timestamp
     * @param list<array<string, mixed>> $insights List of active F6 insights
     * @param DateTimeImmutable|null $now Evaluation reference timestamp
     * @return list<RecommendationCandidate>
     */
    public function evaluate(
        array|string|null $forecastOrHouseholdId,
        array|RecommendationEvidence|null $solarLatestOrEvidence = null,
        array|DateTimeImmutable $verifiedBillsOrNow = [],
        array $insights = [],
        ?DateTimeImmutable $now = null
    ): array {
        if (is_string($forecastOrHouseholdId) || $solarLatestOrEvidence instanceof RecommendationEvidence) {
            $evidence = $solarLatestOrEvidence instanceof RecommendationEvidence
                ? $solarLatestOrEvidence
                : new RecommendationEvidence();
            $evalTime = $verifiedBillsOrNow instanceof DateTimeImmutable
                ? $verifiedBillsOrNow
                : ($now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC')));

            return $this->evaluateFromEvidence($evidence, $evalTime);
        }

        $forecast = is_array($forecastOrHouseholdId) ? $forecastOrHouseholdId : null;
        $solarLatest = is_array($solarLatestOrEvidence) ? $solarLatestOrEvidence : null;
        $verifiedBills = is_array($verifiedBillsOrNow) ? $verifiedBillsOrNow : [];
        $evalTime = $now ?? ($verifiedBillsOrNow instanceof DateTimeImmutable ? $verifiedBillsOrNow : new DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $candidates = [];

        // 1. Evaluate Data Gaps & Limitations first (Rule G)
        $limitations = $this->extractLimitations($forecast, $solarLatest, $verifiedBills, $insights, $evalTime);

        $hasVerifiedBills = !empty($verifiedBills);
        $solarIsStale = $this->isSolarTelemetryStale($solarLatest, $evalTime);

        // If data is critically missing, produce an honest DATA_LIMITATION candidate and suppress false actionable recommendations
        if (!$hasVerifiedBills && ($forecast === null || ($forecast['consumption_forecast']['confidence'] ?? '') === RecommendationConfidence::INSUFFICIENT)) {
            $candidates[] = $this->buildMissingBillsLimitationCandidate($evalTime, $limitations);
        }

        if ($solarIsStale) {
            $candidates[] = $this->buildStaleSolarLimitationCandidate($evalTime, $solarLatest, $limitations);
        }

        // 2. Evaluate Forecast & Energy Balance Evidence (Rules A, B, E)
        if ($forecast !== null) {
            $this->evaluateForecastRules($forecast, $evalTime, $limitations, $candidates);
        }

        // 3. Evaluate F6 Pattern & Insight Evidence (Rules C, D, F)
        if (!empty($insights)) {
            $this->evaluateInsightRules($insights, $forecast, $evalTime, $limitations, $candidates);
        }

        // 4. Bounded deduplication & prioritization
        return $this->selectAndPrioritizeCandidates($candidates);
    }

    /**
     * Evaluate directly from structured RecommendationEvidence.
     *
     * @return list<RecommendationCandidate>
     */
    public function evaluateFromEvidence(
        RecommendationEvidence $evidence,
        DateTimeImmutable $now
    ): array {
        $candidates = [];

        // 1. Limitation notice if degraded or explicit limitations
        if ($evidence->quality === 'DEGRADED' || !empty($evidence->limitations)) {
            $candidates[] = new RecommendationCandidate(
                type: RecommendationTaxonomy::TYPE_DATA_LIMITATION,
                priority: Recommendation::PRIORITY_MEDIUM,
                title: 'Data Quality Notice: Recommendations Constrained',
                summary: 'Telemetry or forecast gaps constrain advisory precision.',
                action: 'Review data sources or connect inverter to enable higher confidence recommendations.',
                reason: !empty($evidence->limitations) ? implode(' ', $evidence->limitations) : 'Missing or degraded input data limits recommendation confidence.',
                timingWindow: 'Immediate',
                impact: RecommendationImpact::informational('Data quality limitation notice'),
                evidence: $evidence,
                confidence: RecommendationConfidence::CONFIDENCE_LOW,
                quality: 'DEGRADED',
                dataNature: $evidence->dataNature ?? 'ACTUAL',
                provenance: !empty($evidence->provenance) ? $evidence->provenance : ['RecommendationEngine'],
                limitations: !empty($evidence->limitations) ? $evidence->limitations : ['Telemetry or forecasting degraded'],
                relevantPeriodStart: null,
                relevantPeriodEnd: null,
                validFrom: $now,
                validUntil: $now->modify("+{$this->thresholds->defaultValidityHours} hours")
            );
        }

        // 2. Forecast evaluation (Rule A / Surplus)
        $solarFc = $evidence->solarForecast;
        $consumptionFc = $evidence->consumptionForecast;

        $solarVal = 0.0;
        if (isset($solarFc['points']) && is_array($solarFc['points'])) {
            foreach ($solarFc['points'] as $pt) {
                $solarVal += (float) ($pt['value_kwh'] ?? $pt['predicted_value'] ?? 0.0);
            }
        } elseif (isset($solarFc['value_kwh'])) {
            $solarVal = (float) $solarFc['value_kwh'];
        } elseif (isset($solarFc['predicted_value'])) {
            $solarVal = (float) $solarFc['predicted_value'];
        }

        $consumptionVal = 0.0;
        if (isset($consumptionFc['points']) && is_array($consumptionFc['points'])) {
            foreach ($consumptionFc['points'] as $pt) {
                $consumptionVal += (float) ($pt['value_kwh'] ?? $pt['predicted_value'] ?? 0.0);
            }
        } elseif (isset($consumptionFc['value_kwh'])) {
            $consumptionVal = (float) $consumptionFc['value_kwh'];
        } elseif (isset($consumptionFc['predicted_value'])) {
            $consumptionVal = (float) $consumptionFc['predicted_value'];
        }

        $balanceKwh = $solarVal - $consumptionVal;

        if ($solarFc !== null && $consumptionFc !== null && $balanceKwh >= $this->thresholds->minSurplusKwh) {
            $confidence = RecommendationConfidence::worstOf([
                'solar' => $solarFc['confidence'] ?? RecommendationConfidence::CONFIDENCE_MODERATE,
                'consumption' => $consumptionFc['confidence'] ?? RecommendationConfidence::CONFIDENCE_MODERATE,
                'quality' => $evidence->quality ?? 'GOOD',
            ]);

            $candidates[] = new RecommendationCandidate(
                type: RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                priority: Recommendation::PRIORITY_HIGH,
                title: 'Shift Flexible Electricity to Solar Window',
                summary: sprintf('Potential solar-window opportunity of approximately %.1f kWh expected during daytime solar hours.', $balanceKwh),
                action: 'Consider shifting flexible household electricity use (e.g. water heating, laundry, pump) to the midday solar window where practical.',
                reason: sprintf('Solar generation is forecast to exceed estimated household consumption by approximately %.1f kWh during peak daylight. This is a forecast-derived potential opportunity, not guaranteed savings.', $balanceKwh),
                timingWindow: 'Daytime solar window (11:00–15:00)',
                impact: RecommendationImpact::solarSelfConsumption($balanceKwh),
                evidence: $evidence,
                confidence: $confidence,
                quality: $evidence->quality ?? 'GOOD',
                dataNature: $evidence->dataNature ?? 'PREDICTED',
                provenance: !empty($evidence->provenance) ? $evidence->provenance : ['ForecastService (F3)'],
                limitations: $evidence->limitations,
                relevantPeriodStart: null,
                relevantPeriodEnd: null,
                validFrom: $now,
                validUntil: $now->modify("+{$this->thresholds->defaultValidityHours} hours")
            );
        }

        // 3. Insight-based rules
        if (!empty($evidence->insights)) {
            foreach ($evidence->insights as $insight) {
                $insightType = $insight['type'] ?? '';
                $conf = RecommendationConfidence::worstOf(
                    $insight['confidence'] ?? RecommendationConfidence::CONFIDENCE_MODERATE,
                    $evidence->quality ?? 'GOOD'
                );
                if ($insightType === 'RECURRING_POTENTIAL_SURPLUS') {
                    $candidates[] = new RecommendationCandidate(
                        type: RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR,
                        priority: Recommendation::PRIORITY_MEDIUM,
                        title: 'Utilize Consistent Daytime Solar Surplus',
                        summary: 'Historical patterns demonstrate recurring midday surplus.',
                        action: 'Consider shifting flexible household loads into peak daytime solar generation hours.',
                        reason: 'Consistently higher midday generation than consumption across past cycles.',
                        timingWindow: 'Midday solar hours (11:00–14:00)',
                        impact: RecommendationImpact::solarSelfConsumption(2.5),
                        evidence: $evidence,
                        confidence: $conf,
                        quality: $evidence->quality ?? 'GOOD',
                        dataNature: $evidence->dataNature ?? 'PREDICTED',
                        provenance: $evidence->provenance,
                        limitations: $evidence->limitations,
                        relevantPeriodStart: null,
                        relevantPeriodEnd: null,
                        validFrom: $now,
                        validUntil: $now->modify("+{$this->thresholds->defaultValidityHours} hours")
                    );
                } elseif ($insightType === 'RECURRING_POTENTIAL_DEFICIT') {
                    $candidates[] = new RecommendationCandidate(
                        type: RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION,
                        priority: Recommendation::PRIORITY_HIGH,
                        title: 'Prepare for Recurring Evening Deficit Window',
                        summary: 'Household encounters energy deficit after sunset.',
                        action: 'Consider completing flexible high-demand activities before sunset to minimize evening grid reliance.',
                        reason: 'Solar production drops sharply while household evening demand rises.',
                        timingWindow: 'Evening (18:00–21:00)',
                        impact: RecommendationImpact::gridImportReduction(),
                        evidence: $evidence,
                        confidence: $conf,
                        quality: $evidence->quality ?? 'GOOD',
                        dataNature: $evidence->dataNature ?? 'PREDICTED',
                        provenance: $evidence->provenance,
                        limitations: $evidence->limitations,
                        relevantPeriodStart: null,
                        relevantPeriodEnd: null,
                        validFrom: $now,
                        validUntil: $now->modify("+{$this->thresholds->defaultValidityHours} hours")
                    );
                } elseif ($insightType === 'RECURRING_HIGH_DEMAND') {
                    $candidates[] = new RecommendationCandidate(
                        type: RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION,
                        priority: Recommendation::PRIORITY_MEDIUM,
                        title: 'Household Consumption Moderation',
                        summary: 'Recurring high household consumption observed across recent billing periods.',
                        action: 'Review recurring household electricity consumption and reduce avoidable usage where practical.',
                        reason: 'Your last 3 verified bills show recurring household consumption above the current baseline.',
                        timingWindow: 'Ongoing billing cycle',
                        impact: RecommendationImpact::highDemandReduction(),
                        evidence: $evidence,
                        confidence: $conf,
                        quality: $evidence->quality ?? 'GOOD',
                        dataNature: $evidence->dataNature ?? 'PREDICTED',
                        provenance: $evidence->provenance,
                        limitations: $evidence->limitations,
                        relevantPeriodStart: null,
                        relevantPeriodEnd: null,
                        validFrom: $now,
                        validUntil: $now->modify("+{$this->thresholds->defaultValidityHours} hours")
                    );
                } elseif ($insightType === 'COST_IMPACT_PATTERN') {
                    $candidates[] = new RecommendationCandidate(
                        type: RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT,
                        priority: Recommendation::PRIORITY_MEDIUM,
                        title: 'Cost-Aware Load Timing Optimization',
                        summary: 'Electricity tariffs or slab rates indicate potential cost exposure.',
                        action: 'Shift discretionary usage away from higher-rate evening consumption periods.',
                        reason: 'Discretionary grid consumption during peak hours increases electricity expenditure.',
                        timingWindow: 'Before 17:00 or after 22:00',
                        impact: RecommendationImpact::costAwareShift(1.5, 12.5),
                        evidence: $evidence,
                        confidence: $conf,
                        quality: $evidence->quality ?? 'GOOD',
                        dataNature: $evidence->dataNature ?? 'PREDICTED',
                        provenance: $evidence->provenance,
                        limitations: $evidence->limitations,
                        relevantPeriodStart: null,
                        relevantPeriodEnd: null,
                        validFrom: $now,
                        validUntil: $now->modify("+{$this->thresholds->defaultValidityHours} hours")
                    );
                } elseif ($insightType === 'SOLAR_GENERATION_DECLINE') {
                    $candidates[] = new RecommendationCandidate(
                        type: RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE,
                        priority: Recommendation::PRIORITY_MEDIUM,
                        title: 'Solar Generation Decline Advisory',
                        summary: 'Observed solar output trend is lower than previous comparable periods.',
                        action: 'Inspect panel cleanliness and reduce daytime discretionary load expectations.',
                        reason: 'Solar yields have diminished relative to baseline clear-sky performance.',
                        timingWindow: 'Daytime',
                        impact: RecommendationImpact::informational('Adjust daytime flexible load expectations to match reduced solar generation.'),
                        evidence: $evidence,
                        confidence: $conf,
                        quality: $evidence->quality ?? 'GOOD',
                        dataNature: $evidence->dataNature ?? 'PREDICTED',
                        provenance: $evidence->provenance,
                        limitations: $evidence->limitations,
                        relevantPeriodStart: null,
                        relevantPeriodEnd: null,
                        validFrom: $now,
                        validUntil: $now->modify("+{$this->thresholds->defaultValidityHours} hours")
                    );
                }
            }
        }

        return $this->selectAndPrioritizeCandidates($candidates);
    }

    /**
     * Rule A & Rule B & Rule E evaluation from F3 forecast outputs.
     *
     * @param array<string, mixed> $forecast
     * @param list<string> $limitations
     * @param list<RecommendationCandidate> &$candidates
     */
    private function evaluateForecastRules(
        array $forecast,
        DateTimeImmutable $now,
        array $limitations,
        array &$candidates
    ): void {
        $solarFc = $forecast['solar_forecast'] ?? null;
        $consumptionFc = $forecast['consumption_forecast'] ?? null;
        $balanceProj = $forecast['energy_balance_projection'] ?? null;
        $costProj = $forecast['energy_cost_projection'] ?? null;
        $timeSeries = $forecast['time_series'] ?? [];
        $combinedConfidence = $forecast['combined_confidence'] ?? RecommendationConfidence::INSUFFICIENT;

        // Skip actionable recommendations if combined confidence is insufficient
        if (!RecommendationConfidence::isActionable($combinedConfidence)) {
            return;
        }

        $solarVal = isset($solarFc['predicted_value']) ? (float) $solarFc['predicted_value'] : null;
        $consumptionVal = isset($consumptionFc['predicted_value']) ? (float) $consumptionFc['predicted_value'] : null;
        $balanceKwh = isset($balanceProj['balance_kwh']) ? (float) $balanceProj['balance_kwh'] : null;
        $balanceState = $balanceProj['classification'] ?? $balanceProj['state'] ?? 'UNAVAILABLE';

        $provenance = ['F3_FORECAST', 'F4_BALANCE'];
        if (isset($forecast['provenance']['solar']['source'])) {
            $provenance[] = (string) $forecast['provenance']['solar']['source'];
        }

        $periodStart = $forecast['period']['start'] ?? $now->format('Y-m-d');
        $periodEnd = $forecast['period']['end'] ?? $now->format('Y-m-d');
        $validUntil = $now->modify("+{$this->thresholds->defaultValidityHours} hours");

        // Analyze hourly time series for specific timing windows (e.g. 11:00-14:00)
        $surplusHours = $this->findSurplusHours($timeSeries);
        $deficitHours = $this->findDeficitHours($timeSeries);

        // Rule A: Daytime Solar Opportunity / Surplus
        // IF solar > consumption by threshold OR balance classification is SURPLUS / POTENTIAL_SURPLUS
        if (
            ($balanceState === 'SURPLUS' || $balanceState === 'POTENTIAL_SURPLUS' || ($solarVal !== null && $consumptionVal !== null && ($solarVal - $consumptionVal) >= $this->thresholds->minSurplusKwh))
            && $balanceKwh !== null && $balanceKwh >= $this->thresholds->minSurplusKwh
        ) {
            $timingWindow = !empty($surplusHours)
                ? sprintf('Today, %s', $surplusHours)
                : 'Daytime solar window (10:00–15:00)';

            $candidateType = RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW;

            $unitRate = $this->extractUnitRate($costProj);
            $impact = RecommendationImpact::solarUtilization($balanceKwh, $unitRate);

            $evidence = new RecommendationEvidence(
                solarForecast: [
                    'value_kwh' => $solarVal,
                    'unit' => 'kWh',
                    'period' => $forecast['period'] ?? null,
                    'confidence' => $solarFc['confidence'] ?? RecommendationConfidence::MODERATE,
                    'source' => 'SOLAR_FORECAST_MODEL',
                ],
                consumptionForecast: [
                    'value_kwh' => $consumptionVal,
                    'unit' => 'kWh',
                    'period' => $forecast['period'] ?? null,
                    'confidence' => $consumptionFc['confidence'] ?? RecommendationConfidence::MODERATE,
                    'source' => 'CONSUMPTION_FORECAST_MODEL',
                ],
                energyBalance: [
                    'balance_kwh' => $balanceKwh,
                    'state' => $balanceState,
                    'classification' => $balanceState,
                    'period' => $forecast['period'] ?? null,
                ],
                cost: $costProj,
                limitations: $limitations,
                provenance: array_values(array_unique($provenance))
            );

            $action = 'Consider shifting flexible household electricity use (e.g. water heating, laundry, pump) to the midday solar window where practical.';
            $reason = sprintf(
                'Solar generation is forecast to exceed estimated household consumption by approximately %.1f kWh during peak sunlight hours. This is a forecast-derived potential opportunity, not guaranteed savings.',
                $balanceKwh
            );

            $dataNature = (($solarFc['data_nature'] ?? '') === 'SIMULATED' || ($forecast['data_nature'] ?? '') === 'SIMULATED')
                ? 'SIMULATED'
                : 'PREDICTED';

            $candidates[] = new RecommendationCandidate(
                type: $candidateType,
                priority: Recommendation::PRIORITY_HIGH,
                title: 'Shift Flexible Electricity to Solar Window',
                summary: sprintf('Potential solar-window opportunity of approximately %.1f kWh expected during peak sunlight.', $balanceKwh),
                action: $action,
                reason: $reason,
                timingWindow: $timingWindow,
                impact: $impact,
                evidence: $evidence,
                confidence: $combinedConfidence,
                quality: $solarFc['data_quality'] ?? 'GOOD',
                dataNature: $dataNature,
                provenance: array_values(array_unique($provenance)),
                limitations: $limitations,
                relevantPeriodStart: $periodStart,
                relevantPeriodEnd: $periodEnd,
                validFrom: $now,
                validUntil: $validUntil
            );
        }

        // Rule B: Deficit Window
        // IF consumption > solar by threshold OR balance classification is DEFICIT / POTENTIAL_DEFICIT
        if (
            ($balanceState === 'DEFICIT' || $balanceState === 'POTENTIAL_DEFICIT' || ($consumptionVal !== null && $solarVal !== null && ($consumptionVal - $solarVal) >= $this->thresholds->minDeficitKwh))
            && $balanceKwh !== null && abs($balanceKwh) >= $this->thresholds->minDeficitKwh
        ) {
            $deficitKwh = abs($balanceKwh);
            $timingWindow = !empty($deficitHours)
                ? sprintf('Evening, %s', $deficitHours)
                : 'Evening deficit period (18:00–22:00)';

            $unitRate = $this->extractUnitRate($costProj);
            $impact = RecommendationImpact::avoidedDeficit($deficitKwh, $unitRate);

            $evidence = new RecommendationEvidence(
                solarForecast: [
                    'value_kwh' => $solarVal,
                    'unit' => 'kWh',
                    'period' => $forecast['period'] ?? null,
                    'confidence' => $solarFc['confidence'] ?? RecommendationConfidence::MODERATE,
                    'source' => 'SOLAR_FORECAST_MODEL',
                ],
                consumptionForecast: [
                    'value_kwh' => $consumptionVal,
                    'unit' => 'kWh',
                    'period' => $forecast['period'] ?? null,
                    'confidence' => $consumptionFc['confidence'] ?? RecommendationConfidence::MODERATE,
                    'source' => 'CONSUMPTION_FORECAST_MODEL',
                ],
                energyBalance: [
                    'balance_kwh' => $balanceKwh,
                    'state' => $balanceState,
                    'classification' => $balanceState,
                    'period' => $forecast['period'] ?? null,
                ],
                cost: $costProj,
                limitations: $limitations,
                provenance: array_values(array_unique($provenance))
            );

            $action = 'Consider reducing discretionary or flexible electricity usage during the expected deficit window.';
            $reason = sprintf(
                'Household demand is expected to exceed solar availability by ≈%.1f kWh during this window, leading to potential grid reliance.',
                $deficitKwh
            );

            $candidates[] = new RecommendationCandidate(
                type: RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW,
                priority: Recommendation::PRIORITY_MEDIUM,
                title: 'Moderate Usage During Expected Deficit Window',
                summary: sprintf('Expected net deficit of ≈%.1f kWh during evening or low-solar periods.', $deficitKwh),
                action: $action,
                reason: $reason,
                timingWindow: $timingWindow,
                impact: $impact,
                evidence: $evidence,
                confidence: $combinedConfidence,
                quality: $consumptionFc['data_quality'] ?? 'GOOD',
                dataNature: 'PREDICTED',
                provenance: array_values(array_unique($provenance)),
                limitations: $limitations,
                relevantPeriodStart: $periodStart,
                relevantPeriodEnd: $periodEnd,
                validFrom: $now,
                validUntil: $validUntil
            );
        }

        // Rule E: Cost Impact
        // IF F5 provides sufficient cost evidence AND an energy shift recommendation is viable
        if (
            $costProj !== null
            && ($costProj['state'] ?? '') === 'CALCULATED'
            && isset($costProj['estimated_cost_inr'])
            && (float) $costProj['estimated_cost_inr'] > 0
            && $balanceKwh !== null
            && abs($balanceKwh) >= $this->thresholds->minSurplusKwh
        ) {
            $costInr = (float) $costProj['estimated_cost_inr'];
            $shiftKwh = min(abs($balanceKwh), $solarVal ?? abs($balanceKwh));
            $unitRate = $this->extractUnitRate($costProj) ?? 7.50;
            $savingsInr = round($shiftKwh * $unitRate, 2);

            $impact = RecommendationImpact::costAwareShift($shiftKwh, $savingsInr);
            $costProvenance = array_merge($provenance, ['F5_COST']);

            $evidence = new RecommendationEvidence(
                solarForecast: [
                    'value_kwh' => $solarVal,
                    'unit' => 'kWh',
                    'confidence' => $solarFc['confidence'] ?? RecommendationConfidence::MODERATE,
                ],
                consumptionForecast: [
                    'value_kwh' => $consumptionVal,
                    'unit' => 'kWh',
                    'confidence' => $consumptionFc['confidence'] ?? RecommendationConfidence::MODERATE,
                ],
                energyBalance: [
                    'balance_kwh' => $balanceKwh,
                    'state' => $balanceState,
                ],
                cost: [
                    'estimated_cost_inr' => $costInr,
                    'cost_range' => $costProj['cost_range'] ?? null,
                    'currency' => 'INR',
                    'source' => 'F5_ENERGY_COST',
                ],
                limitations: $limitations,
                provenance: array_values(array_unique($costProvenance))
            );

            $candidates[] = new RecommendationCandidate(
                type: RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT,
                priority: Recommendation::PRIORITY_MEDIUM,
                title: 'Cost-Aware Load Shifting',
                summary: sprintf('Potential variable energy charge reduction of up to ₹%.2f.', $savingsInr),
                action: 'Shift discretionary usage from evening hours into peak daytime solar generation.',
                reason: sprintf('Estimated variable energy cost is ₹%.2f. Maximizing solar self-consumption directly mitigates grid energy cost exposure.', $costInr),
                timingWindow: 'Upcoming billing period',
                impact: $impact,
                evidence: $evidence,
                confidence: RecommendationConfidence::worstOf($combinedConfidence, RecommendationConfidence::MODERATE),
                quality: 'GOOD',
                dataNature: 'ESTIMATED',
                provenance: array_values(array_unique($costProvenance)),
                limitations: $limitations,
                relevantPeriodStart: $periodStart,
                relevantPeriodEnd: $periodEnd,
                validFrom: $now,
                validUntil: $validUntil
            );
        }
    }

    /**
     * Rule C, Rule D & Rule F evaluation from F6 insight engine outputs.
     *
     * @param list<array<string, mixed>> $insights
     * @param array<string, mixed>|null $forecast
     * @param DateTimeImmutable $now
     * @param list<string> $limitations
     * @param list<RecommendationCandidate> &$candidates
     */
    private function evaluateInsightRules(
        array $insights,
        ?array $forecast,
        DateTimeImmutable $now,
        array $limitations,
        array &$candidates
    ): void {
        $validUntil = $now->modify("+{$this->thresholds->defaultValidityHours} hours");

        $activeStatuses = [
            \App\Domain\Intelligence\InsightLifecycle::DETECTED,
            \App\Domain\Intelligence\InsightLifecycle::VALIDATED,
            \App\Domain\Intelligence\InsightLifecycle::ACTIVE,
            \App\Domain\Intelligence\InsightLifecycle::UPDATED,
        ];

        foreach ($insights as $insight) {
            $type = $insight['type'] ?? '';
            $confidence = RecommendationConfidence::normalize($insight['confidence'] ?? 'LOW');
            $status = $insight['status'] ?? 'ACTIVE';

            if (!RecommendationConfidence::isActionable($confidence) || !in_array($status, $activeStatuses, true)) {
                continue;
            }

            $provenance = ['F6_INSIGHTS'];
            $insightSummary = $insight['summary'] ?? '';
            $insightPeriodStart = $insight['affected_period_start'] ?? $now->format('Y-m-d');
            $insightPeriodEnd = $insight['affected_period_end'] ?? $now->format('Y-m-d');

            // Rule C: Evening Deficit Preparation (Diurnal Pattern)
            if ($type === 'DAYTIME_SURPLUS_EVENING_DEFICIT' || $type === 'SOLAR_DEMAND_MISMATCH') {
                $candidates[] = new RecommendationCandidate(
                    type: RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Prepare Flexible Loads Before Evening Deficit',
                    summary: 'Recent pattern shows strong midday solar generation followed by high evening demand.',
                    action: 'Complete flexible electricity-intensive activities (e.g. laundry, water pumping) before 16:00.',
                    reason: 'Your household consistently experiences potential surplus around midday and deficit after sunset. Shifting flexible loads earlier reduces evening grid dependency.',
                    timingWindow: 'Daily before 16:00',
                    impact: RecommendationImpact::loadShift(1.5, 'midday solar (11:00–15:00)'),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: $provenance
                    ),
                    confidence: $confidence,
                    quality: $insight['quality'] ?? 'GOOD',
                    dataNature: $insight['data_nature'] ?? 'PREDICTED',
                    provenance: $provenance,
                    limitations: $limitations,
                    relevantPeriodStart: $insightPeriodStart,
                    relevantPeriodEnd: $insightPeriodEnd,
                    validFrom: $now,
                    validUntil: $validUntil
                );
            }

            // Rule D: High Demand Reduction
            if ($type === 'RECURRING_HIGH_DEMAND' || $type === 'CONSUMPTION_INCREASE') {
                $excessKwh = isset($insight['context']['excess_kwh']) && is_numeric($insight['context']['excess_kwh'])
                    ? (float) $insight['context']['excess_kwh']
                    : null;
                $reason = ($type === 'RECURRING_HIGH_DEMAND')
                    ? 'Your last 3 verified bills show recurring household consumption above the current baseline.'
                    : sprintf('Household consumption has trended higher than baseline (%s).', $insightSummary);
                $candidates[] = new RecommendationCandidate(
                    type: RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION,
                    priority: Recommendation::PRIORITY_MEDIUM,
                    title: 'Household Consumption Moderation',
                    summary: 'Recurring high household consumption observed across recent billing periods.',
                    action: 'Review recurring household electricity consumption and reduce avoidable usage where practical.',
                    reason: $reason,
                    timingWindow: 'Ongoing billing cycle',
                    impact: RecommendationImpact::highDemandReduction($excessKwh),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: $provenance
                    ),
                    confidence: $confidence,
                    quality: $insight['quality'] ?? 'GOOD',
                    dataNature: $insight['data_nature'] ?? 'ESTIMATED',
                    provenance: $provenance,
                    limitations: $limitations,
                    relevantPeriodStart: $insightPeriodStart,
                    relevantPeriodEnd: $insightPeriodEnd,
                    validFrom: $now,
                    validUntil: $validUntil
                );
            }

            // Rule F: Solar Decline Response
            if ($type === 'SOLAR_PRODUCTION_DECLINE') {
                $candidates[] = new RecommendationCandidate(
                    type: RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE,
                    priority: Recommendation::PRIORITY_LOW,
                    title: 'Adjust Load Expectations for Solar Decline',
                    summary: 'Observed solar production has declined relative to prior periods.',
                    action: 'Verify solar panel cleanliness and inverter telemetry, and plan flexible usage around lower expected solar yield.',
                    reason: sprintf('Solar production showed a noticeable decline (%s). Ensure hardware status is normal and adjust daytime usage expectations.', $insightSummary),
                    timingWindow: 'Over the next 7 days',
                    impact: RecommendationImpact::informational('Adjust daytime flexible load expectations to match reduced solar generation.'),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: $provenance
                    ),
                    confidence: $confidence,
                    quality: $insight['quality'] ?? 'GOOD',
                    dataNature: $insight['data_nature'] ?? 'ESTIMATED',
                    provenance: $provenance,
                    limitations: $limitations,
                    relevantPeriodStart: $insightPeriodStart,
                    relevantPeriodEnd: $insightPeriodEnd,
                    validFrom: $now,
                    validUntil: $validUntil
                );
            }
        }
    }

    /**
     * Build honest DATA_LIMITATION support candidate when bills are missing.
     *
     * @param list<string> $limitations
     */
    private function buildMissingBillsLimitationCandidate(DateTimeImmutable $now, array $limitations): RecommendationCandidate
    {
        $validUntil = $now->modify("+{$this->thresholds->defaultValidityHours} hours");

        return new RecommendationCandidate(
            type: RecommendationTaxonomy::TYPE_DATA_LIMITATION,
            priority: Recommendation::PRIORITY_LOW,
            title: 'Limited Consumption History Notice',
            summary: 'Actionable consumption-based recommendations require verified electricity bills.',
            action: 'Upload your recent electricity bills to unlock personalized consumption and savings recommendations.',
            reason: 'Without verified billing history, household electricity demand cannot be accurately modeled, preventing reliable deficit and cost shift advice.',
            timingWindow: 'Until verified bills are added',
            impact: RecommendationImpact::informational('Upload verified electricity bills to enable full analytical capabilities.'),
            evidence: new RecommendationEvidence(
                limitations: array_merge(['NO_VERIFIED_BILLS'], $limitations),
                provenance: ['SYSTEM']
            ),
            confidence: RecommendationConfidence::INSUFFICIENT,
            quality: 'DEGRADED',
            dataNature: 'ACTUAL',
            provenance: ['SYSTEM'],
            limitations: array_merge(['NO_VERIFIED_BILLS'], $limitations),
            relevantPeriodStart: null,
            relevantPeriodEnd: null,
            validFrom: $now,
            validUntil: $validUntil
        );
    }

    /**
     * Build honest DATA_LIMITATION support candidate when solar telemetry is stale.
     *
     * @param array<string, mixed>|null $solarLatest
     * @param list<string> $limitations
     */
    private function buildStaleSolarLimitationCandidate(DateTimeImmutable $now, ?array $solarLatest, array $limitations): RecommendationCandidate
    {
        $validUntil = $now->modify('+6 hours');
        $lastObserved = $solarLatest['received_at'] ?? $solarLatest['measurement_time'] ?? $solarLatest['timestamp'] ?? 'Unknown';

        return new RecommendationCandidate(
            type: RecommendationTaxonomy::TYPE_DATA_LIMITATION,
            priority: Recommendation::PRIORITY_MEDIUM,
            title: 'Inverter Telemetry Stale: Check Connection',
            summary: 'Live solar inverter readings are currently delayed or unavailable.',
            action: 'Check inverter Wi-Fi connection or SEMS Portal sync status.',
            reason: sprintf('Solar inverter data was last refreshed at %s (>15 minutes ago). Solar recommendations degrade until fresh readings resume.', $lastObserved),
            timingWindow: 'Current inverter status',
            impact: RecommendationImpact::informational('Real-time solar recommendations resume once inverter synchronizes.'),
            evidence: new RecommendationEvidence(
                solarForecast: ['status' => 'STALE', 'last_reading' => $lastObserved],
                limitations: array_merge(['STALE_SOLAR_TELEMETRY'], $limitations),
                provenance: ['GOODWE_SEMS']
            ),
            confidence: RecommendationConfidence::LOW,
            quality: 'STALE',
            dataNature: 'ACTUAL',
            provenance: ['GOODWE_SEMS'],
            limitations: array_merge(['STALE_SOLAR_TELEMETRY'], $limitations),
            relevantPeriodStart: null,
            relevantPeriodEnd: null,
            validFrom: $now,
            validUntil: $validUntil
        );
    }

    /**
     * Extract limitations across all inputs.
     *
     * @param array<string, mixed>|null $forecast
     * @param array<string, mixed>|null $solarLatest
     * @param list<array<string, mixed>> $verifiedBills
     * @param list<array<string, mixed>> $insights
     * @param DateTimeImmutable $now
     * @return list<string>
     */
    private function extractLimitations(
        ?array $forecast,
        ?array $solarLatest,
        array $verifiedBills,
        array $insights,
        DateTimeImmutable $now
    ): array {
        $limitations = [];

        if (empty($verifiedBills)) {
            $limitations[] = 'NO_VERIFIED_BILLS';
        } elseif (count($verifiedBills) < 3) {
            $limitations[] = 'INCOMPLETE_BILLING_HISTORY';
        }

        if ($this->isSolarTelemetryStale($solarLatest, $now)) {
            $limitations[] = 'STALE_SOLAR_READINGS';
        }

        if ($forecast !== null && !empty($forecast['limitations'])) {
            foreach ($forecast['limitations'] as $lim) {
                if (is_string($lim)) {
                    $limitations[] = $lim;
                }
            }
        }

        return array_values(array_unique($limitations));
    }

    private function isSolarTelemetryStale(?array $solarLatest, DateTimeImmutable $now): bool
    {
        if ($solarLatest === null) {
            return false; // Not configured or no readings yet
        }

        $timeStr = (string) ($solarLatest['received_at'] ?? $solarLatest['measurement_time'] ?? $solarLatest['timestamp'] ?? '');
        if ($timeStr === '') {
            return false;
        }

        $ts = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timeStr, new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $timeStr);

        if ($ts === false) {
            try {
                $ts = new DateTimeImmutable($timeStr, new DateTimeZone('UTC'));
            } catch (\Exception) {
                return false;
            }
        }

        return ($now->getTimestamp() - $ts->getTimestamp()) > $this->thresholds->staleDataSeconds;
    }

    /**
     * @param list<array<string, mixed>> $timeSeries
     */
    private function findSurplusHours(array $timeSeries): string
    {
        $surplusHours = [];
        foreach ($timeSeries as $pt) {
            $bal = (float) ($pt['balance_kwh'] ?? 0);
            if ($bal >= $this->thresholds->minSurplusKwh && isset($pt['timestamp'])) {
                $time = substr((string) $pt['timestamp'], 11, 5);
                if ($time !== '') {
                    $surplusHours[] = $time;
                }
            }
        }

        if (empty($surplusHours)) {
            return '';
        }

        return sprintf('%s–%s', reset($surplusHours), end($surplusHours));
    }

    /**
     * @param list<array<string, mixed>> $timeSeries
     */
    private function findDeficitHours(array $timeSeries): string
    {
        $deficitHours = [];
        foreach ($timeSeries as $pt) {
            $bal = (float) ($pt['balance_kwh'] ?? 0);
            if ($bal <= -$this->thresholds->minDeficitKwh && isset($pt['timestamp'])) {
                $time = substr((string) $pt['timestamp'], 11, 5);
                if ($time !== '') {
                    $deficitHours[] = $time;
                }
            }
        }

        if (empty($deficitHours)) {
            return '';
        }

        return sprintf('%s–%s', reset($deficitHours), end($deficitHours));
    }

    private function extractUnitRate(?array $costProj): ?float
    {
        if ($costProj === null) {
            return null;
        }

        if (isset($costProj['pricing']['unit_rate'])) {
            return (float) $costProj['pricing']['unit_rate'];
        }

        return null;
    }

    /**
     * Filter, deduplicate, and order candidates up to MAX_ACTIVE_RECOMMENDATIONS.
     *
     * @param list<RecommendationCandidate> $candidates
     * @return list<RecommendationCandidate>
     */
    private function selectAndPrioritizeCandidates(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }

        // Deduplicate by type and window
        $unique = [];
        foreach ($candidates as $c) {
            $key = $c->type . '|' . $c->timingWindow;
            if (!isset($unique[$key])) {
                $unique[$key] = $c;
            } else {
                // Keep higher priority
                $existingPriority = $this->priorityRank($unique[$key]->priority);
                $newPriority = $this->priorityRank($c->priority);
                if ($newPriority > $existingPriority) {
                    $unique[$key] = $c;
                }
            }
        }

        // Sort by priority DESC, then confidence DESC
        usort($unique, function (RecommendationCandidate $a, RecommendationCandidate $b): int {
            $pDiff = $this->priorityRank($b->priority) <=> $this->priorityRank($a->priority);
            if ($pDiff !== 0) {
                return $pDiff;
            }

            return RecommendationConfidence::rank($b->confidence) <=> RecommendationConfidence::rank($a->confidence);
        });

        return array_slice(array_values($unique), 0, $this->thresholds->maxActiveRecommendations);
    }

    private function priorityRank(string $priority): int
    {
        return match (strtoupper($priority)) {
            Recommendation::PRIORITY_HIGH => 3,
            Recommendation::PRIORITY_MEDIUM => 2,
            default => 1,
        };
    }
}
