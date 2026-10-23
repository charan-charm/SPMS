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
class RecommendationEngine
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
                title: 'Use flexible appliances during solar hours',
                summary: sprintf('Potential solar-window opportunity of approximately %.1f kWh expected during daytime solar hours.', $balanceKwh),
                action: 'Run the washing machine, water pump or water heater during the higher-solar period.',
                reason: sprintf('Solar generation is forecast to exceed estimated household consumption by approximately %.1f kWh during peak daylight. This is a forecast-derived potential opportunity, not guaranteed savings.', $balanceKwh),
                timingWindow: '11:00–15:00',
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
                    $surplusKwh = isset($insight['context']['average_surplus_kwh']) && is_numeric($insight['context']['average_surplus_kwh'])
                        ? (float) $insight['context']['average_surplus_kwh']
                        : null;
                    $impact = ($surplusKwh !== null && $surplusKwh > 0.0)
                        ? RecommendationImpact::solarSelfConsumption($surplusKwh)
                        : RecommendationImpact::informational('Run discretionary appliances during peak daylight hours to maximize solar self-consumption.');

                    $candidates[] = new RecommendationCandidate(
                        type: RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR,
                        priority: Recommendation::PRIORITY_MEDIUM,
                        title: 'Use flexible appliances during solar hours',
                        summary: 'Historical patterns demonstrate recurring midday solar surplus.',
                        action: 'Run discretionary appliances during peak daylight hours to maximize solar self-consumption.',
                        reason: 'Consistently higher midday generation than consumption across past cycles.',
                        timingWindow: '11:00–14:00',
                        impact: $impact,
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
                        title: 'Prepare flexible loads before evening deficit',
                        summary: 'Household encounters energy deficit after sunset.',
                        action: 'Complete flexible electricity-intensive activities before sunset to minimize evening grid reliance.',
                        reason: 'Solar production drops sharply while household evening demand rises.',
                        timingWindow: '18:00–21:00',
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
                        title: 'Moderate household electricity consumption',
                        summary: 'Recurring high household consumption observed across recent billing periods.',
                        action: 'Review discretionary household electricity consumption and reduce avoidable usage where practical.',
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
                        title: 'Shift discretionary usage to avoid peak cost',
                        summary: 'Electricity tariffs or slab rates indicate potential cost exposure.',
                        action: 'Shift flexible energy usage into peak daytime solar generation to lower variable grid energy charges.',
                        reason: 'Discretionary grid consumption during peak hours increases electricity expenditure.',
                        timingWindow: 'Before 17:00 or after 22:00',
                        impact: RecommendationImpact::costAwareShift(1.5, null),
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
                        title: 'Adjust load expectations for solar decline',
                        summary: 'Observed solar output trend is lower than previous comparable periods.',
                        action: 'Verify solar panel cleanliness and inverter telemetry, and plan flexible usage around lower expected solar yield.',
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

        $weatherEvidence = $this->buildWeatherEvidence(
            $forecast['weather'] ?? null,
            $forecast['period'] ?? null,
            $timeSeries,
            !empty($surplusHours) ? $surplusHours : null
        );
        if ($weatherEvidence !== null && in_array($weatherEvidence['status'] ?? '', ['ACTIVE', 'STALE'], true)) {
            $provenance[] = 'OPEN_METEO';
        }

        // Rule A: Daytime Solar Opportunity / Surplus
        // IF solar > consumption by threshold OR balance classification is SURPLUS / POTENTIAL_SURPLUS
        if (
            ($balanceState === 'SURPLUS' || $balanceState === 'POTENTIAL_SURPLUS' || ($solarVal !== null && $consumptionVal !== null && ($solarVal - $consumptionVal) >= $this->thresholds->minSurplusKwh))
            && $balanceKwh !== null && $balanceKwh >= $this->thresholds->minSurplusKwh
        ) {
            $timingWindow = !empty($surplusHours)
                ? $surplusHours
                : '10:00–15:00';

            $ruleAWeatherEvidence = $this->buildWeatherEvidence(
                $forecast['weather'] ?? null,
                $forecast['period'] ?? null,
                $timeSeries,
                $timingWindow
            ) ?? $weatherEvidence;

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
                provenance: array_values(array_unique($provenance)),
                weather: $ruleAWeatherEvidence
            );

            $action = 'Run the washing machine, water pump or water heater during the higher-solar period.';
            $weatherReasonPart = '';
            if ($ruleAWeatherEvidence !== null && !empty($ruleAWeatherEvidence['explanation'])) {
                $weatherReasonPart = ' ' . $ruleAWeatherEvidence['explanation'];
            }
            $reason = sprintf(
                'Solar generation is forecast to exceed estimated household consumption by approximately %.1f kWh during peak sunlight hours.%s This is a forecast-derived potential opportunity, not guaranteed savings.',
                $balanceKwh,
                $weatherReasonPart
            );

            $dataNature = (($solarFc['data_nature'] ?? '') === 'SIMULATED' || ($forecast['data_nature'] ?? '') === 'SIMULATED')
                ? 'SIMULATED'
                : 'PREDICTED';

            $candidates[] = new RecommendationCandidate(
                type: $candidateType,
                priority: Recommendation::PRIORITY_HIGH,
                title: 'Use flexible appliances during solar hours',
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
                ? $deficitHours
                : '18:00–22:00';

            $unitRate = $this->extractUnitRate($costProj);
            $impact = RecommendationImpact::avoidedDeficit($deficitKwh, $unitRate);

            $ruleBWeatherEvidence = $this->buildWeatherEvidence(
                $forecast['weather'] ?? null,
                $forecast['period'] ?? null,
                $timeSeries,
                $timingWindow
            ) ?? $weatherEvidence;

            $action = 'Postpone or reduce high-power appliance usage during the evening deficit period.';
            $weatherReasonPart = '';
            if ($ruleBWeatherEvidence !== null && !empty($ruleBWeatherEvidence['explanation'])) {
                $weatherReasonPart = ' ' . $ruleBWeatherEvidence['explanation'];
            }
            $reason = sprintf(
                'Household demand is expected to exceed solar availability by ≈%.1f kWh during this window, leading to potential grid reliance.%s',
                $deficitKwh,
                $weatherReasonPart
            );

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
                provenance: array_values(array_unique($provenance)),
                weather: $ruleBWeatherEvidence
            );

            $candidates[] = new RecommendationCandidate(
                type: RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW,
                priority: Recommendation::PRIORITY_MEDIUM,
                title: 'Reduce high-power appliances during evening deficit',
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
            $unitRate = $this->extractUnitRate($costProj);
            $savingsInr = ($unitRate !== null && $unitRate > 0) ? round($shiftKwh * $unitRate, 2) : null;

            $shiftWindow = !empty($surplusHours) ? $surplusHours : '10:00–15:00';
            $ruleEWeatherEvidence = $this->buildWeatherEvidence(
                $forecast['weather'] ?? null,
                $forecast['period'] ?? null,
                $timeSeries,
                $shiftWindow
            ) ?? $weatherEvidence;

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
                provenance: array_values(array_unique($costProvenance)),
                weather: $ruleEWeatherEvidence
            );

            $candidateSummary = $savingsInr !== null
                ? sprintf('Potential variable energy charge reduction of up to ₹%.2f.', $savingsInr)
                : sprintf('Potential variable energy charge reduction by shifting ≈%.1f kWh into solar hours.', $shiftKwh);
            $candidateReason = $savingsInr !== null
                ? sprintf('Estimated variable energy cost is ₹%.2f. Maximizing solar self-consumption directly mitigates grid energy cost exposure.', $costInr)
                : sprintf('Estimated variable energy cost is ₹%.2f. Maximizing daytime solar self-consumption mitigates grid energy cost exposure.', $costInr);

            $candidates[] = new RecommendationCandidate(
                type: RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT,
                priority: Recommendation::PRIORITY_MEDIUM,
                title: 'Shift discretionary usage to avoid peak cost',
                summary: $candidateSummary,
                action: 'Shift flexible energy usage into peak daytime solar generation to lower variable grid energy charges.',
                reason: $candidateReason,
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

        $weatherEvidence = $this->buildWeatherEvidence($forecast['weather'] ?? null, $forecast['period'] ?? null);

        foreach ($insights as $insight) {
            $type = $insight['type'] ?? '';
            $confidence = RecommendationConfidence::normalize($insight['confidence'] ?? 'LOW');
            $status = $insight['status'] ?? 'ACTIVE';

            if (!RecommendationConfidence::isActionable($confidence) || !in_array($status, $activeStatuses, true)) {
                continue;
            }

            $provenance = ['F6_INSIGHTS'];
            if ($weatherEvidence !== null && in_array($weatherEvidence['status'] ?? '', ['ACTIVE', 'STALE'], true)) {
                $provenance[] = 'OPEN_METEO';
            }
            $insightSummary = $insight['summary'] ?? '';
            $insightPeriodStart = $insight['affected_period_start'] ?? $now->format('Y-m-d');
            $insightPeriodEnd = $insight['affected_period_end'] ?? $now->format('Y-m-d');

            // Rule C: Evening Deficit Preparation (Diurnal Pattern)
            if ($type === 'DAYTIME_SURPLUS_EVENING_DEFICIT' || $type === 'SOLAR_DEMAND_MISMATCH') {
                $candidates[] = new RecommendationCandidate(
                    type: RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Prepare flexible loads before evening deficit',
                    summary: 'Recent pattern shows strong midday solar generation followed by high evening demand.',
                    action: 'Complete flexible electricity-intensive activities (e.g. laundry, water pumping) before sunset to minimize evening grid reliance.',
                    reason: 'Your household consistently experiences potential surplus around midday and deficit after sunset. Shifting flexible loads earlier reduces evening grid dependency.',
                    timingWindow: 'Daily before 16:00',
                    impact: RecommendationImpact::loadShift(1.5, 'midday solar (11:00–15:00)'),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: $provenance,
                        weather: $weatherEvidence
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
                    title: 'Moderate household electricity consumption',
                    summary: 'Recurring high household consumption observed across recent billing periods.',
                    action: 'Review discretionary household electricity consumption and reduce avoidable usage where practical.',
                    reason: $reason,
                    timingWindow: 'Ongoing billing cycle',
                    impact: RecommendationImpact::highDemandReduction($excessKwh),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: $provenance,
                        weather: $weatherEvidence
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
                    title: 'Adjust load expectations for solar decline',
                    summary: 'Observed solar production has declined relative to prior periods.',
                    action: 'Verify solar panel cleanliness and inverter telemetry, and plan flexible usage around lower expected solar yield.',
                    reason: sprintf('Solar production showed a noticeable decline (%s). Ensure hardware status is normal and adjust daytime usage expectations.', $insightSummary),
                    timingWindow: 'Over the next 7 days',
                    impact: RecommendationImpact::informational('Adjust daytime flexible load expectations to match reduced solar generation.'),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: $provenance,
                        weather: $weatherEvidence
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

            // Weather Planning Window Rule
            if ($type === 'WEATHER_PLANNING_WINDOW') {
                $projectedSurplusKwh = null;
                if ($forecast !== null && isset($forecast['energy_balance_projection'])) {
                    $eb = $forecast['energy_balance_projection'];
                    if (($eb['state'] ?? '') === 'CLASSIFIED' && ($eb['classification'] ?? '') === 'POTENTIAL_SURPLUS') {
                        $projectedSurplusKwh = (float) ($eb['balance_kwh'] ?? 0.0);
                    }
                }

                $impact = ($projectedSurplusKwh !== null && $projectedSurplusKwh > 0.0)
                    ? RecommendationImpact::solarSelfConsumption($projectedSurplusKwh)
                    : RecommendationImpact::informational('Maximize solar self-consumption by scheduling flexible appliances during clear-sky midday hours.');

                $candidates[] = new RecommendationCandidate(
                    type: RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Use flexible appliances during solar hours',
                    summary: 'Potential solar-window opportunity: forecast indicates favorable solar production and low cloud cover during peak daytime hours.',
                    action: 'Run washing machines, water pumps, water heaters, or EV charging during the 10:00–15:00 solar window.',
                    reason: 'Clear skies and elevated solar irradiance are expected tomorrow. Running high-consumption loads during this window increases direct solar self-consumption and lowers grid energy costs.',
                    timingWindow: '10:00–15:00 tomorrow',
                    impact: $impact,
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: array_unique(array_merge($provenance, ['OPEN_METEO_WEATHER_FORECAST'])),
                        weather: $weatherEvidence
                    ),
                    confidence: $confidence,
                    quality: $insight['quality'] ?? 'GOOD',
                    dataNature: $insight['data_nature'] ?? 'PREDICTED',
                    provenance: array_unique(array_merge($provenance, ['OPEN_METEO_WEATHER_FORECAST'])),
                    limitations: $limitations,
                    relevantPeriodStart: $insightPeriodStart,
                    relevantPeriodEnd: $insightPeriodEnd,
                    validFrom: $now,
                    validUntil: $validUntil
                );
            }

            // Cloudy Production Risk Rule
            if ($type === 'CLOUDY_PRODUCTION_RISK') {
                $candidates[] = new RecommendationCandidate(
                    type: RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE,
                    priority: Recommendation::PRIORITY_MEDIUM,
                    title: 'Prepare for lower solar generation during cloudy conditions',
                    summary: 'Forecast indicates increased cloud cover or low solar irradiance tomorrow.',
                    action: 'Postpone flexible discretionary electricity usage or run essentials during the clearest daylight hours.',
                    reason: 'Overcast conditions are forecast to attenuate solar irradiance. Reducing daytime flexible loads helps avoid unexpected grid reliance.',
                    timingWindow: 'Tomorrow daytime',
                    impact: RecommendationImpact::informational('Adjust daytime flexible load scheduling to match reduced solar generation.'),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: $limitations,
                        provenance: array_unique(array_merge($provenance, ['OPEN_METEO_WEATHER_FORECAST'])),
                        weather: $weatherEvidence
                    ),
                    confidence: $confidence,
                    quality: $insight['quality'] ?? 'GOOD',
                    dataNature: $insight['data_nature'] ?? 'PREDICTED',
                    provenance: array_unique(array_merge($provenance, ['OPEN_METEO_WEATHER_FORECAST'])),
                    limitations: $limitations,
                    relevantPeriodStart: $insightPeriodStart,
                    relevantPeriodEnd: $insightPeriodEnd,
                    validFrom: $now,
                    validUntil: $validUntil
                );
            }

            // Solar Telemetry Stale or Inverter Disconnected Rule
            if ($type === 'SOLAR_TELEMETRY_STALE' || $type === 'INVERTER_DISCONNECTED') {
                $candidates[] = new RecommendationCandidate(
                    type: RecommendationTaxonomy::TYPE_DATA_LIMITATION,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Check solar inverter connectivity',
                    summary: 'Inverter telemetry has not updated within the expected threshold or hardware is offline.',
                    action: 'Verify your solar inverter Wi-Fi logger or network connection to ensure real-time telemetry reaches SPMS.',
                    reason: 'Real-time solar monitoring and live power recommendations depend on current inverter measurements. Telemetry is currently stale or offline.',
                    timingWindow: 'Immediate',
                    impact: RecommendationImpact::informational('Verify inverter logger connectivity to restore real-time monitoring and recommendations.'),
                    evidence: new RecommendationEvidence(
                        insights: [$insight],
                        limitations: array_merge(['INVERTER_TELEMETRY_STALE'], $limitations),
                        provenance: array_unique(array_merge($provenance, ['GOODWE_SOLAR_TELEMETRY']))
                    ),
                    confidence: $confidence,
                    quality: 'DEGRADED',
                    dataNature: $insight['data_nature'] ?? 'ACTUAL',
                    provenance: array_unique(array_merge($provenance, ['GOODWE_SOLAR_TELEMETRY'])),
                    limitations: array_merge(['INVERTER_TELEMETRY_STALE'], $limitations),
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
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
            title: 'Limited consumption history notice',
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
        $lastObservedRaw = (string) ($solarLatest['received_at'] ?? $solarLatest['measurement_time'] ?? $solarLatest['timestamp'] ?? 'Unknown');
        $lastObservedDisplay = $lastObservedRaw;
        if ($lastObservedRaw !== 'Unknown') {
            try {
                $dt = new DateTimeImmutable($lastObservedRaw, new DateTimeZone('UTC'));
                $dtIst = $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                $lastObservedDisplay = $dtIst->format('H:i') . ' IST';
            } catch (\Throwable) {
                $lastObservedDisplay = $lastObservedRaw;
            }
        }

        return new RecommendationCandidate(
            type: RecommendationTaxonomy::TYPE_DATA_LIMITATION,
            priority: Recommendation::PRIORITY_MEDIUM,
            title: 'Solar data is out of date',
            summary: 'Live solar inverter readings are currently delayed or unavailable.',
            action: 'Check inverter Wi-Fi connection or SEMS Portal sync status to resume real-time recommendations.',
            reason: sprintf('Solar inverter data was last refreshed at %s (>15 minutes ago). Because live inverter telemetry is marked STALE, recommendations rely on predictive modeling until fresh readings resume.', $lastObservedDisplay),
            timingWindow: 'Current inverter status',
            impact: RecommendationImpact::informational('Real-time solar recommendations resume once inverter synchronizes.'),
            evidence: new RecommendationEvidence(
                solarForecast: ['status' => 'STALE', 'last_reading' => $lastObservedDisplay],
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
     * Derive the highest useful forecast solar generation window in household local time.
     *
     * @param list<array<string, mixed>> $timeSeries
     */
    private function findSurplusHours(array $timeSeries): string
    {
        if (empty($timeSeries)) {
            return '';
        }

        // Peak solar generation across the series
        $peakSolar = 0.0;
        foreach ($timeSeries as $pt) {
            $s = (float) ($pt['predicted_solar_kwh'] ?? 0);
            if ($s > $peakSolar) {
                $peakSolar = $s;
            }
        }

        // Target highest useful generation window:
        // Hours where solar generation is at least 68% of daily peak AND net surplus >= minSurplusKwh
        $threshold = max($this->thresholds->minSurplusKwh, $peakSolar * 0.68);

        $usefulHours = [];
        foreach ($timeSeries as $pt) {
            $bal = (float) ($pt['projected_balance_kwh'] ?? $pt['balance_kwh'] ?? 0);
            $solar = (float) ($pt['predicted_solar_kwh'] ?? 0);

            if ($bal >= $this->thresholds->minSurplusKwh && $solar >= $threshold) {
                $h = isset($pt['local_hour'])
                    ? (int) $pt['local_hour']
                    : (isset($pt['hour']) ? (int) $pt['hour'] : null);

                if ($h === null && isset($pt['local_time']) && preg_match('/\s(\d{2}):/', (string) $pt['local_time'], $m)) {
                    $h = (int) $m[1];
                }

                if ($h !== null && $h >= 6 && $h <= 18) {
                    $usefulHours[] = $h;
                }
            }
        }

        // Fallback to all daylight surplus hours if peak threshold yielded empty set
        if (empty($usefulHours)) {
            foreach ($timeSeries as $pt) {
                $bal = (float) ($pt['projected_balance_kwh'] ?? $pt['balance_kwh'] ?? 0);
                if ($bal >= $this->thresholds->minSurplusKwh) {
                    $h = isset($pt['local_hour'])
                        ? (int) $pt['local_hour']
                        : (isset($pt['hour']) ? (int) $pt['hour'] : null);
                    if ($h === null && isset($pt['local_time']) && preg_match('/\s(\d{2}):/', (string) $pt['local_time'], $m)) {
                        $h = (int) $m[1];
                    }
                    if ($h !== null && $h >= 6 && $h <= 18) {
                        $usefulHours[] = $h;
                    }
                }
            }
        }

        if (empty($usefulHours)) {
            return '';
        }

        sort($usefulHours);
        $start = reset($usefulHours);
        $end = end($usefulHours);

        return sprintf('%02d:00–%02d:00', $start, $end);
    }

    /**
     * Derive evening deficit hours in household local time.
     *
     * @param list<array<string, mixed>> $timeSeries
     */
    private function findDeficitHours(array $timeSeries): string
    {
        $deficitHours = [];
        foreach ($timeSeries as $pt) {
            $bal = (float) ($pt['projected_balance_kwh'] ?? $pt['balance_kwh'] ?? 0);
            if ($bal <= -$this->thresholds->minDeficitKwh) {
                $h = isset($pt['local_hour'])
                    ? (int) $pt['local_hour']
                    : (isset($pt['hour']) ? (int) $pt['hour'] : null);
                if ($h === null && isset($pt['local_time']) && preg_match('/\s(\d{2}):/', (string) $pt['local_time'], $m)) {
                    $h = (int) $m[1];
                }
                if ($h !== null) {
                    $deficitHours[] = $h;
                }
            }
        }

        if (empty($deficitHours)) {
            return '';
        }

        // Focus on evening hours (17:00–23:00) when deficit is actionable for household load reduction
        $evening = array_filter($deficitHours, static fn (int $h): bool => $h >= 17 && $h <= 23);
        if (!empty($evening)) {
            sort($evening);
            return sprintf('%02d:00–%02d:00', reset($evening), end($evening));
        }

        sort($deficitHours);
        return sprintf('%02d:00–%02d:00', reset($deficitHours), end($deficitHours));
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

        // Mutual suppression:
        // Case A: If SHIFT_TO_SOLAR_WINDOW is present, suppress USE_AVAILABLE_SOLAR
        // Case B: If EVENING_DEFICIT_PREPARATION is present, suppress AVOID_DEFICIT_WINDOW
        $hasShiftToSolar = false;
        $hasEveningDeficitPrep = false;
        foreach ($unique as $c) {
            if ($c->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                $hasShiftToSolar = true;
            }
            if ($c->type === RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION) {
                $hasEveningDeficitPrep = true;
            }
        }

        if ($hasShiftToSolar || $hasEveningDeficitPrep) {
            $unique = array_filter($unique, static function (RecommendationCandidate $c) use ($hasShiftToSolar, $hasEveningDeficitPrep): bool {
                if ($hasShiftToSolar && $c->type === RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR) {
                    return false;
                }
                if ($hasEveningDeficitPrep && $c->type === RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW) {
                    return false;
                }
                return true;
            });
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

    /**
     * Build normalized weather evidence conforming to Section 10 contract.
     *
     * @param array<string, mixed>|null $rawWeather
     * @param array<string, mixed>|null $period
     * @param list<array<string, mixed>> $timeSeries
     * @param string|null $timingWindow
     * @return array<string, mixed>|null
     */
    private function buildWeatherEvidence(
        ?array $rawWeather,
        ?array $period,
        array $timeSeries = [],
        ?string $timingWindow = null
    ): ?array {
        if ($rawWeather === null || empty($rawWeather)) {
            return null;
        }

        $status = $rawWeather['status'] ?? (($rawWeather['available'] ?? false) ? 'ACTIVE' : 'FALLBACK');

        if ($status === 'FALLBACK' || $status === 'UNAVAILABLE') {
            $explanation = 'External weather data unavailable; forecast is supported by physical clear-sky solar modeling.';
            return [
                'source' => $rawWeather['source_label'] ?? $rawWeather['provider'] ?? 'Astronomical Clear-Sky Model',
                'status' => $status,
                'period_start' => $period['start'] ?? null,
                'period_end' => $period['end'] ?? null,
                'timing_window' => $timingWindow,
                'solar_impact' => 'MODERATE',
                'explanation' => $explanation,
                'limitations' => ['External weather provider unavailable; clear-sky physical solar pattern model applies.'],
            ];
        }

        // Try extracting window-specific atmospheric factors from timeSeries if timingWindow is specified
        $windowMatchingPoints = [];
        $windowStartIso = null;
        $windowEndIso = null;
        $startHour = null;
        $endHour = null;

        if ($timingWindow !== null && preg_match('/(\d{1,2}):\d{2}[–\-](\d{1,2}):\d{2}/u', $timingWindow, $matches)) {
            $startHour = (int) $matches[1];
            $endHour = (int) $matches[2];

            foreach ($timeSeries as $pt) {
                $h = isset($pt['local_hour'])
                    ? (int) $pt['local_hour']
                    : (isset($pt['hour']) ? (int) $pt['hour'] : null);
                if ($h === null && isset($pt['local_time']) && preg_match('/\s(\d{2}):/', (string) $pt['local_time'], $m)) {
                    $h = (int) $m[1];
                }

                if ($h !== null && $h >= $startHour && $h < $endHour) {
                    $windowMatchingPoints[] = $pt;
                }
            }

            if (!empty($windowMatchingPoints)) {
                $windowStartIso = $windowMatchingPoints[0]['timestamp'] ?? null;
                $windowEndIso = end($windowMatchingPoints)['timestamp'] ?? null;
            }
        }

        // Check if matching points contain genuine atmospheric variables
        $pointsWithWeather = array_filter(
            $windowMatchingPoints,
            static fn (array $pt): bool => isset($pt['cloud_cover_pct']) || isset($pt['solar_irradiance_wm2'])
        );

        $cloudPct = null;
        $irradiance = null;
        $tempC = null;
        $precipProb = null;
        $solarImpact = null;
        $isWindowSpecific = false;

        if (!empty($pointsWithWeather)) {
            $isWindowSpecific = true;
            $cloudSum = 0.0;
            $cloudCount = 0;
            $irrSum = 0.0;
            $irrCount = 0;
            $tempSum = 0.0;
            $tempCount = 0;

            foreach ($pointsWithWeather as $pt) {
                if (isset($pt['cloud_cover_pct']) && is_numeric($pt['cloud_cover_pct'])) {
                    $cloudSum += (float) $pt['cloud_cover_pct'];
                    $cloudCount++;
                }
                if (isset($pt['solar_irradiance_wm2']) && is_numeric($pt['solar_irradiance_wm2'])) {
                    $irrSum += (float) $pt['solar_irradiance_wm2'];
                    $irrCount++;
                }
                if (isset($pt['temperature_c']) && is_numeric($pt['temperature_c'])) {
                    $tempSum += (float) $pt['temperature_c'];
                    $tempCount++;
                }
            }

            $cloudPct = $cloudCount > 0 ? round($cloudSum / $cloudCount, 1) : null;
            $irradiance = $irrCount > 0 ? round($irrSum / $irrCount, 1) : null;
            $tempC = $tempCount > 0 ? round($tempSum / $tempCount, 1) : null;

            // Window-specific solar impact
            $isEveningOrNight = ($startHour !== null && $startHour >= 18) || ($irradiance !== null && $irradiance <= 5.0);
            if ($isEveningOrNight) {
                $solarImpact = 'LOW';
                $explanation = sprintf('Solar irradiance is absent during %s, creating full reliance on grid power.', $timingWindow);
            } elseif ($irradiance !== null && $irradiance >= 400.0) {
                $solarImpact = 'STRONG';
                $explanation = sprintf('Clear atmospheric conditions and strong solar irradiance (avg %d W/m²) support high solar availability during %s.', (int) round($irradiance), $timingWindow);
            } elseif ($cloudPct !== null && $cloudPct > 70.0) {
                $solarImpact = 'LOW';
                $explanation = sprintf('Cloud cover (avg %d%%) is forecast to reduce solar availability during %s.', (int) round($cloudPct), $timingWindow);
            } else {
                $solarImpact = 'MODERATE';
                $explanation = sprintf('Moderate atmospheric conditions with partial cloud cover are expected during %s.', $timingWindow);
            }
        } else {
            // Use rawWeather overall fields
            $cloudPct = isset($rawWeather['cloud_cover_pct']) && is_numeric($rawWeather['cloud_cover_pct'])
                ? (float) $rawWeather['cloud_cover_pct']
                : null;
            $irradiance = isset($rawWeather['solar_irradiance_wm2']) && is_numeric($rawWeather['solar_irradiance_wm2'])
                ? (float) $rawWeather['solar_irradiance_wm2']
                : null;
            $precipProb = isset($rawWeather['precipitation_probability_pct']) && is_numeric($rawWeather['precipitation_probability_pct'])
                ? (float) $rawWeather['precipitation_probability_pct']
                : null;
            $tempC = isset($rawWeather['temperature_c']) && is_numeric($rawWeather['temperature_c'])
                ? (float) $rawWeather['temperature_c']
                : null;

            $solarImpact = $rawWeather['solar_impact'] ?? 'MODERATE';
            if ($solarImpact === 'MODERATE' && $cloudPct !== null) {
                if ($cloudPct < 30.0) {
                    $solarImpact = 'STRONG';
                } elseif ($cloudPct > 70.0 || ($precipProb !== null && $precipProb >= 60.0)) {
                    $solarImpact = 'LOW';
                }
            }

            $explanation = match ($solarImpact) {
                'STRONG' => 'Clear atmospheric conditions and high solar irradiance support strong daytime solar availability.',
                'LOW' => 'Elevated cloud cover is forecast to reduce available solar irradiance.',
                default => 'Moderate atmospheric conditions with partial cloud cover are expected across daylight hours.',
            };
        }

        $evidenceLimitations = [];
        if ($status === 'STALE') {
            $evidenceLimitations[] = 'Weather evidence is STALE (>1 hour old).';
        }
        if ($timingWindow !== null && !$isWindowSpecific) {
            $evidenceLimitations[] = sprintf('Hourly atmospheric factors unavailable for timing window %s; using period summary.', $timingWindow);
        }

        $periodStart = $windowStartIso ?? $period['start'] ?? null;
        $periodEnd = $windowEndIso ?? $period['end'] ?? null;

        return array_filter([
            'source' => $rawWeather['source_label'] ?? $rawWeather['provider'] ?? 'Open-Meteo',
            'status' => $status,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'timing_window' => $timingWindow,
            'cloud_cover_pct' => $cloudPct,
            'irradiance_wm2' => $irradiance,
            'precipitation_probability_pct' => $precipProb,
            'temperature_c' => $tempC,
            'weather_code' => $rawWeather['weather_code'] ?? null,
            'condition' => $rawWeather['condition'] ?? null,
            'solar_impact' => $solarImpact,
            'explanation' => $explanation,
            'limitations' => !empty($evidenceLimitations) ? $evidenceLimitations : null,
        ], static fn ($v) => $v !== null);
    }
}
