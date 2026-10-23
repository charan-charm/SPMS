<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillStatus;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Forecast\SolarForecastModel;
use App\Domain\Household\HouseholdService;
use App\Domain\Intelligence\Insight;
use App\Domain\Recommendation\Recommendation;
use App\Domain\Weather\WeatherService;
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
    public const DOMAIN_WEATHER = 'weather';

    public function __construct(
        private readonly ?HouseholdService $householdService = null,
        private readonly ?ForecastService $forecastService = null,
        private readonly ?BillRepository $billRepository = null,
        private readonly ?SolarReadingRepository $solarRepository = null,
        private readonly ?InsightRepository $insightRepository = null,
        private readonly ?RecommendationRepository $recRepository = null,
        private readonly ?WeatherService $weatherService = null,
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
                $domains[] = self::DOMAIN_WEATHER;
            } elseif (str_contains($p, 'weather')) {
                $domains[] = self::DOMAIN_WEATHER;
                $domains[] = self::DOMAIN_FORECAST;
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
        if (preg_match('/\b(solar|generation|generated|generate|sun|inverter|panel|photovoltaic|kw|kwh|telemetry|daily|weekly|monthly|yesterday|today|this week|last week|best day|highest day|lowest day|worst day|least solar|lowest solar|rolling|last 7 days|past 7 days|seven days|7 days|power vs energy|energy vs power|current power|watt|watts)\b/i', $q)) {
            $domains[] = self::DOMAIN_SOLAR;
            $domains[] = self::DOMAIN_GOODWE;
        }

        if (preg_match('/\b(weather|clouds?|rain(?:y|ing)?|temps?|temperatures?|sunny|overcast|sunlight|irradiance|sunrise|sunset)\b/i', $q)) {
            $domains[] = self::DOMAIN_WEATHER;
            $domains[] = self::DOMAIN_FORECAST;
        }

        if (preg_match('/\b(forecasts?|tomorrow|predicts?|predictions?|predicting|expected|day ahead|future|outlook|machine learning|ml model)\b/i', $q)) {
            $domains[] = self::DOMAIN_FORECAST;
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
        }

        if (preg_match('/\b(balance|surplus|deficit|grid|net|export|import|position)\b/i', $q)) {
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
            $domains[] = self::DOMAIN_FORECAST;
        }

        if (preg_match('/\b(costs?|prices?|tariffs?|charges?|bill estimate|rupees?|inr|money|savings?|expenses?)\b/i', $q)) {
            $domains[] = self::DOMAIN_COST;
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
        }

        if (preg_match('/\b(bills?|apspdcl|units|meter|statement|discom|verified|unverified|verify|coverage|cover(?:s|ed|ing)?|electricity needs|consumption|consumption history)\b/i', $q)) {
            $domains[] = self::DOMAIN_BILLS;
        }

        if (preg_match('/\b(demand|household demand|home consumption|consumption)\b/i', $q)) {
            $domains[] = self::DOMAIN_FORECAST;
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
        }

        if (preg_match('/\b(insights?|patterns?|trends?|unusual|recurring|anomal(?:y|ies))\b/i', $q)) {
            $domains[] = self::DOMAIN_INSIGHTS;
        }

        if (preg_match('/\b(recommends?|recommendations?|shifts?|loads?|actions?|suggestions?|appliances?|advice|turn off|switch off|turn on|switch on|control|use more solar|maximize solar|optimize solar|what should i do)\b/i', $q)) {
            $domains[] = self::DOMAIN_RECOMMENDATIONS;
            $domains[] = self::DOMAIN_ENERGY_BALANCE;
            $domains[] = self::DOMAIN_FORECAST;
        }

        if (preg_match('/\b(goodwe|sems|station|fresh)\b/i', $q) || preg_match('/\b(inverter|telemetry|solar|goodwe)\s+(offline|disconnected|stale)\b/i', $q) || preg_match('/\b(stale|offline|disconnected)\s+(telemetry|data|inverter)\b/i', $q) || preg_match('/\b(is\s+(?:the|my)\s+inverter\s+(?:data\s+)?(?:current|online|stale))\b/i', $q)) {
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
        $activeProfile = Database::currentProfile();
        $dbEnv = getenv('SPMS_DATA_ENVIRONMENT') ?: $activeProfile;
        $isDemo = strcasecmp((string) $dbEnv, 'demo') === 0 || strcasecmp((string) $activeProfile, 'demo') === 0;

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
        $dailySummaries = [];

        // 2. Solar & GoodWe Hardware Context
        if (in_array(self::DOMAIN_SOLAR, $domains, true) || in_array(self::DOMAIN_GOODWE, $domains, true) || in_array(self::DOMAIN_DASHBOARD, $domains, true)) {
            $connStmt = $pdo->prepare('SELECT connection_status, provider, station_id, inverter_sn FROM household_solar_connections WHERE household_id = :id LIMIT 1');
            $connStmt->execute([':id' => $householdId]);
            $conn = $connStmt->fetch(PDO::FETCH_ASSOC);

            $solarRepo = $this->solarRepository ?? new SolarReadingRepository($pdo);
            $latestReading = $solarRepo->findLatestByHouseholdId($householdId);
            $dailySummaries = $solarRepo->findDailySummariesByHouseholdId($householdId);

            $contextLines[] = '';
            $contextLines[] = '### SOLAR GENERATION & INVERTER HARDWARE (ACTUAL TELEMETRY)';
            if ($conn) {
                $contextLines[] = sprintf('- Solar Inverter Connection: %s (%s)', $conn['connection_status'] ?? 'CONNECTED', $conn['provider'] ?? 'GoodWe SEMS');
                $contextLines[] = sprintf('- Station ID: %s', $conn['station_id'] ?? 'N/A');
                $contextLines[] = sprintf('- Inverter Serial: %s', $conn['inverter_sn'] ?? 'N/A');
                $ratedWatts = SolarForecastModel::parseCapacityFromInverterSn($conn['inverter_sn'] ?? null) ?? 3000.0;
                $contextLines[] = sprintf('- Nameplate Capacity: %.1f kW (Rated AC Output: %d W)', $ratedWatts / 1000.0, (int) $ratedWatts);
            } else {
                $contextLines[] = '- Solar Inverter Connection: NOT_CONNECTED (using default residential capacity profile)';
                $contextLines[] = '- Nameplate Capacity: 3.0 kW (Default residential profile)';
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
                $contextLines[] = '- Current Active Solar Output: UNAVAILABLE';
                $contextLines[] = '- Today Cumulative Generation: UNAVAILABLE';
                $allLimitations[] = 'No solar readings have been ingested for this household.';
            }

            // Historical Daily, Weekly & Monthly Solar Aggregations (Asia/Kolkata timezone)
            if (!empty($dailySummaries)) {
                $todayYmd = $now->format('Y-m-d');
                $yesterdayYmd = $now->modify('-1 day')->format('Y-m-d');

                $summariesByDate = [];
                foreach ($dailySummaries as $row) {
                    $summariesByDate[(string) $row['cal_date']] = $row;
                }

                $latestSummary = $dailySummaries[0];
                $latestDateStr = (string) $latestSummary['cal_date'];
                if ($latestDateStr !== $todayYmd) {
                    $contextLines[] = sprintf('- Most Recent Day with Telemetry (%s): %.3f kWh (%d readings, peak %.1f W)',
                        $latestDateStr, (float) $latestSummary['max_daily_kwh'], (int) $latestSummary['readings_count'], (float) $latestSummary['max_power_w']
                    );
                }

                $yesterdayRow = $summariesByDate[$yesterdayYmd] ?? null;
                if ($yesterdayRow !== null) {
                    $contextLines[] = sprintf('- Yesterday Generation (%s): %.3f kWh (%d readings, peak %.1f W)',
                        $yesterdayYmd, (float) $yesterdayRow['max_daily_kwh'], (int) $yesterdayRow['readings_count'], (float) $yesterdayRow['max_power_w']
                    );
                } elseif (count($dailySummaries) > 1 && $latestDateStr !== $todayYmd) {
                    $priorDay = $dailySummaries[1];
                    $contextLines[] = sprintf('- Day Prior to Most Recent (%s): %.3f kWh (%d readings)',
                        (string) $priorDay['cal_date'], (float) $priorDay['max_daily_kwh'], (int) $priorDay['readings_count']
                    );
                }

                // Current Calendar Week (Mon–Sun in Asia/Kolkata)
                $currWeekStart = $now->modify('monday this week')->setTime(0, 0, 0);
                $currWeekEnd = $now->modify('sunday this week')->setTime(23, 59, 59);
                $currWeekStartYmd = $currWeekStart->format('Y-m-d');
                $currWeekEndYmd = $currWeekEnd->format('Y-m-d');

                $currWeekKwh = 0.0;
                $currWeekDays = 0;
                foreach ($summariesByDate as $dStr => $sRow) {
                    if ($dStr >= $currWeekStartYmd && $dStr <= $currWeekEndYmd) {
                        $currWeekKwh += (float) $sRow['max_daily_kwh'];
                        $currWeekDays++;
                    }
                }
                if ($currWeekDays > 0) {
                    $contextLines[] = sprintf('- Current Calendar Week (%s to %s): %.3f kWh across %d recorded days',
                        $currWeekStartYmd, $currWeekEndYmd, $currWeekKwh, $currWeekDays
                    );
                } else {
                    $contextLines[] = sprintf('- Current Calendar Week (%s to %s): 0.000 kWh (0 readings; telemetry inactive this week)',
                        $currWeekStartYmd, $currWeekEndYmd
                    );
                }

                // Most Recent Recorded Week (Monday to Sunday of the latest reading's week)
                $latestDt = new DateTimeImmutable($latestDateStr, $now->getTimezone());
                $latestWeekStart = $latestDt->modify('monday this week')->setTime(0, 0, 0);
                $latestWeekEnd = $latestDt->modify('sunday this week')->setTime(23, 59, 59);
                $latestWeekStartYmd = $latestWeekStart->format('Y-m-d');
                $latestWeekEndYmd = $latestWeekEnd->format('Y-m-d');

                $recWeekKwh = 0.0;
                $recWeekBreakdown = [];
                foreach ($summariesByDate as $dStr => $sRow) {
                    if ($dStr >= $latestWeekStartYmd && $dStr <= $latestWeekEndYmd) {
                        $k = (float) $sRow['max_daily_kwh'];
                        $recWeekKwh += $k;
                        $dtDay = new DateTimeImmutable($dStr, $now->getTimezone());
                        $dayName = $dtDay->format('D');
                        $recWeekBreakdown[] = sprintf('%s: %.2f kWh', $dayName, $k);
                    }
                }
                $recWeekDaysCount = count($recWeekBreakdown);
                $contextLines[] = sprintf('- Most Recent Recorded Week (%s to %s): %.3f kWh across %d recorded days (Breakdown: %s)',
                    $latestWeekStartYmd,
                    $latestWeekEndYmd,
                    $recWeekKwh,
                    $recWeekDaysCount,
                    implode(', ', array_reverse($recWeekBreakdown))
                );

                // Preceding Week (for Week-over-Week Comparison)
                $priorWeekStart = $latestWeekStart->modify('-7 days');
                $priorWeekEnd = $latestWeekEnd->modify('-7 days');
                $priorWeekStartYmd = $priorWeekStart->format('Y-m-d');
                $priorWeekEndYmd = $priorWeekEnd->format('Y-m-d');

                $priorWeekKwh = 0.0;
                $priorWeekDays = 0;
                foreach ($summariesByDate as $dStr => $sRow) {
                    if ($dStr >= $priorWeekStartYmd && $dStr <= $priorWeekEndYmd) {
                        $priorWeekKwh += (float) $sRow['max_daily_kwh'];
                        $priorWeekDays++;
                    }
                }
                if ($priorWeekDays > 0) {
                    $diff = $recWeekKwh - $priorWeekKwh;
                    $contextLines[] = sprintf('- Preceding Week (%s to %s): %.3f kWh across %d recorded days (Week-over-Week Change: %+.3f kWh)',
                        $priorWeekStartYmd, $priorWeekEndYmd, $priorWeekKwh, $priorWeekDays, $diff
                    );
                } else {
                    $contextLines[] = sprintf('- Preceding Week (%s to %s): UNAVAILABLE (No records exist for that week; week-over-week comparison cannot be calculated)',
                        $priorWeekStartYmd, $priorWeekEndYmd
                    );
                }

                // Rolling 7-Day Window (Ending on Most Recent Recorded Date)
                $rolling7Start = $latestDt->modify('-6 days')->setTime(0, 0, 0);
                $rolling7StartYmd = $rolling7Start->format('Y-m-d');
                $rolling7Kwh = 0.0;
                $rolling7Days = 0;
                foreach ($summariesByDate as $dStr => $sRow) {
                    if ($dStr >= $rolling7StartYmd && $dStr <= $latestDateStr) {
                        $rolling7Kwh += (float) $sRow['max_daily_kwh'];
                        $rolling7Days++;
                    }
                }
                $contextLines[] = sprintf('- Rolling 7-Day Window (%s to %s): %.3f kWh across %d recorded days',
                    $rolling7StartYmd, $latestDateStr, $rolling7Kwh, $rolling7Days
                );

                // Monthly Generation
                $currMonthYm = $now->format('Y-m');
                $currMonthKwh = 0.0;
                $currMonthDays = 0;
                foreach ($summariesByDate as $dStr => $sRow) {
                    if (str_starts_with($dStr, $currMonthYm)) {
                        $currMonthKwh += (float) $sRow['max_daily_kwh'];
                        $currMonthDays++;
                    }
                }
                if ($currMonthDays > 0) {
                    $contextLines[] = sprintf('- Current Month (%s): %.3f kWh across %d recorded days',
                        $currMonthYm, $currMonthKwh, $currMonthDays
                    );
                } else {
                    $contextLines[] = sprintf('- Current Month (%s): 0.000 kWh (No telemetry recorded in %s)',
                        $currMonthYm, $now->format('F Y')
                    );
                }

                $recMonthYm = substr($latestDateStr, 0, 7);
                if ($recMonthYm !== $currMonthYm) {
                    $recMonthKwh = 0.0;
                    $recMonthDays = 0;
                    foreach ($summariesByDate as $dStr => $sRow) {
                        if (str_starts_with($dStr, $recMonthYm)) {
                            $recMonthKwh += (float) $sRow['max_daily_kwh'];
                            $recMonthDays++;
                        }
                    }
                    $avgMonthDaily = $recMonthDays > 0 ? $recMonthKwh / $recMonthDays : 0.0;
                    $recMonthDt = DateTimeImmutable::createFromFormat('Y-m-d', "{$recMonthYm}-01", $now->getTimezone());
                    $recMonthLabel = $recMonthDt !== false ? $recMonthDt->format('F Y') : $recMonthYm;
                    $contextLines[] = sprintf('- Most Recent Recorded Month (%s): %.3f kWh across %d recorded days (Daily Average: %.3f kWh/day)',
                        $recMonthLabel, $recMonthKwh, $recMonthDays, $avgMonthDaily
                    );
                }

                // Best Generation Day on Record
                $bestDay = null;
                $bestKwh = -1.0;
                foreach ($dailySummaries as $sRow) {
                    $k = (float) $sRow['max_daily_kwh'];
                    if ($k > $bestKwh) {
                        $bestKwh = $k;
                        $bestDay = $sRow;
                    }
                }
                if ($bestDay !== null) {
                    $contextLines[] = sprintf('- Best Generation Day on Record: %.3f kWh on %s (Peak Inverter Output: %.1f W)',
                        $bestKwh, (string) $bestDay['cal_date'], (float) $bestDay['max_power_w']
                    );
                }

                // Lowest Active Generation Day on Record
                $lowestDay = null;
                $lowestKwh = 999999.0;
                foreach ($dailySummaries as $sRow) {
                    $k = (float) $sRow['max_daily_kwh'];
                    if ($k < $lowestKwh && $k > 0.0) {
                        $lowestKwh = $k;
                        $lowestDay = $sRow;
                    }
                }
                if ($lowestDay !== null) {
                    $contextLines[] = sprintf('- Lowest Active Generation Day on Record: %.3f kWh on %s (Peak Inverter Output: %.1f W)',
                        $lowestKwh, (string) $lowestDay['cal_date'], (float) $lowestDay['max_power_w']
                    );
                }

                // All-Time Record Coverage
                $totalKwhAll = 0.0;
                foreach ($dailySummaries as $sRow) {
                    $totalKwhAll += (float) $sRow['max_daily_kwh'];
                }
                $earliestDateStr = (string) end($dailySummaries)['cal_date'];
                $contextLines[] = sprintf('- Total Solar Production on Record: %.3f kWh across %d recorded calendar days (Date span: %s to %s)',
                    $totalKwhAll, count($dailySummaries), $earliestDateStr, $latestDateStr
                );
            } else {
                $contextLines[] = '- Weekly Generation: UNAVAILABLE (No historical solar readings found)';
                $contextLines[] = '- Rolling 7-Day Window: UNAVAILABLE';
                $contextLines[] = '- Monthly Generation: UNAVAILABLE (No historical solar readings found)';
                $contextLines[] = '- Best Generation Day on Record: UNAVAILABLE';
                $contextLines[] = '- Lowest Active Generation Day on Record: UNAVAILABLE';
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

        // 4. Weather & Environmental Outlook Context
        if (in_array(self::DOMAIN_WEATHER, $domains, true)) {
            $weatherSvc = $this->weatherService ?? new WeatherService();
            $weather = null;
            try {
                $weather = $weatherSvc->getWeatherForHousehold($householdId, $now);
            } catch (\Throwable) {
                $weather = null;
            }

            $contextLines[] = '';
            $contextLines[] = '### LOCAL WEATHER CONDITIONS & SOLAR IMPACT';
            if ($weather) {
                $loc = $weather['location'] ?? 'Unspecified';
                $cond = $weather['condition'] ?? 'Unknown';
                $temp = $weather['temperature'] !== null ? sprintf('%.1f°C', (float) $weather['temperature']) : 'UNAVAILABLE';
                $cloud = $weather['cloud_cover'] !== null ? sprintf('%d%%', (int) $weather['cloud_cover']) : 'UNAVAILABLE';
                $impact = $weather['solar_impact'] ?? 'MODERATE';
                $desc = $weather['solar_impact_description'] ?? '';

                $contextLines[] = sprintf('- Location: %s', $loc);
                $contextLines[] = sprintf('- Weather Status: %s', $weather['status'] ?? 'FALLBACK');
                $contextLines[] = sprintf('- Conditions: %s (Cloud Cover: %s, Temperature: %s)', $cond, $cloud, $temp);
                $contextLines[] = sprintf('- Solar Impact Assessment: %s%s', $impact, $desc !== '' ? " — {$desc}" : '');

                if (!empty($weather['sunrise']) && !empty($weather['sunset'])) {
                    $contextLines[] = sprintf('- Daylight Window: Sunrise %s, Sunset %s', $weather['sunrise'], $weather['sunset']);
                }

                if (!empty($weather['limitations'])) {
                    foreach ($weather['limitations'] as $lim) {
                        $allLimitations[] = (string) $lim;
                    }
                }
            } else {
                $contextLines[] = '- Weather Outlook: UNAVAILABLE';
                $allLimitations[] = 'Weather data is currently unavailable for this household location.';
            }
        }

        // 5. Bills Context
        if (in_array(self::DOMAIN_BILLS, $domains, true)) {
            $billRepo = $this->billRepository ?? new BillRepository($pdo);
            $allBills = $billRepo->listForHousehold($householdId);
            $verifiedBills = array_values(array_filter($allBills, static fn (Bill $b): bool => $b->status() === BillStatus::VERIFIED));
            $unverifiedBills = array_values(array_filter($allBills, static fn (Bill $b): bool => $b->status() !== BillStatus::VERIFIED));

            $contextLines[] = '';
            $contextLines[] = '### APSPDCL ELECTRICITY BILLS (VERIFIED HISTORY)';
            $contextLines[] = sprintf('- Total Uploaded Statements: %d', count($allBills));
            $contextLines[] = sprintf('- Total Verified Bills: %d', count($verifiedBills));
            if (count($unverifiedBills) > 0) {
                $contextLines[] = sprintf('- Unverified Bills Awaiting Review: %d statement(s) pending user verification in the Bills workspace', count($unverifiedBills));
            }

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

                if (count($verifiedBills) > 1) {
                    $prevBill = $verifiedBills[1];
                    $prevStart = $prevBill->value(BillField::BILLING_PERIOD_START);
                    $prevEnd = $prevBill->value(BillField::BILLING_PERIOD_END);
                    $prevKwh = $prevBill->value('consumption_kwh');
                    $prevAmt = $prevBill->value('total_due') ?? $prevBill->value('bill_amount');
                    $contextLines[] = sprintf('- Prior Verified Billing Cycle: %s to %s (%s kWh, ₹%s)', $prevStart ?? 'N/A', $prevEnd ?? 'N/A', $prevKwh !== null ? (string) $prevKwh : 'N/A', $prevAmt !== null ? (string) $prevAmt : 'N/A');
                    if ($kwh !== null && $prevKwh !== null && is_numeric($kwh) && is_numeric($prevKwh)) {
                        $diffKwh = (float) $kwh - (float) $prevKwh;
                        $contextLines[] = sprintf('- Consumption Change vs Prior Bill: %+.1f kWh (%+.1f%%)', $diffKwh, ((float) $prevKwh > 0 ? ($diffKwh / (float) $prevKwh) * 100 : 0.0));
                    }
                } else {
                    $contextLines[] = '- Prior Verified Bill: UNAVAILABLE (Only 1 verified bill on file; comparison requires 2+ verified bills)';
                }

                // Solar coverage of bill
                if ($dailySummaries === []) {
                    $solarRepo = $this->solarRepository ?? new SolarReadingRepository($pdo);
                    $dailySummaries = $solarRepo->findDailySummariesByHouseholdId($householdId);
                }

                if ($start !== null && !empty($dailySummaries)) {
                    $billMonthYm = substr((string) $start, 0, 7);
                    $monthSolarKwh = 0.0;
                    $monthSolarDays = 0;
                    foreach ($dailySummaries as $sRow) {
                        if (str_starts_with((string) $sRow['cal_date'], $billMonthYm)) {
                            $monthSolarKwh += (float) $sRow['max_daily_kwh'];
                            $monthSolarDays++;
                        }
                    }
                    if ($monthSolarDays > 0 && $kwh !== null && (float) $kwh > 0) {
                        $coveragePct = ($monthSolarKwh / (float) $kwh) * 100;
                        $contextLines[] = sprintf('- Solar vs Billed Demand (%s): Solar generated %.1f kWh vs billed grid consumption of %.1f kWh (Equivalent to %.1f%% of utility demand). Note: This is an energy ratio, not confirmed self-consumption (household load is unmetered). Official net metering export credits are administered by APSPDCL under APERC regulations.',
                            $billMonthYm, $monthSolarKwh, (float) $kwh, $coveragePct
                        );
                    } elseif ($kwh !== null && (float) $kwh > 0 && !empty($dailySummaries)) {
                        // Cross-period indicative comparison when bill period precedes or follows telemetry records
                        $latestSolarSummary = $dailySummaries[0];
                        $latestSolarDate = (string) $latestSolarSummary['cal_date'];
                        $latestSolarMonthYm = substr($latestSolarDate, 0, 7);
                        $latestSolarMonthKwh = 0.0;
                        $latestSolarMonthDays = 0;
                        foreach ($dailySummaries as $sRow) {
                            if (str_starts_with((string) $sRow['cal_date'], $latestSolarMonthYm)) {
                                $latestSolarMonthKwh += (float) $sRow['max_daily_kwh'];
                                $latestSolarMonthDays++;
                            }
                        }
                        if ($latestSolarMonthDays > 0) {
                            $indicativePct = ($latestSolarMonthKwh / (float) $kwh) * 100;
                            $contextLines[] = sprintf('- Solar vs Billed Demand (Indicative Comparison): Your latest verified bill is for %s (%.1f kWh), while your most recent recorded solar generation is for %s (%.1f kWh across %d recorded days, equivalent to %.1f%% of your billed demand). Direct coverage for the exact billing cycle (%s to %s) cannot be measured because solar telemetry was recorded in %s. Note: This is an energy ratio, not confirmed self-consumption (household load is unmetered).',
                                $billMonthYm, (float) $kwh, $latestSolarMonthYm, $latestSolarMonthKwh, $latestSolarMonthDays, $indicativePct,
                                (string) $start, (string) $end, $latestSolarMonthYm
                            );
                        }
                    }
                }
            } else {
                $contextLines[] = '- Verified Bill History: 0 bills verified. (Uploaded bills must be reviewed and verified to ground consumption forecasts).';
                $allLimitations[] = 'No verified electricity bills exist; historical demand analysis requires at least 1 verified bill.';
            }
        }

        // 6. Insights Context
        if (in_array(self::DOMAIN_INSIGHTS, $domains, true)) {
            $insRepo = $this->insightRepository ?? new InsightRepository($pdo);
            $insights = $insRepo->list(['household_id' => $householdId]);

            $contextLines[] = '';
            $contextLines[] = '### F6 DETECTED PATTERNS & INSIGHTS';
            if ($insights !== []) {
                $contextLines[] = sprintf('- Active Insights Count: %d', count($insights));
                foreach (array_slice($insights, 0, 3) as $ins) {
                    $arr = $ins instanceof Insight ? $ins->toArray() : (is_array($ins) ? $ins : []);
                    $title = $ins instanceof Insight ? $ins->title() : ($arr['title'] ?? 'Insight');
                    $summary = $ins instanceof Insight ? $ins->summary() : ($arr['summary'] ?? '');
                    $sig = $ins instanceof Insight ? $ins->significance() : ($arr['significance'] ?? 'LOW');
                    $expl = $arr['explanation'] ?? '';
                    $evidence = $arr['evidence_summary'] ?? '';
                    $why = $expl !== '' ? $expl : ($evidence !== '' ? $evidence : '');
                    $contextLines[] = sprintf('  * [%s] %s: %s%s', $sig, $title, $summary, $why !== '' ? " (Reason/Why: {$why})" : '');
                }
            } else {
                $contextLines[] = '- Active Insights: None currently detected.';
            }
        }

        // 7. Recommendations Context
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
