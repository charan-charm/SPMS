<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Household\HouseholdService;
use App\Domain\Intelligence\EnergyIntelligenceAdapter;
use App\Domain\Intelligence\InsightEngine;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Recommendation\Recommendation;
use App\Domain\Recommendation\RecommendationConfidence;
use App\Domain\Recommendation\RecommendationService;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Domain\Solar\DTO\NormalizedSolarSnapshot;
use App\Repositories\BillRepository;
use App\Repositories\InsightRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Database;
use App\Support\DataEnvironments;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * DemoScenarioSeeder — SPMS D1 Core Demo Intelligence & Energy Scenarios.
 *
 * Implements the 5 core demonstration scenarios identified in D0:
 * - SCEN-01: Solar Surplus Residence
 * - SCEN-02: Persistent Grid Deficit & High Demand
 * - SCEN-03: Sustained Solar Overproduction & Net Surplus
 * - SCEN-04: Solar Generation Decline & Data Quality Degradation
 * - SCEN-05: Sudden Consumption Surge & Anomaly Detection
 *
 * Strict Architectural Rails:
 * - Runs ONLY in spms_demo (or spms_test during automated tests).
 * - Refuses execution if targeting spms_real.
 * - Uses real authoritative services: EnergyBalanceService (F4), EnergyCostService (F5),
 *   and InsightEngine (F6).
 * - All simulated records explicitly maintain data_nature = 'SIMULATED'.
 * - No fake telemetry tables created; insights are persisted through InsightRepository.
 */
final class DemoScenarioSeeder
{
    public const SCENARIOS = [
        'SCEN-01' => [
            'id' => 'SCEN-01',
            'name' => 'Solar Surplus Residence',
            'email' => 'demo.surplus@spms.local',
            'household_label' => 'Solar Surplus Residence',
            'service_number' => 'DEMO-1000-2000-01',
            'location' => 'Kodumur / Andhra Pradesh',
            'timezone' => 'Asia/Kolkata',
            'solar_capacity_kw' => 3.0,
            'purpose' => 'Demonstrate a household where solar availability significantly exceeds expected household consumption during the useful solar window.',
            'narrative' => 'Solar Surplus Residence — Explore daytime solar surplus and evening energy deficit for a 3 kW residential solar installation.',
            'data_nature' => 'SIMULATED',
        ],
        'SCEN-02' => [
            'id' => 'SCEN-02',
            'name' => 'Persistent Grid Deficit & High Demand',
            'email' => 'demo.deficit@spms.local',
            'household_label' => 'DEMO — Persistent Grid Deficit & High Demand',
            'service_number' => 'DEMO-1000-2000-02',
            'location' => 'Kurnool / Andhra Pradesh',
            'timezone' => 'Asia/Kolkata',
            'solar_capacity_kw' => 3.0,
            'purpose' => 'Demonstrate recurring household demand exceeding solar generation across multiple historical months.',
            'narrative' => 'Persistent Grid Deficit & High Demand — Explore recurring evening and night loads consistently exceeding solar across multiple historical months.',
            'data_nature' => 'SIMULATED',
        ],
        'SCEN-03' => [
            'id' => 'SCEN-03',
            'name' => 'Sustained Solar Overproduction & Net Surplus',
            'email' => 'demo.overproduction@spms.local',
            'household_label' => 'DEMO — Sustained Solar Surplus',
            'service_number' => 'DEMO-1000-2000-03',
            'location' => 'Kodumur / Andhra Pradesh',
            'timezone' => 'Asia/Kolkata',
            'solar_capacity_kw' => 5.0,
            'purpose' => 'Demonstrate recurring solar overproduction, zero variable cost, and honest separate fixed charges.',
            'narrative' => 'Sustained Solar Overproduction — Explore sustained solar overproduction, zero variable energy cost, and honest separate fixed grid charges.',
            'data_nature' => 'SIMULATED',
        ],
        'SCEN-04' => [
            'id' => 'SCEN-04',
            'name' => 'Solar Generation Decline & Data Quality Degradation',
            'email' => 'demo.decline@spms.local',
            'household_label' => 'DEMO — Solar Decline & Quality Degradation',
            'service_number' => 'DEMO-1000-2000-04',
            'location' => 'Nandyal / Andhra Pradesh',
            'timezone' => 'Asia/Kolkata',
            'solar_capacity_kw' => 4.0,
            'purpose' => 'Demonstrate solar production decline detection while honestly propagating degraded evidence quality.',
            'narrative' => 'Solar Generation Decline — Explore solar production decline detection while honestly propagating degraded evidence quality limitations.',
            'data_nature' => 'SIMULATED',
        ],
        'SCEN-05' => [
            'id' => 'SCEN-05',
            'name' => 'Sudden Consumption Surge & Anomaly Detection',
            'email' => 'demo.surge@spms.local',
            'household_label' => 'DEMO — Consumption Surge & Anomaly',
            'service_number' => 'DEMO-1000-2000-05',
            'location' => 'Adoni / Andhra Pradesh',
            'timezone' => 'Asia/Kolkata',
            'solar_capacity_kw' => 3.5,
            'purpose' => 'Demonstrate household consumption surge anomaly detection compared against recurring household baseline.',
            'narrative' => 'Consumption Surge & Anomaly — Explore household consumption surge anomaly detection compared against recurring household baseline.',
            'data_nature' => 'SIMULATED',
        ],
    ];

    public const CANONICAL_OPERATOR = [
        'name' => 'SPMS Demo Operator',
        'email' => 'demo.operator@spms.local',
    ];

    public const SCEN_01_SOLAR_HOURLY_W = [
        0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0,
        6 => 120.0, 7 => 650.0, 8 => 1280.0, 9 => 1850.0, 10 => 2320.0,
        11 => 2680.0, 12 => 2850.0, 13 => 2800.0, 14 => 2510.0, 15 => 1950.0,
        16 => 1250.0, 17 => 520.0, 18 => 80.0, 19 => 0.0, 20 => 0.0,
        21 => 0.0, 22 => 0.0, 23 => 0.0,
    ];

    public const SCEN_02_SOLAR_HOURLY_W = [
        0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0,
        6 => 50.0, 7 => 310.0, 8 => 620.0, 9 => 890.0, 10 => 1120.0,
        11 => 1280.0, 12 => 1350.0, 13 => 1320.0, 14 => 1180.0, 15 => 920.0,
        16 => 580.0, 17 => 240.0, 18 => 30.0, 19 => 0.0, 20 => 0.0,
        21 => 0.0, 22 => 0.0, 23 => 0.0,
    ];

    public const SCEN_03_SOLAR_HOURLY_W = [
        0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0,
        6 => 200.0, 7 => 1100.0, 8 => 2150.0, 9 => 3120.0, 10 => 3920.0,
        11 => 4520.0, 12 => 4800.0, 13 => 4720.0, 14 => 4230.0, 15 => 3280.0,
        16 => 2110.0, 17 => 880.0, 18 => 140.0, 19 => 0.0, 20 => 0.0,
        21 => 0.0, 22 => 0.0, 23 => 0.0,
    ];

    public const SCEN_04_SOLAR_HOURLY_W = [
        0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0,
        6 => 40.0, 7 => 220.0, 8 => 440.0, 9 => 630.0, 10 => 790.0,
        11 => 910.0, 12 => 950.0, 13 => 930.0, 14 => 830.0, 15 => 650.0,
        16 => 410.0, 17 => 170.0, 18 => 20.0, 19 => 0.0, 20 => 0.0,
        21 => 0.0, 22 => 0.0, 23 => 0.0,
    ];

    public const SCEN_05_SOLAR_HOURLY_W = [
        0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0,
        6 => 140.0, 7 => 750.0, 8 => 1450.0, 9 => 2100.0, 10 => 2630.0,
        11 => 3020.0, 12 => 3100.0, 13 => 3050.0, 14 => 2720.0, 15 => 2120.0,
        16 => 1360.0, 17 => 570.0, 18 => 90.0, 19 => 0.0, 20 => 0.0,
        21 => 0.0, 22 => 0.0, 23 => 0.0,
    ];


    /**
     * Seed all D1 scenarios into the active database.
     *
     * @param PDO $pdo
     * @param array<string, string> $passwords Output map of email => password
     * @param (callable(string): void)|null $logger
     * @return array<string, array<string, mixed>> Execution summary per scenario
     */
    public static function seed(PDO $pdo, array &$passwords, ?callable $logger = null): array
    {
        self::assertAllowedEnvironment();

        $log = $logger ?? static function (string $msg): void {
            // no-op
        };

        $balanceService = new EnergyBalanceService();
        $costService = new EnergyCostService();
        $insightEngine = new InsightEngine();
        $insightRepo = new InsightRepository($pdo);
        $recRepo = new RecommendationRepository($pdo);
        $now = new DateTimeImmutable('2026-09-20 12:00:00', new DateTimeZone('UTC'));

        $summary = [];

        // Seed SCEN-01
        $summary['SCEN-01'] = self::seedScen01(
            $pdo, $passwords, $balanceService, $costService, $insightEngine, $insightRepo, $recRepo, $now, $log
        );

        // Seed SCEN-02
        $summary['SCEN-02'] = self::seedScen02(
            $pdo, $passwords, $balanceService, $costService, $insightEngine, $insightRepo, $recRepo, $now, $log
        );

        // Seed SCEN-03
        $summary['SCEN-03'] = self::seedScen03(
            $pdo, $passwords, $balanceService, $costService, $insightEngine, $insightRepo, $recRepo, $now, $log
        );

        // Seed SCEN-04
        $summary['SCEN-04'] = self::seedScen04(
            $pdo, $passwords, $balanceService, $costService, $insightEngine, $insightRepo, $recRepo, $now, $log
        );

        // Seed SCEN-05
        $summary['SCEN-05'] = self::seedScen05(
            $pdo, $passwords, $balanceService, $costService, $insightEngine, $insightRepo, $recRepo, $now, $log
        );

        // Ensure canonical DEMO operator account
        self::ensureCanonicalOperator($pdo, $passwords, $log);

        return $summary;
    }

    /**
     * SCEN-01: Solar Surplus Residence
     */
    private static function seedScen01(
        PDO $pdo,
        array &$passwords,
        EnergyBalanceService $balanceService,
        EnergyCostService $costService,
        InsightEngine $engine,
        InsightRepository $repo,
        RecommendationRepository $recRepo,
        DateTimeImmutable $now,
        callable $log
    ): array {
        $meta = self::SCENARIOS['SCEN-01'];
        $account = self::ensureScenarioAccount($pdo, $meta['email'], $passwords);
        $householdId = self::ensureScenarioHousehold(
            $account,
            $meta['household_label'],
            $meta['service_number'],
            $meta['location'] ?? 'Kodumur / Andhra Pradesh',
            $meta['timezone'] ?? 'Asia/Kolkata'
        );

        // 1 & 2. Baseline verified bill
        self::ensureScenarioBill(
            $pdo, $householdId, $meta['service_number'], 'LT-1(A)', 3.0, 216.0, 1450.0, 1120.0, 150.0, $now
        );

        // 3. Coherent Deterministic Solar Telemetry (72+ hours)
        $solarReadingRepo = self::seedSolarHardwareAndReadings(
            $pdo, $householdId, 'DEMO_STATION_SURPLUS_01', 'GW3000-DNS', $meta['email'],
            self::SCEN_01_SOLAR_HOURLY_W, $now, 'GOOD', false
        );

        // 4. F3 Forecast Generation through real domain service
        $forecastService = new ForecastService();
        $forecastService->generateForecast($householdId, ForecastHorizon::DAY_AHEAD, $now);

        // 5. F4 & F5 Canonical Period Evaluation
        // Midday (12:00-13:00): Solar 2.85 kWh, Consumption 0.28 kWh -> Surplus +2.57 kWh
        $p1 = ['start' => $now->format('Y-m-d') . 'T12:00:00', 'end' => $now->format('Y-m-d') . 'T13:00:00', 'resolution' => 'HOUR'];
        $p1Solar = ['value_kwh' => 2.85, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];
        $p1Use = ['value_kwh' => 0.28, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];

        $p1Balance = $balanceService->classify(['solar' => $p1Solar, 'consumption' => $p1Use], 'TIME_PERIOD', $p1, $householdId);
        $p1Balance['period'] = $p1;
        $p1Cost = $costService->estimate([
            'solar' => $p1Solar,
            'consumption' => $p1Use,
            'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
        ], $p1, $householdId);
        $p1Cost['period'] = $p1;

        // Evening (19:00-20:00): Solar 0.0 kWh, Consumption 0.95 kWh -> Deficit -0.95 kWh
        $p2 = ['start' => $now->format('Y-m-d') . 'T19:00:00', 'end' => $now->format('Y-m-d') . 'T20:00:00', 'resolution' => 'HOUR'];
        $p2Solar = ['value_kwh' => 0.0, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];
        $p2Use = ['value_kwh' => 0.95, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];

        $p2Balance = $balanceService->classify(['solar' => $p2Solar, 'consumption' => $p2Use], 'TIME_PERIOD', $p2, $householdId);
        $p2Balance['period'] = $p2;
        $p2Cost = $costService->estimate([
            'solar' => $p2Solar,
            'consumption' => $p2Use,
            'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
        ], $p2, $householdId);
        $p2Cost['period'] = $p2;

        // Build canonical evidence for F6
        $evidence = InsightEvidenceInput::fromArray([
            'household_id' => $householdId,
            'solar' => [
                ['period' => $p1, 'value_kwh' => 2.85, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'],
                ['period' => $p2, 'value_kwh' => 0.0, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'],
            ],
            'consumption' => [
                ['period' => $p1, 'value_kwh' => 0.28, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'],
                ['period' => $p2, 'value_kwh' => 0.95, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'],
            ],
            'balance' => [$p1Balance, $p2Balance],
            'cost' => [$p1Cost, $p2Cost],
            'quality' => ['overall_status' => 'FRESH', 'overall_quality' => 'GOOD'],
            'provenance' => [
                'sources' => ['SIMULATED_DEMO_SCENARIO_SCEN01'],
                'pipeline' => ['EnergyBalanceService (F4)', 'EnergyCostService (F5)'],
            ],
        ]);

        $insights = $engine->evaluate($evidence, $now);
        $persisted = [];
        foreach ($insights as $insight) {
            $persisted[] = $repo->upsert($insight, $now);
        }

        $log("  [SCEN-01] {$meta['name']}: " . count($persisted) . ' insights generated.');

        // 6. F7 Recommendations Generation via real RecommendationService
        $pdo->prepare('DELETE FROM recommendations WHERE household_id = :hid')->execute([':hid' => $householdId]);
        $recService = new RecommendationService(
            repository: $recRepo,
            forecastService: $forecastService,
            bills: new BillRepository($pdo),
            solarReadings: $solarReadingRepo,
            insightRepo: $repo
        );

        $persistedRecs = $recService->generate($householdId, $now, forceFresh: true);
        $log("  [SCEN-01] {$meta['name']}: " . count($persistedRecs) . ' recommendations generated via real pipeline.');

        return [
            'household_id' => $householdId,
            'account' => $meta['email'],
            'insights_count' => count($persisted),
            'insight_types' => array_map(static fn ($i) => $i->type(), $persisted),
            'recommendations_count' => count($persistedRecs),
            'recommendation_types' => array_map(static fn ($r) => $r->type(), $persistedRecs),
            'f4_classifications' => [$p1Balance['result']['classification'], $p2Balance['result']['classification']],
            'f5_costs' => [$p1Cost['result']['estimated_variable_cost'], $p2Cost['result']['estimated_variable_cost']],
        ];
    }

    /**
     * SCEN-02: Persistent Grid Deficit & High Demand
     */
    private static function seedScen02(
        PDO $pdo,
        array &$passwords,
        EnergyBalanceService $balanceService,
        EnergyCostService $costService,
        InsightEngine $engine,
        InsightRepository $repo,
        RecommendationRepository $recRepo,
        DateTimeImmutable $now,
        callable $log
    ): array {
        $meta = self::SCENARIOS['SCEN-02'];
        $account = self::ensureScenarioAccount($pdo, $meta['email'], $passwords);
        $householdId = self::ensureScenarioHousehold($account, $meta['household_label'], $meta['service_number']);

        // Solar connection & coherent telemetry (GW3000-DNS, peak 1350 W)
        self::ensureScenarioBill(
            $pdo, $householdId, $meta['service_number'], 'LT-1(B)', 3.0, 495.0, 3450.0, 3120.0, 150.0, $now
        );
        self::seedSolarHardwareAndReadings(
            $pdo, $householdId, 'DEMO_STATION_DEFICIT_02', 'GW3000-DNS', $meta['email'],
            self::SCEN_02_SOLAR_HOURLY_W, $now, 'GOOD', false
        );
        $forecastService = new ForecastService();
        $forecastService->generateForecast($householdId, ForecastHorizon::DAY_AHEAD, $now);

        $periodsData = [
            ['start' => '2026-06-01', 'end' => '2026-06-30', 'solar' => 140.0, 'use' => 480.0],
            ['start' => '2026-07-01', 'end' => '2026-07-31', 'solar' => 150.0, 'use' => 510.0],
            ['start' => '2026-08-01', 'end' => '2026-08-31', 'solar' => 135.0, 'use' => 495.0],
        ];

        $solarRecords = [];
        $useRecords = [];
        $balances = [];
        $costs = [];

        foreach ($periodsData as $row) {
            $period = ['start' => $row['start'], 'end' => $row['end'], 'resolution' => 'MONTH'];
            $solarInput = ['value_kwh' => $row['solar'], 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];
            $useInput = ['value_kwh' => $row['use'], 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];

            $solarRecords[] = ['period' => $period, 'value_kwh' => $row['solar'], 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'];
            $useRecords[] = ['period' => $period, 'value_kwh' => $row['use'], 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'];

            $b = $balanceService->classify(['solar' => $solarInput, 'consumption' => $useInput], 'TIME_PERIOD', $period, $householdId);
            $b['period'] = $period;
            $balances[] = $b;

            $c = $costService->estimate([
                'solar' => $solarInput,
                'consumption' => $useInput,
                'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
                'fixed_charge' => ['amount' => 150.0, 'currency' => 'INR', 'label' => 'Standard Fixed Meter Charge'],
            ], $period, $householdId);
            $c['period'] = $period;
            $costs[] = $c;
        }

        $evidence = InsightEvidenceInput::fromArray([
            'household_id' => $householdId,
            'solar' => $solarRecords,
            'consumption' => $useRecords,
            'balance' => $balances,
            'cost' => $costs,
            'quality' => ['overall_status' => 'FRESH', 'overall_quality' => 'GOOD'],
            'provenance' => [
                'sources' => ['SIMULATED_DEMO_SCENARIO_SCEN02'],
                'pipeline' => ['EnergyBalanceService (F4)', 'EnergyCostService (F5)'],
            ],
        ]);

        $insights = $engine->evaluate($evidence, $now);
        $persisted = [];
        foreach ($insights as $insight) {
            $persisted[] = $repo->upsert($insight, $now);
        }

        $log("  [SCEN-02] {$meta['name']}: " . count($persisted) . ' insights generated.');

        // Seed F7 Recommendations for SCEN-02
        $pdo->prepare('DELETE FROM recommendations WHERE household_id = :hid')->execute([':hid' => $householdId]);
        $rec1 = new Recommendation(
            id: self::uuid(),
            householdId: $householdId,
            type: RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW,
            status: Recommendation::STATUS_ACTIVE,
            priority: Recommendation::PRIORITY_HIGH,
            title: 'Curtail High Consumption During Grid Deficit Window',
            summary: 'Recurring structural deficit observed across all afternoon and evening hours.',
            action: 'Postpone discretionary heavy appliance usage during peak grid import periods.',
            reason: 'Solar capacity is insufficient to cover baseline daytime load (average monthly deficit 350 kWh).',
            timingWindow: '14:00 - 21:00',
            potentialImpact: 'Estimated 3.0–5.0 kWh grid import avoidance daily.',
            impactJson: [
                'type' => 'GRID_IMPORT_AVOIDANCE',
                'estimated_daily_avoidance_kwh' => 4.0,
                'wording' => 'Estimated 3.0–5.0 kWh grid import avoidance daily.',
            ],
            evidenceJson: [
                'avg_monthly_deficit_kwh' => 350.0,
            ],
            confidence: RecommendationConfidence::CONFIDENCE_HIGH,
            quality: 'GOOD',
            dataNature: 'SIMULATED',
            provenance: ['SIMULATED_DEMO_SCENARIO_SCEN02', 'EnergyBalanceService (F4)'],
            limitations: [],
            relevantPeriodStart: '2026-08-01',
            relevantPeriodEnd: '2026-08-31',
            validFrom: $now,
            validUntil: $now->modify('+24 hours'),
            createdAt: $now,
            updatedAt: $now
        );
        $rec2 = new Recommendation(
            id: self::uuid(),
            householdId: $householdId,
            type: RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION,
            status: Recommendation::STATUS_ACTIVE,
            priority: Recommendation::PRIORITY_HIGH,
            title: 'Mitigate Recurring High Demand Peaks',
            summary: 'Household demand regularly surges past 4.5 kW, incurring higher telescopic slab charges.',
            action: 'Stagger high-power appliance operations to avoid compounding simultaneous peaks.',
            reason: 'Concurrent heavy loads increase peak demand and elevate monthly energy bill.',
            timingWindow: 'Peak usage hours',
            potentialImpact: 'Expected reduction in maximum demand charges and upper slab tier exposure.',
            impactJson: [
                'type' => 'PEAK_DEMAND_REDUCTION',
                'wording' => 'Expected reduction in maximum demand charges and upper slab tier exposure.',
            ],
            evidenceJson: [
                'peak_demand_kw' => 4.8,
            ],
            confidence: RecommendationConfidence::CONFIDENCE_HIGH,
            quality: 'GOOD',
            dataNature: 'SIMULATED',
            provenance: ['SIMULATED_DEMO_SCENARIO_SCEN02', 'EnergyCostService (F5)'],
            limitations: [],
            relevantPeriodStart: '2026-08-01',
            relevantPeriodEnd: '2026-08-31',
            validFrom: $now,
            validUntil: $now->modify('+24 hours'),
            createdAt: $now,
            updatedAt: $now
        );
        $rec3 = new Recommendation(
            id: self::uuid(),
            householdId: $householdId,
            type: RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT,
            status: Recommendation::STATUS_ACTIVE,
            priority: Recommendation::PRIORITY_MEDIUM,
            title: 'Optimize Usage Against Telescopic Tariff Tiers',
            summary: 'Monthly consumption approaching next higher APSPDCL tariff slab threshold.',
            action: 'Moderate daily discretionary consumption by 1.2 kWh to remain within lower billing tier.',
            reason: 'Crossing 200 kWh/month increases per-unit energy charges across consumption slabs.',
            timingWindow: 'Current billing cycle',
            potentialImpact: 'Estimated ₹180–₹350 monthly billing savings if kept below next slab threshold.',
            impactJson: [
                'type' => 'TARIFF_SLAB_OPTIMIZATION',
                'potential_monthly_saving_inr' => 250.0,
                'wording' => 'Estimated ₹180–₹350 monthly billing savings if kept below next slab threshold.',
            ],
            evidenceJson: [
                'current_slab_units' => 495,
                'slab_threshold' => 500,
            ],
            confidence: RecommendationConfidence::CONFIDENCE_MODERATE,
            quality: 'GOOD',
            dataNature: 'SIMULATED',
            provenance: ['SIMULATED_DEMO_SCENARIO_SCEN02', 'EnergyCostService (F5)'],
            limitations: ['Based on simulated historical billing and standard APSPDCL LT-I domestic tariff.'],
            relevantPeriodStart: '2026-08-01',
            relevantPeriodEnd: '2026-08-31',
            validFrom: $now,
            validUntil: $now->modify('+24 hours'),
            createdAt: $now,
            updatedAt: $now
        );
        $persistedRecs = [$rec1, $rec2, $rec3];
        foreach ($persistedRecs as $rec) {
            $recRepo->save($rec);
        }

        return [
            'household_id' => $householdId,
            'account' => $meta['email'],
            'insights_count' => count($persisted),
            'insight_types' => array_map(static fn ($i) => $i->type(), $persisted),
            'recommendations_count' => count($persistedRecs),
            'recommendation_types' => array_map(static fn ($r) => $r->type(), $persistedRecs),
            'f4_classifications' => array_map(static fn ($b) => $b['result']['classification'], $balances),
            'f5_costs' => array_map(static fn ($c) => $c['result']['estimated_variable_cost'], $costs),
        ];
    }

    /**
     * SCEN-03: Sustained Solar Overproduction & Net Surplus
     */
    private static function seedScen03(
        PDO $pdo,
        array &$passwords,
        EnergyBalanceService $balanceService,
        EnergyCostService $costService,
        InsightEngine $engine,
        InsightRepository $repo,
        RecommendationRepository $recRepo,
        DateTimeImmutable $now,
        callable $log
    ): array {
        $meta = self::SCENARIOS['SCEN-03'];
        $account = self::ensureScenarioAccount($pdo, $meta['email'], $passwords);
        $householdId = self::ensureScenarioHousehold($account, $meta['household_label'], $meta['service_number']);

        // Solar connection & coherent telemetry (GW5000-DNS, peak 4800 W)
        self::ensureScenarioBill(
            $pdo, $householdId, $meta['service_number'], 'LT-1(A)', 5.0, 205.0, 1380.0, 1050.0, 150.0, $now
        );
        self::seedSolarHardwareAndReadings(
            $pdo, $householdId, 'DEMO_STATION_OVERPROD_03', 'GW5000-DNS', $meta['email'],
            self::SCEN_03_SOLAR_HOURLY_W, $now, 'GOOD', false
        );
        $forecastService = new ForecastService();
        $forecastService->generateForecast($householdId, ForecastHorizon::DAY_AHEAD, $now);

        $periodsData = [
            ['start' => '2026-06-01', 'end' => '2026-06-30', 'solar' => 450.0, 'use' => 210.0],
            ['start' => '2026-07-01', 'end' => '2026-07-31', 'solar' => 460.0, 'use' => 220.0],
            ['start' => '2026-08-01', 'end' => '2026-08-31', 'solar' => 440.0, 'use' => 205.0],
        ];

        $solarRecords = [];
        $useRecords = [];
        $balances = [];
        $costs = [];

        foreach ($periodsData as $row) {
            $period = ['start' => $row['start'], 'end' => $row['end'], 'resolution' => 'MONTH'];
            $solarInput = ['value_kwh' => $row['solar'], 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];
            $useInput = ['value_kwh' => $row['use'], 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];

            $solarRecords[] = ['period' => $period, 'value_kwh' => $row['solar'], 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'];
            $useRecords[] = ['period' => $period, 'value_kwh' => $row['use'], 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'];

            $b = $balanceService->classify(['solar' => $solarInput, 'consumption' => $useInput], 'TIME_PERIOD', $period, $householdId);
            $b['period'] = $period;
            $balances[] = $b;

            $c = $costService->estimate([
                'solar' => $solarInput,
                'consumption' => $useInput,
                'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
                'fixed_charge' => ['amount' => 150.0, 'currency' => 'INR', 'label' => 'Standard Fixed Meter Charge'],
            ], $period, $householdId);
            $c['period'] = $period;
            $costs[] = $c;
        }

        $evidence = InsightEvidenceInput::fromArray([
            'household_id' => $householdId,
            'solar' => $solarRecords,
            'consumption' => $useRecords,
            'balance' => $balances,
            'cost' => $costs,
            'quality' => ['overall_status' => 'FRESH', 'overall_quality' => 'GOOD'],
            'provenance' => [
                'sources' => ['SIMULATED_DEMO_SCENARIO_SCEN03'],
                'pipeline' => ['EnergyBalanceService (F4)', 'EnergyCostService (F5)'],
            ],
        ]);

        $insights = $engine->evaluate($evidence, $now);
        $persisted = [];
        foreach ($insights as $insight) {
            $persisted[] = $repo->upsert($insight, $now);
        }

        $log("  [SCEN-03] {$meta['name']}: " . count($persisted) . ' insights generated.');

        // Seed F7 Recommendations for SCEN-03
        $pdo->prepare('DELETE FROM recommendations WHERE household_id = :hid')->execute([':hid' => $householdId]);
        $rec1 = new Recommendation(
            id: self::uuid(),
            householdId: $householdId,
            type: RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR,
            status: Recommendation::STATUS_ACTIVE,
            priority: Recommendation::PRIORITY_MEDIUM,
            title: 'Maximize Self-Consumption of Sustained Solar Surplus',
            summary: 'Generates consistent surplus averaging +2.8 kWh/hr between 10:00 and 15:00.',
            action: 'Utilize free daytime solar for EV charging, heat pump water heating, or thermal storage.',
            reason: 'Solar generation substantially exceeds all current household base loads with low grid export compensation.',
            timingWindow: '10:00 - 15:00 daily',
            potentialImpact: 'Potential 12–15 kWh/day additional solar utilization.',
            impactJson: [
                'type' => 'SOLAR_SELF_CONSUMPTION',
                'potential_daily_utilization_kwh' => 13.5,
                'wording' => 'Potential 12–15 kWh/day additional solar utilization.',
            ],
            evidenceJson: [
                'avg_daily_surplus_kwh' => 14.2,
            ],
            confidence: RecommendationConfidence::CONFIDENCE_HIGH,
            quality: 'GOOD',
            dataNature: 'SIMULATED',
            provenance: ['SIMULATED_DEMO_SCENARIO_SCEN03', 'EnergyBalanceService (F4)'],
            limitations: [],
            relevantPeriodStart: '2026-08-01',
            relevantPeriodEnd: '2026-08-31',
            validFrom: $now,
            validUntil: $now->modify('+24 hours'),
            createdAt: $now,
            updatedAt: $now
        );
        $persistedRecs = [$rec1];
        foreach ($persistedRecs as $rec) {
            $recRepo->save($rec);
        }

        return [
            'household_id' => $householdId,
            'account' => $meta['email'],
            'insights_count' => count($persisted),
            'insight_types' => array_map(static fn ($i) => $i->type(), $persisted),
            'recommendations_count' => count($persistedRecs),
            'recommendation_types' => array_map(static fn ($r) => $r->type(), $persistedRecs),
            'f4_classifications' => array_map(static fn ($b) => $b['result']['classification'], $balances),
            'f5_costs' => array_map(static fn ($c) => $c['result']['estimated_variable_cost'], $costs),
        ];
    }

    /**
     * SCEN-04: Solar Generation Decline & Data Quality Degradation
     */
    private static function seedScen04(
        PDO $pdo,
        array &$passwords,
        EnergyBalanceService $balanceService,
        EnergyCostService $costService,
        InsightEngine $engine,
        InsightRepository $repo,
        RecommendationRepository $recRepo,
        DateTimeImmutable $now,
        callable $log
    ): array {
        $meta = self::SCENARIOS['SCEN-04'];
        $account = self::ensureScenarioAccount($pdo, $meta['email'], $passwords);
        $householdId = self::ensureScenarioHousehold($account, $meta['household_label'], $meta['service_number']);

        // Solar connection & coherent telemetry (GW4000-DNS, peak 950 W, degraded quality & gaps)
        self::ensureScenarioBill(
            $pdo, $householdId, $meta['service_number'], 'LT-1(A)', 4.0, 240.0, 1620.0, 1290.0, 150.0, $now
        );
        self::seedSolarHardwareAndReadings(
            $pdo, $householdId, 'DEMO_STATION_DECLINE_04', 'GW4000-DNS', $meta['email'],
            self::SCEN_04_SOLAR_HOURLY_W, $now, 'DEGRADED', true
        );
        $forecastService = new ForecastService();
        $forecastService->generateForecast($householdId, ForecastHorizon::DAY_AHEAD, $now);

        // Period T1: Fresh, good quality, 380 solar, 220 consumption
        $p1 = ['start' => '2026-07-01', 'end' => '2026-07-31', 'resolution' => 'MONTH'];
        $p1Solar = ['value_kwh' => 380.0, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];
        $p1Use = ['value_kwh' => 220.0, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];

        $b1 = $balanceService->classify(['solar' => $p1Solar, 'consumption' => $p1Use], 'TIME_PERIOD', $p1, $householdId);
        $b1['period'] = $p1;
        $c1 = $costService->estimate([
            'solar' => $p1Solar,
            'consumption' => $p1Use,
            'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
        ], $p1, $householdId);
        $c1['period'] = $p1;

        // Period T2: Degraded, partial quality, 270 solar (-28.9% decline), 230 consumption
        $p2 = ['start' => '2026-08-01', 'end' => '2026-08-31', 'resolution' => 'MONTH'];
        $p2Solar = ['value_kwh' => 270.0, 'nature' => 'SIMULATED', 'status' => 'DEGRADED', 'quality' => 'PARTIAL'];
        $p2Use = ['value_kwh' => 230.0, 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];

        $b2 = $balanceService->classify(['solar' => $p2Solar, 'consumption' => $p2Use], 'TIME_PERIOD', $p2, $householdId);
        $b2['period'] = $p2;
        $c2 = $costService->estimate([
            'solar' => $p2Solar,
            'consumption' => $p2Use,
            'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
        ], $p2, $householdId);
        $c2['period'] = $p2;

        $evidence = InsightEvidenceInput::fromArray([
            'household_id' => $householdId,
            'solar' => [
                ['period' => $p1, 'value_kwh' => 380.0, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'],
                ['period' => $p2, 'value_kwh' => 270.0, 'data_nature' => 'SIMULATED', 'data_status' => 'DEGRADED', 'quality' => 'PARTIAL'],
            ],
            'consumption' => [
                ['period' => $p1, 'value_kwh' => 220.0, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'],
                ['period' => $p2, 'value_kwh' => 230.0, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'],
            ],
            'balance' => [$b1, $b2],
            'cost' => [$c1, $c2],
            'quality' => ['overall_status' => 'DEGRADED', 'overall_quality' => 'PARTIAL'],
            'provenance' => [
                'sources' => ['SIMULATED_DEMO_SCENARIO_SCEN04'],
                'pipeline' => ['EnergyBalanceService (F4)', 'EnergyCostService (F5)'],
            ],
        ]);

        $insights = $engine->evaluate($evidence, $now);
        $persisted = [];
        foreach ($insights as $insight) {
            $persisted[] = $repo->upsert($insight, $now);
        }

        $log("  [SCEN-04] {$meta['name']}: " . count($persisted) . ' insights generated.');

        // Seed F7 Recommendations for SCEN-04
        $pdo->prepare('DELETE FROM recommendations WHERE household_id = :hid')->execute([':hid' => $householdId]);
        $rec1 = new Recommendation(
            id: self::uuid(),
            householdId: $householdId,
            type: RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE,
            status: Recommendation::STATUS_ACTIVE,
            priority: Recommendation::PRIORITY_HIGH,
            title: 'Investigate Solar Generation Decline',
            summary: 'Daily solar yield decreased by 28.9% compared to historical baseline under similar conditions.',
            action: 'Inspect solar array for dust accumulation, shading, panel soiling, or inverter warning indicators.',
            reason: 'Sustained drop from 380 kWh/month to 270 kWh/month observed across consecutive billing periods.',
            timingWindow: 'Morning hours before peak insolation',
            potentialImpact: 'Potential restoration of up to 8–10 kWh/day generation capacity.',
            impactJson: [
                'type' => 'GENERATION_RESTORATION',
                'potential_recovery_kwh_day' => 9.0,
                'wording' => 'Potential restoration of up to 8–10 kWh/day generation capacity.',
            ],
            evidenceJson: [
                'previous_solar_kwh' => 380.0,
                'current_solar_kwh' => 270.0,
                'decline_pct' => 28.9,
            ],
            confidence: RecommendationConfidence::CONFIDENCE_LOW,
            quality: 'DEGRADED',
            dataNature: 'SIMULATED',
            provenance: ['SIMULATED_DEMO_SCENARIO_SCEN04', 'SolarReadingRepository', 'InsightEngine (F6)'],
            limitations: ['Inverter telemetry data contains intermittent gaps; confidence downgraded to LOW.'],
            relevantPeriodStart: '2026-08-01',
            relevantPeriodEnd: '2026-08-31',
            validFrom: $now,
            validUntil: $now->modify('+24 hours'),
            createdAt: $now,
            updatedAt: $now
        );
        $rec2 = new Recommendation(
            id: self::uuid(),
            householdId: $householdId,
            type: RecommendationTaxonomy::TYPE_DATA_LIMITATION,
            status: Recommendation::STATUS_ACTIVE,
            priority: Recommendation::PRIORITY_MEDIUM,
            title: 'Inverter Telemetry Degraded — Recommendations Constrained',
            summary: 'Telemetry gaps detected in solar generation records for the evaluation period.',
            action: 'Check inverter Wi-Fi / RS485 communication link to restore automated recommendation accuracy.',
            reason: 'Missing interval data impairs reliable surplus/deficit forecasting and savings estimation.',
            timingWindow: 'Immediate',
            potentialImpact: null,
            impactJson: null,
            evidenceJson: [
                'degraded_status' => 'DEGRADED',
                'data_quality' => 'PARTIAL',
            ],
            confidence: RecommendationConfidence::CONFIDENCE_LOW,
            quality: 'DEGRADED',
            dataNature: 'SIMULATED',
            provenance: ['SIMULATED_DEMO_SCENARIO_SCEN04', 'DataQualityChecker'],
            limitations: ['Advisory models operating in degraded mode; numerical savings estimates suppressed.'],
            relevantPeriodStart: '2026-08-01',
            relevantPeriodEnd: '2026-08-31',
            validFrom: $now,
            validUntil: $now->modify('+24 hours'),
            createdAt: $now,
            updatedAt: $now
        );
        $persistedRecs = [$rec1, $rec2];
        foreach ($persistedRecs as $rec) {
            $recRepo->save($rec);
        }

        return [
            'household_id' => $householdId,
            'account' => $meta['email'],
            'insights_count' => count($persisted),
            'insight_types' => array_map(static fn ($i) => $i->type(), $persisted),
            'recommendations_count' => count($persistedRecs),
            'recommendation_types' => array_map(static fn ($r) => $r->type(), $persistedRecs),
            'f4_classifications' => [$b1['result']['classification'], $b2['result']['classification']],
            'f5_costs' => [$c1['result']['estimated_variable_cost'], $c2['result']['estimated_variable_cost']],
        ];
    }

    /**
     * SCEN-05: Sudden Consumption Surge & Anomaly Detection
     */
    private static function seedScen05(
        PDO $pdo,
        array &$passwords,
        EnergyBalanceService $balanceService,
        EnergyCostService $costService,
        InsightEngine $engine,
        InsightRepository $repo,
        RecommendationRepository $recRepo,
        DateTimeImmutable $now,
        callable $log
    ): array {
        $meta = self::SCENARIOS['SCEN-05'];
        $account = self::ensureScenarioAccount($pdo, $meta['email'], $passwords);
        $householdId = self::ensureScenarioHousehold($account, $meta['household_label'], $meta['service_number']);

        // Solar connection & coherent telemetry (GW3600-DNS, peak 3100 W)
        self::ensureScenarioBill(
            $pdo, $householdId, $meta['service_number'], 'LT-1(B)', 3.5, 330.0, 2290.0, 1960.0, 150.0, $now
        );
        self::seedSolarHardwareAndReadings(
            $pdo, $householdId, 'DEMO_STATION_SURGE_05', 'GW3600-DNS', $meta['email'],
            self::SCEN_05_SOLAR_HOURLY_W, $now, 'GOOD', false
        );
        $forecastService = new ForecastService();
        $forecastService->generateForecast($householdId, ForecastHorizon::DAY_AHEAD, $now);

        // Baseline: 195 kWh, 210 kWh, 225 kWh (mean = 210 kWh).
        // Current: 330 kWh (+57.1% deviation from baseline, +46.7% vs previous).
        $periodsData = [
            ['start' => '2026-05-01', 'end' => '2026-05-31', 'solar' => 100.0, 'use' => 195.0],
            ['start' => '2026-06-01', 'end' => '2026-06-30', 'solar' => 105.0, 'use' => 210.0],
            ['start' => '2026-07-01', 'end' => '2026-07-31', 'solar' => 100.0, 'use' => 225.0],
            ['start' => '2026-08-01', 'end' => '2026-08-31', 'solar' => 100.0, 'use' => 330.0],
        ];

        $solarRecords = [];
        $useRecords = [];
        $balances = [];
        $costs = [];

        foreach ($periodsData as $row) {
            $period = ['start' => $row['start'], 'end' => $row['end'], 'resolution' => 'MONTH'];
            $solarInput = ['value_kwh' => $row['solar'], 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];
            $useInput = ['value_kwh' => $row['use'], 'nature' => 'SIMULATED', 'status' => 'FRESH', 'quality' => 'GOOD'];

            $solarRecords[] = ['period' => $period, 'value_kwh' => $row['solar'], 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'];
            $useRecords[] = ['period' => $period, 'value_kwh' => $row['use'], 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'quality' => 'GOOD'];

            $b = $balanceService->classify(['solar' => $solarInput, 'consumption' => $useInput], 'TIME_PERIOD', $period, $householdId);
            $b['period'] = $period;
            $balances[] = $b;

            $c = $costService->estimate([
                'solar' => $solarInput,
                'consumption' => $useInput,
                'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
            ], $period, $householdId);
            $c['period'] = $period;
            $costs[] = $c;
        }

        $evidence = InsightEvidenceInput::fromArray([
            'household_id' => $householdId,
            'solar' => $solarRecords,
            'consumption' => $useRecords,
            'balance' => $balances,
            'cost' => $costs,
            'quality' => ['overall_status' => 'FRESH', 'overall_quality' => 'GOOD'],
            'provenance' => [
                'sources' => ['SIMULATED_DEMO_SCENARIO_SCEN05'],
                'pipeline' => ['EnergyBalanceService (F4)', 'EnergyCostService (F5)'],
            ],
        ]);

        $insights = $engine->evaluate($evidence, $now);
        $persisted = [];
        foreach ($insights as $insight) {
            $persisted[] = $repo->upsert($insight, $now);
        }

        $log("  [SCEN-05] {$meta['name']}: " . count($persisted) . ' insights generated.');

        // Seed F7 Recommendations for SCEN-05
        $pdo->prepare('DELETE FROM recommendations WHERE household_id = :hid')->execute([':hid' => $householdId]);
        $rec1 = new Recommendation(
            id: self::uuid(),
            householdId: $householdId,
            type: RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION,
            status: Recommendation::STATUS_ACTIVE,
            priority: Recommendation::PRIORITY_HIGH,
            title: 'Investigate Sudden Consumption Surge',
            summary: 'Unusual consumption surge of 330 kWh observed (baseline: 210 kWh).',
            action: 'Check for unintended appliance activation, continuous water heater run, or equipment faults.',
            reason: 'Monthly consumption spiked by +57.1% above the established 3-month baseline.',
            timingWindow: 'Immediate',
            potentialImpact: 'Potential prevention of ₹800–₹1,200 excess tariff charges if resolved.',
            impactJson: [
                'type' => 'SURGE_AVOIDANCE',
                'potential_cost_avoidance_inr' => 900.0,
                'wording' => 'Potential prevention of ₹800–₹1,200 excess tariff charges if resolved.',
            ],
            evidenceJson: [
                'baseline_kwh' => 210.0,
                'current_kwh' => 330.0,
                'surge_pct' => 57.1,
            ],
            confidence: RecommendationConfidence::CONFIDENCE_HIGH,
            quality: 'GOOD',
            dataNature: 'SIMULATED',
            provenance: ['SIMULATED_DEMO_SCENARIO_SCEN05', 'InsightEngine (F6)'],
            limitations: [],
            relevantPeriodStart: '2026-08-01',
            relevantPeriodEnd: '2026-08-31',
            validFrom: $now,
            validUntil: $now->modify('+24 hours'),
            createdAt: $now,
            updatedAt: $now
        );
        $persistedRecs = [$rec1];
        foreach ($persistedRecs as $rec) {
            $recRepo->save($rec);
        }

        return [
            'household_id' => $householdId,
            'account' => $meta['email'],
            'insights_count' => count($persisted),
            'insight_types' => array_map(static fn ($i) => $i->type(), $persisted),
            'recommendations_count' => count($persistedRecs),
            'recommendation_types' => array_map(static fn ($r) => $r->type(), $persistedRecs),
            'f4_classifications' => array_map(static fn ($b) => $b['result']['classification'], $balances),
            'f5_costs' => array_map(static fn ($c) => $c['result']['estimated_variable_cost'], $costs),
        ];
    }

    public static function resolveScenarioPassword(string $email): string
    {
        if ($email === 'demo.operator@spms.local' || $email === (string) Config::get('DEMO_OPERATOR_EMAIL')) {
            return (string) (Config::get('DEMO_OPERATOR_PASSWORD') ?: 'DemoOperator2026!');
        }

        $scenarioDefaults = [
            '01' => ['email' => 'demo.surplus@spms.local', 'password' => 'SolarSurplus2026!'],
            '02' => ['email' => 'demo.deficit@spms.local', 'password' => 'EveningDeficit2026!'],
            '03' => ['email' => 'demo.overproduction@spms.local', 'password' => 'Overproduction2026!'],
            '04' => ['email' => 'demo.decline@spms.local', 'password' => 'SolarDecline2026!'],
            '05' => ['email' => 'demo.surge@spms.local', 'password' => 'DemandSurge2026!'],
        ];

        foreach ($scenarioDefaults as $num => $defaults) {
            $configuredEmail = (string) (Config::get("DEMO_SCENARIO_{$num}_EMAIL") ?: $defaults['email']);
            // Also accept legacy alias demo.scen0X@demo.spms.local
            if ($email === $configuredEmail || $email === "demo.scen{$num}@demo.spms.local") {
                return (string) (Config::get("DEMO_SCENARIO_{$num}_PASSWORD") ?: $defaults['password']);
            }
        }

        return 'Demo' . substr(hash('sha256', $email), 0, 10) . 'Aa1!';
    }

    /**
     * Ensures canonical operator account exists with DEMO_TEST mode.
     */
    public static function ensureCanonicalOperator(PDO $pdo, array &$passwords, ?callable $logger = null): Account
    {
        $log = $logger ?? static function (string $msg): void {};
        $email = (string) (Config::get('DEMO_OPERATOR_EMAIL') ?: self::CANONICAL_OPERATOR['email']);
        $account = self::ensureScenarioAccount($pdo, $email, $passwords);

        // In demo environment (spms_demo), ensure operator account is initially bound to SCEN-01 household
        if (Config::get('SPMS_DATA_ENVIRONMENT') === DataEnvironments::DEMO) {
            $scen01Stmt = $pdo->prepare("SELECT id FROM households WHERE service_number = 'DEMO-1000-2000-01' OR REPLACE(service_number, '-', '') = 'DEMO1000200001' LIMIT 1");
            $scen01Stmt->execute();
            $scen01HouseholdId = $scen01Stmt->fetchColumn();
            if ($scen01HouseholdId !== false) {
                $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                $pdo->prepare('DELETE FROM household_memberships WHERE account_id = :acc_id')->execute([':acc_id' => $account->id()]);
                $pdo->prepare('INSERT INTO household_memberships (id, household_id, account_id, role, created_at, updated_at) VALUES (:id, :hh_id, :acc_id, :role, :created_at, :updated_at)')
                    ->execute([
                        ':id' => self::uuid(),
                        ':hh_id' => $scen01HouseholdId,
                        ':acc_id' => $account->id(),
                        ':role' => 'OWNER',
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]);
                $log("  [Canonical Operator] {$email} bound to SCEN-01 household.");
            }
        }

        return $account;
    }

    /**
     * Seeds solar hardware connection and deterministic hourly readings.
     */
    private static function seedSolarHardwareAndReadings(
        PDO $pdo,
        string $householdId,
        string $stationId,
        string $inverterSn,
        string $email,
        array $hourlySolarW,
        DateTimeImmutable $now,
        string $quality = 'GOOD',
        bool $hasIntermittentGaps = false
    ): SolarReadingRepository {
        $solarConnRepo = new SolarConnectionRepository($pdo);
        $solarConnRepo->upsert(
            id: self::uuid(),
            householdId: $householdId,
            provider: 'GOODWE_SEMS',
            stationId: $stationId,
            inverterSn: $inverterSn,
            accountIdentifier: $email,
            encryptedCredentials: 'DEMO_ENCRYPTED_SEMS_CREDENTIALS',
            connectionStatus: 'CONNECTED'
        );

        $pdo->prepare('DELETE FROM solar_readings WHERE household_id = :hid')->execute([':hid' => $householdId]);
        $solarReadingRepo = new SolarReadingRepository($pdo);
        $syncBatchId = self::uuid();

        $startDate = $now->modify('-3 days')->setTime(0, 0, 0);
        $endDate = $now;

        $cursor = $startDate;
        $dailyGenKwh = 0.0;
        $currentDayStr = '';

        while ($cursor <= $endDate) {
            $dayStr = $cursor->format('Y-m-d');
            if ($dayStr !== $currentDayStr) {
                $currentDayStr = $dayStr;
                $dailyGenKwh = 0.0;
            }

            $hour = (int) $cursor->format('G');

            if ($hasIntermittentGaps && in_array($hour, [10, 13], true)) {
                $cursor = $cursor->modify('+1 hour');
                continue;
            }

            $powerW = $hourlySolarW[$hour] ?? 0.0;
            $hourlyKwh = $powerW / 1000.0;
            $dailyGenKwh += $hourlyKwh;

            $readingSnapshot = new NormalizedSolarSnapshot(
                stationId: $stationId,
                inverterSn: $inverterSn,
                measurementTime: $cursor,
                receivedAt: $cursor->modify('+2 minutes'),
                powerW: $powerW,
                dailyGenerationKwh: round($dailyGenKwh, 3),
                totalGenerationKwh: round(1250.0 + $dailyGenKwh, 3),
                pvPowerW: $powerW > 0 ? $powerW + 25.0 : 0.0,
                acVoltageV: 232.5,
                acCurrentA: $powerW > 0 ? round($powerW / 230.0, 2) : 0.0,
                acFrequencyHz: 50.02,
                temperatureC: $powerW > 0 ? 38.5 : 26.0,
                gridPowerW: null,
                consumptionPowerW: null,
                inverterStatus: $powerW > 0 ? 'NORMAL' : 'WAITING',
                dataNature: 'SIMULATED',
                dataStatus: 'FRESH',
                dataQuality: $quality
            );

            $solarReadingRepo->insert(
                id: self::uuid(),
                householdId: $householdId,
                snapshot: $readingSnapshot,
                syncBatchId: $syncBatchId
            );

            $cursor = $cursor->modify('+1 hour');
        }

        return $solarReadingRepo;
    }

    /**
     * Idempotently seeds an authoritative baseline bill for a scenario.
     */
    private static function ensureScenarioBill(
        PDO $pdo,
        string $householdId,
        string $serviceNumber,
        string $tariffCategory,
        float $contractedLoad,
        float $consumptionKwh,
        float $totalDue,
        float $energyCharges,
        float $fixedCharges,
        DateTimeImmutable $now
    ): void {
        $existing = $pdo->prepare("SELECT id FROM bills WHERE household_id = :hid AND status = 'VERIFIED' LIMIT 1");
        $existing->execute([':hid' => $householdId]);
        if (!$existing->fetchColumn()) {
            $billId = self::uuid();
            $pdo->prepare(<<<'SQL'
                INSERT INTO bills (
                    id, household_id, status, source, service_number, tariff_category,
                    contracted_load, billing_period_start, billing_period_end, consumption_kwh,
                    bill_amount, total_due, energy_charges, fixed_charges, customer_service_charges, taxes_adjustments,
                    verified_at, created_at, updated_at
                ) VALUES (
                    :id, :household_id, 'VERIFIED', 'MANUAL_ENTRY', :service_number, :tariff,
                    :load, '2026-08-01', '2026-08-31', :kwh,
                    :bill_amount, :total, :energy, :fixed, 60.00, 120.00,
                    :verified_at, :created_at, :updated_at
                )
            SQL)->execute([
                ':id' => $billId,
                ':household_id' => $householdId,
                ':service_number' => $serviceNumber,
                ':tariff' => $tariffCategory,
                ':load' => $contractedLoad,
                ':kwh' => $consumptionKwh,
                ':bill_amount' => $totalDue,
                ':total' => $totalDue,
                ':energy' => $energyCharges,
                ':fixed' => $fixedCharges,
                ':verified_at' => $now->modify('-15 days')->format('Y-m-d H:i:s'),
                ':created_at' => $now->modify('-20 days')->format('Y-m-d H:i:s'),
                ':updated_at' => $now->modify('-15 days')->format('Y-m-d H:i:s'),
            ]);
        }
    }


    /**
     * Ensures an account exists in the active database with DEMO_TEST mode.
     */
    private static function ensureScenarioAccount(PDO $pdo, string $email, array &$passwords): Account
    {
        $password = self::resolveScenarioPassword($email);
        $passwords[$email] = $password;

        $existing = $pdo->prepare('SELECT * FROM accounts WHERE email = :email');
        $existing->execute(['email' => $email]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            $pdo->prepare("UPDATE accounts SET password_hash = :hash, mode = 'DEMO_TEST', status = 'ACTIVE' WHERE id = :id")
                ->execute([
                    ':hash' => PasswordPolicy::hash($password),
                    ':id' => $row['id'],
                ]);

            $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = :id');
            $stmt->execute(['id' => $row['id']]);
            return Account::fromStorageRow($stmt->fetch(PDO::FETCH_ASSOC));
        }

        $service = new AccountService();
        $regResult = $service->register($email, $password, '10.255.' . random_int(1, 250) . '.' . random_int(1, 250));
        $account = $regResult['account'];

        $dir = (string) (Config::get('MAIL_FILE_DIR') ?? (dirname(__DIR__, 2) . '/storage/mail'));
        if (!is_dir($dir)) {
            $dir = dirname(__DIR__, 2) . '/storage/mail';
        }
        $files = glob($dir . '/*.eml') ?: [];
        sort($files);
        $token = null;
        foreach (array_reverse($files) as $file) {
            $content = (string) file_get_contents($file);
            if (str_contains($content, 'Verify your email') && str_contains($content, $email) && preg_match('/\b(\d{6})\b/', $content, $m) === 1) {
                $token = $m[1];
                break;
            }
        }
        if ($token !== null) {
            try {
                $service->verifyEmail($token);
            } catch (\Throwable) {
                // Ignore if already verified
            }
        }

        // Server-side DEMO_TEST mode and ACTIVE status assignment
        $pdo->prepare("UPDATE accounts SET mode = 'DEMO_TEST', status = 'ACTIVE', email_verified_at = IFNULL(email_verified_at, NOW()) WHERE id = :id")
            ->execute(['id' => $account->id()]);

        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = :id');
        $stmt->execute(['id' => $account->id()]);
        return Account::fromStorageRow($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Ensures a scenario household exists with the given label and service number.
     */
    private static function ensureScenarioHousehold(
        Account $account,
        string $label,
        string $serviceNumber,
        string $location = 'Kodumur / Andhra Pradesh',
        string $timezone = 'Asia/Kolkata'
    ): string {
        $pdo = Database::connection();
        $cleanSn = str_replace('-', '', $serviceNumber);
        $stmt = $pdo->prepare('SELECT id FROM households WHERE service_number = :sn OR REPLACE(service_number, \'-\', \'\') = :clean_sn OR label = :label LIMIT 1');
        $stmt->execute([':sn' => $serviceNumber, ':clean_sn' => $cleanSn, ':label' => $label]);
        $hhId = $stmt->fetchColumn();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');

        if ($hhId !== false) {
            $pdo->prepare('UPDATE households SET label = :label, location_label = :loc, timezone = :tz WHERE id = :id')
                ->execute(['label' => $label, 'loc' => $location, 'tz' => $timezone, 'id' => (string) $hhId]);

            // Ensure this scenario account is the single owner of this scenario household
            $pdo->prepare('DELETE FROM household_memberships WHERE account_id = :aid')->execute([':aid' => $account->id()]);
            $pdo->prepare('DELETE FROM household_memberships WHERE household_id = :hid')->execute([':hid' => (string) $hhId]);

            $pdo->prepare('INSERT INTO household_memberships (id, household_id, account_id, role, created_at, updated_at) VALUES (:id, :hid, :aid, :role, :created, :updated)')
                ->execute([
                    ':id' => self::uuid(),
                    ':hid' => (string) $hhId,
                    ':aid' => $account->id(),
                    ':role' => 'OWNER',
                    ':created' => $nowStr,
                    ':updated' => $nowStr,
                ]);

            return (string) $hhId;
        }

        $hh = new HouseholdService();
        $created = $hh->createForAccount($account, $label, $timezone, $location, $now, $serviceNumber);

        return $created['household']->id();
    }

    /**
     * Strict environment assertion: Seeding is only permitted in 'demo' or 'test'.
     * Fails closed if run against 'real' or targeting 'spms_real'.
     */
    public static function assertAllowedEnvironment(): void
    {
        $env = (string) Config::get('SPMS_DATA_ENVIRONMENT');
        $db = (string) Config::get('DB_DATABASE');

        if (!in_array($env, [DataEnvironments::DEMO, DataEnvironments::TEST], true)) {
            throw new AppException(
                'INVALID_ENVIRONMENT',
                "Refusing: Demo scenario seeding is only permitted in 'demo' or 'test' environment (got '{$env}').",
                403
            );
        }

        if ($db === 'spms_real' || str_contains($db, 'real')) {
            throw new AppException(
                'SECURITY_VIOLATION',
                "Refusing: Target database cannot be '{$db}'. Demo scenario seeding cannot touch real data.",
                403
            );
        }
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
