<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Household\HouseholdService;
use App\Domain\Intelligence\Insight;
use App\Domain\Recommendation\Recommendation;
use App\Repositories\BillRepository;
use App\Repositories\InsightRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * SPMS Context Builder (docs/01 §14, §15, §26, docs/10 D-040, D-044).
 *
 * Constructs a minimal, authoritative, privacy-preserving factual context
 * strictly scoped to the active household for LLM explanation.
 */
final class SPMSContextBuilder
{
    public const DOMAIN_DASHBOARD = 'dashboard';
    public const DOMAIN_SOLAR = 'solar';
    public const DOMAIN_FORECAST = 'forecast';
    public const DOMAIN_ENERGY_BALANCE = 'energy_balance';
    public const DOMAIN_COST = 'cost';
    public const DOMAIN_BILLS = 'bills';
    public const DOMAIN_INSIGHTS = 'insights';
    public const DOMAIN_RECOMMENDATIONS = 'recommendations';
    public const DOMAIN_GOODWE = 'goodwe';

    public function __construct(
        private readonly ?HouseholdService $householdService = null,
        private readonly ?ForecastService $forecastService = null,
        private readonly ?BillRepository $billRepository = null,
        private readonly ?SolarReadingRepository $solarRepository = null,
        private readonly ?InsightRepository $insightRepository = null,
        private readonly ?RecommendationRepository $recRepository = null,
        private readonly ?PDO $pdo = null
    ) {
    }

    /**
     * Identify relevant context domains based on user query and optional page context.
     *
     * @return list<string>
     */
    public function detectDomains(string $query, ?string $pageContext = null): array
    {
        $q = mb_strtolower(trim($query));
        $domains = [];

        // Check explicit page context hint
        if ($pageContext !== null && $pageContext !== '') {
            $p = mb_strtolower(trim($pageContext));
            if (str_contains($p, 'bill')) {
                $domains[] = self::DOMAIN_BILLS;
            } elseif (str_contains($p, 'forecast')) {
                $domains[] = self::DOMAIN_FORECAST;
                $domains[] = self::DOMAIN_ENERGY_BALANCE;
            } elseif (str_contains($p, 'solar')) {
                $domains[] = self::DOMAIN_SOLAR;
                $domains[] = self::DOMAIN_GOODWE;
            } elseif (str_contains($p, 'cost')) {
                $domains[] = self::DOMAIN_COST;
            } elseif (str_contains($p, 'rec') || str_contains($p, 'advic')) {
                $domains[] = self::DOMAIN_RECOMMENDATIONS;
            } elseif (str_contains($p, 'insight')) {
                $domains[] = self::DOMAIN_INSIGHTS;
            }
        }

        // Keywords in user query
        if (preg_match('/\b(solar|generation|sun|inverter|panel|photovoltaic|kw|telemetry)\b/i', $q)) {
            $domains[] = self::DOMAIN_SOLAR;
            $domains[] = self::DOMAIN_GOODWE;
        }

        if (preg_match('/\b(forecast|tomorrow|predict|expected|day ahead|future|weather|cloud)\b/i', $q)) {
            $domains[] = self::DOMAIN_FORECAST;
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
        }

        if (preg_match('/\b(balance|surplus|deficit|grid|net|export|import|position)\b/i', $q)) {
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
            $domains[] = self::DOMAIN_FORECAST;
        }

        if (preg_match('/\b(cost|price|tariff|charges|bill estimate|rupee|inr|money|savings|expense)\b/i', $q)) {
            $domains[] = self::DOMAIN_COST;
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
        }

        if (preg_match('/\b(bill|apspdcl|units|meter|statement|discom|verified|kwh|consumption history)\b/i', $q)) {
            $domains[] = self::DOMAIN_BILLS;
        }

        if (preg_match('/\b(insight|pattern|trend|unusual|recurring|anomaly)\b/i', $q)) {
            $domains[] = self::DOMAIN_INSIGHTS;
        }

        if (preg_match('/\b(recommend|recommendation|shift|load|action|suggestion|appliance|advice)\b/i', $q)) {
            $domains[] = self::DOMAIN_RECOMMENDATIONS;
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
            $domains[] = self::DOMAIN_FORECAST;
        }

        if (preg_match('/\b(goodwe|sems|stale|offline|disconnected|station)\b/i', $q)) {
            $domains[] = self::DOMAIN_GOODWE;
            $domains[] = self::DOMAIN_SOLAR;
        }

        // If no specific domain matched, provide standard summary domains
        if ($domains === []) {
            $domains = [
                self::DOMAIN_DASHBOARD,
                self::DOMAIN_SOLAR,
                self::DOMAIN_FORECAST,
                self::DOMAIN_RECOMMENDATIONS,
            ];
        }

        return array_values(array_unique($domains));
    }

    /**
     * Build minimal, authoritative context for the active household.
     *
     * @param string $householdId
     * @param string $query
     * @param string|null $pageContext
     * @param DateTimeImmutable|null $referenceTime
     * @return array{
     *     context_text: string,
     *     domains: list<string>,
     *     data_nature: string,
     *     confidence: string,
     *     limitations: list<string>
     * }
     */
    public function build(
        string $householdId,
        string $query,
        ?string $pageContext = null,
        ?DateTimeImmutable $referenceTime = null
    ): array {
        $now = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $domains = $this->detectDomains($query, $pageContext);

        $pdo = $this->pdo ?? Database::connection();
        $dbEnv = getenv('SPMS_DATA_ENVIRONMENT') ?: 'real';
        $isDemo = strcasecmp((string) $dbEnv, 'demo') === 0;

        // 1. Household metadata
        $hhStmt = $pdo->prepare('SELECT id, label, timezone, location_label, service_number, status FROM households WHERE id = :id LIMIT 1');
        $hhStmt->execute([':id' => $householdId]);
        $hh = $hhStmt->fetch(PDO::FETCH_ASSOC);

        $maskedServiceNumber = null;
        if (!empty($hh['service_number'])) {
            $sn = (string) $hh['service_number'];
            $maskedServiceNumber = mb_strlen($sn) > 4
                ? str_repeat('*', mb_strlen($sn) - 4) . mb_substr($sn, -4)
                : $sn;
        }

        $contextLines = [];
        $contextLines[] = '### AUTHORITATIVE SPMS HOUSEHOLD CONTEXT';
        $contextLines[] = sprintf('- Environment: %s', $isDemo ? 'DEMO (Simulated data)' : 'REAL (Measured production telemetry)');
        $contextLines[] = sprintf('- Household: %s', $hh['label'] ?? 'Home');
        $contextLines[] = sprintf('- Timezone: %s', $hh['timezone'] ?? 'Asia/Kolkata');
        $contextLines[] = sprintf('- Location: %s', $hh['location_label'] ?? 'Unspecified');
        $contextLines[] = sprintf('- Consumer/Service Number: %s', $maskedServiceNumber ?? 'Not configured');
        $contextLines[] = sprintf('- Reference Time: %s', $now->format('Y-m-d H:i:s T'));

        $allLimitations = [];
        $overallNature = $isDemo ? 'SIMULATED' : 'ACTUAL';
        $overallConfidence = 'MODERATE';

        // 2. Solar & GoodWe Hardware Context
        if (in_array(self::DOMAIN_SOLAR, $domains, true) || in_array(self::DOMAIN_GOODWE, $domains, true) || in_array(self::DOMAIN_DASHBOARD, $domains, true)) {
            $connStmt = $pdo->prepare('SELECT connection_status, provider, station_id, inverter_sn FROM household_solar_connections WHERE household_id = :id LIMIT 1');
            $connStmt->execute([':id' => $householdId]);
            $conn = $connStmt->fetch(PDO::FETCH_ASSOC);

            $solarRepo = $this->solarRepository ?? new SolarReadingRepository($pdo);
            $latestReading = $solarRepo->findLatestByHouseholdId($householdId);

            $contextLines[] = '';
            $contextLines[] = '### SOLAR GENERATION & INVERTER HARDWARE (ACTUAL TELEMETRY)';
            if ($conn) {
                $contextLines[] = sprintf('- Solar Inverter Connection: %s (%s)', $conn['connection_status'] ?? 'CONNECTED', $conn['provider'] ?? 'GoodWe SEMS');
                $contextLines[] = sprintf('- Station ID: %s', $conn['station_id'] ?? 'N/A');
                $contextLines[] = sprintf('- Inverter Serial: %s', $conn['inverter_sn'] ?? 'N/A');
                $contextLines[] = '- Nameplate Capacity: 3.0 kW';
            } else {
                $contextLines[] = '- Solar Inverter Connection: NOT_CONNECTED (using default residential capacity profile)';
                $allLimitations[] = 'GoodWe inverter is not connected to SPMS; generation values reflect residential baseline.';
            }

            if ($latestReading) {
                $readingTime = (string) ($latestReading['measurement_time'] ?? '');
                $powerW = (float) ($latestReading['power_w'] ?? 0.0);
                $dailyKwh = (float) ($latestReading['daily_generation_kwh'] ?? 0.0);
                $isStale = false;
                try {
                    $dtReading = new DateTimeImmutable($readingTime);
                    $diffMins = abs($now->getTimestamp() - $dtReading->getTimestamp()) / 60;
                    $isStale = $diffMins > 15;
                } catch (\Throwable) {
                    $isStale = true;
                }

                $contextLines[] = sprintf('- Current Active Solar Output: %.1f W (%.3f kW)', $powerW, $powerW / 1000.0);
                $contextLines[] = sprintf('- Today Cumulative Generation: %.3f kWh', $dailyKwh);
                $contextLines[] = sprintf('- Latest Telemetry Recorded: %s (%s)', $readingTime, $isStale ? 'STALE (>15 min old)' : 'FRESH');
                if ($isStale) {
                    $allLimitations[] = 'Latest GoodWe solar telemetry reading is STALE; generation numbers reflect past observations.';
                }
            } else {
                $contextLines[] = '- Latest Solar Reading: UNAVAILABLE (No telemetry recorded yet)';
                $allLimitations[] = 'No solar readings have been ingested for this household.';
            }
        }

        // 3. Forecast, Energy Balance & Cost Context
        if (in_array(self::DOMAIN_FORECAST, $domains, true) || in_array(self::DOMAIN_ENERGY_BALANCE, $domains, true) || in_array(self::DOMAIN_COST, $domains, true) || in_array(self::DOMAIN_RECOMMENDATIONS, $domains, true)) {
            $fcService = $this->forecastService ?? new ForecastService();
            $forecast = null;
            try {
                $forecast = $fcService->getForecast($householdId, ForecastHorizon::DAY_AHEAD, $now);
            } catch (\Throwable) {
                $forecast = null;
            }

            $contextLines[] = '';
            $contextLines[] = '### F3 FORECAST & F4 ENERGY BALANCE (PREDICTED / POTENTIAL)';
            if ($forecast) {
                $solarPred = $forecast['solar_forecast']['predicted_value'] ?? $forecast['solar']['predicted_value'] ?? null;
                $consPred = $forecast['consumption_forecast']['predicted_value'] ?? $forecast['consumption']['predicted_value'] ?? null;
                $fcSource = $forecast['forecast_source'] ?? $forecast['provenance']['forecast_source'] ?? 'DETERMINISTIC';
                $f4 = $forecast['energy_balance_projection'] ?? [];
                $f5 = $forecast['energy_cost_projection'] ?? [];

                $contextLines[] = sprintf('- Forecast Horizon: Day-Ahead (24 Hours)');
                $contextLines[] = sprintf('- Forecast Source Architecture: %s', $fcSource);
                $contextLines[] = sprintf('- Predicted Solar Generation: %s kWh (Confidence: %s)', $solarPred !== null ? number_format((float) $solarPred, 3) : 'UNAVAILABLE', $forecast['solar']['confidence'] ?? 'LOW');
                $contextLines[] = sprintf('- Predicted Household Consumption: %s kWh (Confidence: %s)', $consPred !== null ? number_format((float) $consPred, 3) : 'UNAVAILABLE (0 verified bills)', $forecast['consumption']['confidence'] ?? 'INSUFFICIENT');

                if (isset($f4['state'])) {
                    $contextLines[] = sprintf('- Energy Balance State: %s', $f4['state']);
                    $contextLines[] = sprintf('- Classified Position: %s', $f4['classification'] ?? 'UNAVAILABLE');
                    $contextLines[] = sprintf('- Projected Balance: %s kWh (Measurement Context: POTENTIAL, not measured grid flow)', isset($f4['balance_kwh']) && $f4['balance_kwh'] !== null ? number_format((float) $f4['balance_kwh'], 3) : 'null');
                    if (!empty($f4['explanation'])) {
                        $contextLines[] = sprintf('- Balance Explanation: %s', $f4['explanation']);
                    }
                }

                if (in_array(self::DOMAIN_COST, $domains, true) && isset($f5['state'])) {
                    $contextLines[] = '';
                    $contextLines[] = '### F5 ENERGY COST ESTIMATE (FUTURE_ESTIMATE, NOT A BILL)';
                    $contextLines[] = sprintf('- Estimated Variable Cost: %.2f INR', (float) ($f5['estimated_cost_inr'] ?? 0.0));
                    $contextLines[] = sprintf('- Pricing Basis: %s', $f5['pricing_basis'] ?? 'HISTORICAL_BILL_RELATIONSHIP');
                    if (isset($f5['fixed_charge']['amount'])) {
                        $contextLines[] = sprintf('- Monthly Fixed Charge: %.2f INR (Applies separately per AP tariff, not in variable estimate)', (float) $f5['fixed_charge']['amount']);
                    }
                    if (isset($f5['cost_range'])) {
                        $contextLines[] = sprintf('- Cost Uncertainty Range: %.2f INR to %.2f INR (±%d%%)', (float) ($f5['cost_range']['min_inr'] ?? 0), (float) ($f5['cost_range']['max_inr'] ?? 0), (int) ($f5['cost_range']['half_width_pct'] ?? 25));
                    }
                }

                if (!empty($forecast['limitations'])) {
                    foreach ($forecast['limitations'] as $lim) {
                        $allLimitations[] = (string) $lim;
                    }
                }

                $overallConfidence = $forecast['combined_confidence'] ?? $forecast['confidence'] ?? 'MODERATE';
                $overallNature = $forecast['data_nature'] ?? 'PREDICTED';
            } else {
                $contextLines[] = '- Energy Forecast: UNAVAILABLE';
                $allLimitations[] = 'Forecast pipeline is currently unavailable for this household.';
            }
        }

        // 4. Bills Context
        if (in_array(self::DOMAIN_BILLS, $domains, true)) {
            $billRepo = $this->billRepository ?? new BillRepository($pdo);
            $allBills = $billRepo->listForHousehold($householdId);
            $verifiedBills = array_values(array_filter($allBills, static fn (Bill $b): bool => $b->status() === BillStatus::VERIFIED));

            $contextLines[] = '';
            $contextLines[] = '### APSPDCL ELECTRICITY BILLS (VERIFIED HISTORY)';
            $contextLines[] = sprintf('- Total Uploaded Statements: %d', count($allBills));
            $contextLines[] = sprintf('- Total Verified Bills: %d', count($verifiedBills));

            if ($verifiedBills !== []) {
                // Latest verified bill
                $latestBill = $verifiedBills[0];
                $start = $latestBill->value(BillField::BILLING_PERIOD_START);
                $end = $latestBill->value(BillField::BILLING_PERIOD_END);
                $kwh = $latestBill->value('consumption_kwh');
                $amount = $latestBill->value('total_due') ?? $latestBill->value('bill_amount');
                $energyChg = $latestBill->value('energy_charges');
                $fixedChg = $latestBill->value('fixed_charges');

                $contextLines[] = sprintf('- Latest Verified Billing Cycle: %s to %s', $start ?? 'N/A', $end ?? 'N/A');
                $contextLines[] = sprintf('- Billed Consumption: %s kWh', $kwh !== null ? (string) $kwh : 'N/A');
                $contextLines[] = sprintf('- Total Bill Amount: ₹%s', $amount !== null ? (string) $amount : 'N/A');
                if ($energyChg !== null) {
                    $contextLines[] = sprintf('- Energy Charges: ₹%s', (string) $energyChg);
                }
                if ($fixedChg !== null) {
                    $contextLines[] = sprintf('- Fixed Charges: ₹%s', (string) $fixedChg);
                }
            } else {
                $contextLines[] = '- Verified Bill History: 0 bills verified. (Uploaded bills must be reviewed and verified to ground consumption forecasts).';
                $allLimitations[] = 'No verified electricity bills exist; historical demand analysis requires at least 1 verified bill.';
            }
        }

        // 5. Insights Context
        if (in_array(self::DOMAIN_INSIGHTS, $domains, true)) {
            $insRepo = $this->insightRepository ?? new InsightRepository($pdo);
            $insights = $insRepo->list(['household_id' => $householdId]);

            $contextLines[] = '';
            $contextLines[] = '### F6 DETECTED PATTERNS & INSIGHTS';
            if ($insights !== []) {
                $contextLines[] = sprintf('- Active Insights Count: %d', count($insights));
                foreach (array_slice($insights, 0, 3) as $ins) {
                    $title = $ins instanceof Insight ? $ins->title() : ($ins['title'] ?? 'Insight');
                    $summary = $ins instanceof Insight ? $ins->summary() : ($ins['summary'] ?? '');
                    $sig = $ins instanceof Insight ? $ins->significance() : ($ins['significance'] ?? 'LOW');
                    $contextLines[] = sprintf('  * [%s] %s: %s', $sig, $title, $summary);
                }
            } else {
                $contextLines[] = '- Active Insights: None currently detected.';
            }
        }

        // 6. Recommendations Context
        if (in_array(self::DOMAIN_RECOMMENDATIONS, $domains, true) || in_array(self::DOMAIN_DASHBOARD, $domains, true)) {
            $recRepo = $this->recRepository ?? new RecommendationRepository($pdo);
            $recs = $recRepo->list($householdId, ['status' => 'ACTIVE']);

            $contextLines[] = '';
            $contextLines[] = '### F7 ADVISORY RECOMMENDATIONS (OPPORTUNITIES, NOT AUTOMATED ACTIONS)';
            if ($recs !== []) {
                $contextLines[] = sprintf('- Active Actionable Recommendations: %d', count($recs));
                foreach (array_slice($recs, 0, 3) as $r) {
                    $title = $r instanceof Recommendation ? $r->title() : ($r['title'] ?? 'Recommendation');
                    $priority = $r instanceof Recommendation ? $r->priority() : ($r['priority'] ?? 'MEDIUM');
                    $action = $r instanceof Recommendation ? $r->action() : ($r['action'] ?? '');
                    $reason = $r instanceof Recommendation ? $r->reason() : ($r['reason'] ?? '');
                    $contextLines[] = sprintf('  * [%s Priority] %s: %s. Reason: %s', $priority, $title, $action, $reason);
                }
            } else {
                $contextLines[] = '- Active Recommendations: None currently active.';
            }
        }

        // 7. Limitations & Caveats Summary
        $uniqueLimitations = array_values(array_unique($allLimitations));
        if ($uniqueLimitations !== []) {
            $contextLines[] = '';
            $contextLines[] = '### KNOWN DATA LIMITATIONS & UNCERTAINTIES';
            foreach ($uniqueLimitations as $lim) {
                $contextLines[] = sprintf('- NOTE: %s', $lim);
            }
        }

        return [
            'context_text' => implode("\n", $contextLines),
            'domains' => $domains,
            'data_nature' => $overallNature,
            'confidence' => $overallConfidence,
            'limitations' => $uniqueLimitations,
        ];
    }
}
