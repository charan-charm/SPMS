<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Domain\Account\Account;
use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Household\HouseholdService;
use App\Domain\Intelligence\InsightTaxonomy;
use App\Repositories\InsightRepository;
use App\Support\AppException;
use App\Support\Config;
use App\Support\Database;
use Database\Seeders\DemoBillScenarioSeeder;
use Database\Seeders\DemoScenarioSeeder;
use PDO;
use Tests\Support\Assert;
use Tests\TestResult;

require_once dirname(__DIR__, 2) . '/database/seeders/DemoBillScenarioSeeder.php';
require_once dirname(__DIR__, 2) . '/database/seeders/DemoScenarioSeeder.php';

/**
 * SPMS D1 — Automated verification of DEMO scenario seeding & intelligence pipelines.
 */
final class DemoScenarioWorkflowTest
{
    public static function run(): array
    {
        return [
            TestResult::check('DemoScenarioSeeder rejects REAL environment and spms_real database', function (): void {
                // Test environment assertion under simulated 'real'
                Config::overrideForTesting(['SPMS_DATA_ENVIRONMENT' => 'real']);
                try {
                    Assert::throws(AppException::class, static function (): void {
                        DemoScenarioSeeder::assertAllowedEnvironment();
                    });
                } finally {
                    Config::overrideForTesting(['SPMS_DATA_ENVIRONMENT' => 'test']);
                }

                // Test database name guard
                Config::overrideForTesting(['DB_DATABASE' => 'spms_real']);
                try {
                    Assert::throws(AppException::class, static function (): void {
                        DemoScenarioSeeder::assertAllowedEnvironment();
                    });
                } finally {
                    Config::overrideForTesting(['DB_DATABASE' => 'spms_test']);
                }
            }),

            TestResult::check('DemoScenarioSeeder seeds SCEN-01 through SCEN-05 deterministically', function (): void {
                $pdo = Database::connection();
                $passwords = [];

                $summary = DemoScenarioSeeder::seed($pdo, $passwords);

                Assert::same(5, count($summary), 'All 5 scenarios must be seeded');
                Assert::true(isset($summary['SCEN-01'], $summary['SCEN-02'], $summary['SCEN-03'], $summary['SCEN-04'], $summary['SCEN-05']));

                // Verify accounts were created with DEMO_TEST mode
                foreach (DemoScenarioSeeder::SCENARIOS as $meta) {
                    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE email = :email');
                    $stmt->execute(['email' => $meta['email']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    Assert::true($row !== false, "Account {$meta['email']} must exist");
                    Assert::same('DEMO_TEST', $row['mode'], "Account {$meta['email']} must be in DEMO_TEST mode");
                    Assert::same('ACTIVE', $row['status'], "Account {$meta['email']} must be ACTIVE");
                    Assert::true(isset($passwords[$meta['email']]), "Password must be populated for {$meta['email']}");
                }
            }),

            TestResult::check('Scenario households are strictly isolated with dedicated identities', function (): void {
                $pdo = Database::connection();
                $householdIds = [];

                foreach (DemoScenarioSeeder::SCENARIOS as $meta) {
                    $stmt = $pdo->prepare('SELECT id FROM households WHERE label = :label');
                    $stmt->execute(['label' => $meta['household_label']]);
                    $hhId = $stmt->fetchColumn();

                    Assert::true($hhId !== false, "Household {$meta['household_label']} must exist");
                    Assert::false(in_array($hhId, $householdIds, true), "Household ID {$hhId} must be unique per scenario");
                    $householdIds[$meta['id']] = $hhId;

                    // Verify membership
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM household_memberships WHERE household_id = :hid AND role = \'OWNER\'');
                    $stmt->execute(['hid' => $hhId]);
                    Assert::same(1, (int) $stmt->fetchColumn(), "Household must have exactly 1 OWNER membership");
                }
            }),

            TestResult::check('SCEN-01 generates SOLAR_DEMAND_MISMATCH and DAYTIME_SURPLUS_EVENING_DEFICIT', function (): void {
                $pdo = Database::connection();
                $repo = new InsightRepository($pdo);
                $meta = DemoScenarioSeeder::SCENARIOS['SCEN-01'];

                $stmt = $pdo->prepare('SELECT id FROM households WHERE label = :label');
                $stmt->execute(['label' => $meta['household_label']]);
                $householdId = (string) $stmt->fetchColumn();

                $insights = $repo->list(['household_id' => $householdId]);
                $types = array_map(static fn ($i) => $i->type(), $insights);

                Assert::true(in_array(InsightTaxonomy::TYPE_SOLAR_DEMAND_MISMATCH, $types, true), 'SCEN-01 must produce SOLAR_DEMAND_MISMATCH');
                Assert::true(in_array(InsightTaxonomy::TYPE_DAYTIME_SURPLUS_EVENING_DEFICIT, $types, true), 'SCEN-01 must produce DAYTIME_SURPLUS_EVENING_DEFICIT');
                Assert::true(in_array(InsightTaxonomy::TYPE_DATA_LIMITATION, $types, true), 'SCEN-01 must produce DATA_LIMITATION (simulated data)');

                foreach ($insights as $insight) {
                    Assert::same('SIMULATED', $insight->dataNature() ?? 'SIMULATED', 'All insights must preserve SIMULATED nature');
                }
            }),

            TestResult::check('SCEN-02 generates RECURRING_POTENTIAL_DEFICIT, RECURRING_HIGH_DEMAND, and COST_IMPACT_PATTERN', function (): void {
                $pdo = Database::connection();
                $repo = new InsightRepository($pdo);
                $meta = DemoScenarioSeeder::SCENARIOS['SCEN-02'];

                $stmt = $pdo->prepare('SELECT id FROM households WHERE label = :label');
                $stmt->execute(['label' => $meta['household_label']]);
                $householdId = (string) $stmt->fetchColumn();

                $insights = $repo->list(['household_id' => $householdId]);
                $types = array_map(static fn ($i) => $i->type(), $insights);

                Assert::true(in_array(InsightTaxonomy::TYPE_RECURRING_POTENTIAL_DEFICIT, $types, true), 'SCEN-02 must produce RECURRING_POTENTIAL_DEFICIT');
                Assert::true(in_array(InsightTaxonomy::TYPE_RECURRING_HIGH_DEMAND, $types, true), 'SCEN-02 must produce RECURRING_HIGH_DEMAND');
                Assert::true(in_array(InsightTaxonomy::TYPE_COST_IMPACT_PATTERN, $types, true), 'SCEN-02 must produce COST_IMPACT_PATTERN');
            }),

            TestResult::check('SCEN-03 generates RECURRING_POTENTIAL_SURPLUS and RECURRING_SOLAR_PATTERN', function (): void {
                $pdo = Database::connection();
                $repo = new InsightRepository($pdo);
                $meta = DemoScenarioSeeder::SCENARIOS['SCEN-03'];

                $stmt = $pdo->prepare('SELECT id FROM households WHERE label = :label');
                $stmt->execute(['label' => $meta['household_label']]);
                $householdId = (string) $stmt->fetchColumn();

                $insights = $repo->list(['household_id' => $householdId]);
                $types = array_map(static fn ($i) => $i->type(), $insights);

                Assert::true(in_array(InsightTaxonomy::TYPE_RECURRING_POTENTIAL_SURPLUS, $types, true), 'SCEN-03 must produce RECURRING_POTENTIAL_SURPLUS');
                Assert::true(in_array(InsightTaxonomy::TYPE_RECURRING_SOLAR_PATTERN, $types, true), 'SCEN-03 must produce RECURRING_SOLAR_PATTERN');
            }),

            TestResult::check('SCEN-04 generates SOLAR_PRODUCTION_DECLINE and degraded DATA_LIMITATION', function (): void {
                $pdo = Database::connection();
                $repo = new InsightRepository($pdo);
                $meta = DemoScenarioSeeder::SCENARIOS['SCEN-04'];

                $stmt = $pdo->prepare('SELECT id FROM households WHERE label = :label');
                $stmt->execute(['label' => $meta['household_label']]);
                $householdId = (string) $stmt->fetchColumn();

                $insights = $repo->list(['household_id' => $householdId]);
                $types = array_map(static fn ($i) => $i->type(), $insights);

                Assert::true(in_array(InsightTaxonomy::TYPE_SOLAR_PRODUCTION_DECLINE, $types, true), 'SCEN-04 must produce SOLAR_PRODUCTION_DECLINE');
                Assert::true(in_array(InsightTaxonomy::TYPE_DATA_LIMITATION, $types, true), 'SCEN-04 must produce DATA_LIMITATION');

                $limitationInsight = null;
                foreach ($insights as $insight) {
                    if ($insight->type() === InsightTaxonomy::TYPE_DATA_LIMITATION) {
                        $limitationInsight = $insight;
                        break;
                    }
                }
                Assert::true($limitationInsight !== null);
                Assert::same('DEGRADED', $limitationInsight->dataStatus(), 'Data status must propagate as DEGRADED via worst-of');
            }),

            TestResult::check('SCEN-05 generates UNUSUAL_CONSUMPTION_PATTERN, CONSUMPTION_INCREASE, and COST_INCREASE', function (): void {
                $pdo = Database::connection();
                $repo = new InsightRepository($pdo);
                $meta = DemoScenarioSeeder::SCENARIOS['SCEN-05'];

                $stmt = $pdo->prepare('SELECT id FROM households WHERE label = :label');
                $stmt->execute(['label' => $meta['household_label']]);
                $householdId = (string) $stmt->fetchColumn();

                $insights = $repo->list(['household_id' => $householdId]);
                $types = array_map(static fn ($i) => $i->type(), $insights);

                Assert::true(in_array(InsightTaxonomy::TYPE_UNUSUAL_CONSUMPTION_PATTERN, $types, true), 'SCEN-05 must produce UNUSUAL_CONSUMPTION_PATTERN');
                Assert::true(in_array(InsightTaxonomy::TYPE_CONSUMPTION_INCREASE, $types, true), 'SCEN-05 must produce CONSUMPTION_INCREASE');
                Assert::true(in_array(InsightTaxonomy::TYPE_COST_INCREASE, $types, true), 'SCEN-05 must produce COST_INCREASE');
            }),

            TestResult::check('DemoScenarioSeeder is idempotent on subsequent runs', function (): void {
                $pdo = Database::connection();
                $passwords = [];

                $firstRunCounts = (int) $pdo->query('SELECT COUNT(*) FROM insights')->fetchColumn();
                Assert::true($firstRunCounts > 0);

                // Run seeder a second time
                $summary = DemoScenarioSeeder::seed($pdo, $passwords);
                Assert::same(5, count($summary));

                $secondRunCounts = (int) $pdo->query('SELECT COUNT(*) FROM insights')->fetchColumn();
                Assert::same($firstRunCounts, $secondRunCounts, 'Insight counts must not duplicate on re-run (dedup unique constraint)');
            }),

            TestResult::check('DemoBillScenarioSeeder preserves all DEMO-01 through DEMO-08 bill scenarios', function (): void {
                $pdo = Database::connection();
                $passwords = [];

                $billScenarios = DemoBillScenarioSeeder::seed($pdo, $passwords);

                Assert::same(8, count($billScenarios), 'All 8 bill scenarios must be produced');
                Assert::true(isset($billScenarios['DEMO-01 normal valid (service match, all PASS)']));
                Assert::true(isset($billScenarios['DEMO-02 net conflict (+177 vs expected -177)']));
                Assert::true(isset($billScenarios['DEMO-03 missing solar (NOT_CHECKED)']));
                Assert::true(isset($billScenarios['DEMO-04 wrong document (NOT_A_BILL)']));
                Assert::true(isset($billScenarios['DEMO-05 service mismatch (foreign number)']));
                Assert::true(isset($billScenarios['DEMO-06 unreadable document']));
                Assert::true(isset($billScenarios['DEMO-07 low-data household (limitations bill)']));
                Assert::true(isset($billScenarios['DEMO-08 reserved household (zero bills)']));

                // Confirm no seeded demo bill is VERIFIED (verification is user-driven B5 territory)
                foreach ($billScenarios as $name => $info) {
                    if (isset($info['bill_id']) && $info['bill_id'] !== '-') {
                        $stmt = $pdo->prepare('SELECT status FROM bills WHERE id = :id');
                        $stmt->execute(['id' => $info['bill_id']]);
                        $st = $stmt->fetchColumn();
                        Assert::same(false, $st === 'VERIFIED', "Seeded scenario '{$name}' must not produce a VERIFIED bill (B5 territory)");
                    }
                }
            }),
        ];
    }
}
