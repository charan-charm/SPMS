<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\RecommendationController;
use App\Domain\Recommendation\Recommendation;
use App\Domain\Recommendation\RecommendationConfidence;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Repositories\RecommendationRepository;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use Database\Seeders\DemoScenarioSeeder;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class RecommendationWorkflowTest
{
    public static function run(): array
    {
        AuthTesting::configure();

        return [
            TestResult::check('End-to-end recommendation workflow: list, view, dismiss, and filter lifecycle', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture('E2E Rec Household', 'DEMO-REC-001');
                $hhId = $fixture['household_id'];
                $account = $fixture['account'];

                $repo = new RecommendationRepository(Database::connection());
                $controller = new RecommendationController();

                // Step 1: Generate recommendations via controller
                $genReq = AuthTesting::authed(Request::create('POST', '/api/v1/recommendations/generate'), $account);
                $genRes = $controller->generate($genReq);
                Assert::same(200, $genRes->status());
                $genData = $genRes->data();
                Assert::true(isset($genData['recommendations']));

                // If no sensor readings existed, DATA_LIMITATION is honestly produced
                Assert::true(count($genData['recommendations']) >= 1);
                $firstRec = $genData['recommendations'][0];
                $recId = $firstRec['id'];
                Assert::same(Recommendation::STATUS_ACTIVE, $firstRec['status']);

                // Step 2: List active recommendations
                $listReq = AuthTesting::authed(Request::create('GET', '/api/v1/recommendations?status=ACTIVE'), $account);
                $listRes = $controller->index($listReq);
                Assert::same(200, $listRes->status());
                $activeList = $listRes->data()['recommendations'];
                $activeIds = array_column($activeList, 'id');
                Assert::true(in_array($recId, $activeIds, true), 'Generated recommendation must appear in ACTIVE list');

                // Step 3: View recommendation detail
                $showReq = AuthTesting::authed(Request::create('GET', "/api/v1/recommendations/{$recId}"), $account);
                $showRes = $controller->show($showReq, ['id' => $recId]);
                Assert::same(200, $showRes->status());
                $detail = $showRes->data()['recommendation'];
                Assert::same($recId, $detail['id']);
                Assert::same($hhId, $detail['household_id']);
                Assert::true(isset($detail['action'], $detail['reason'], $detail['evidence']));

                // Step 4: Dismiss recommendation
                $dismissReq = AuthTesting::authed(Request::create('POST', "/api/v1/recommendations/{$recId}/dismiss"), $account);
                $dismissRes = $controller->dismiss($dismissReq, ['id' => $recId]);
                Assert::same(200, $dismissRes->status());
                $dismissedDetail = $dismissRes->data()['recommendation'];
                Assert::same(Recommendation::STATUS_DISMISSED, $dismissedDetail['status']);
                Assert::true($dismissedDetail['dismissed_at'] !== null);

                // Step 5: Verify filtered list behavior
                $listAfterDismiss = $controller->index(
                    AuthTesting::authed(Request::create('GET', '/api/v1/recommendations?status=ACTIVE'), $account)
                );
                $activeIdsAfter = array_column($listAfterDismiss->data()['recommendations'], 'id');
                Assert::false(in_array($recId, $activeIdsAfter, true), 'Dismissed recommendation must NOT appear in ACTIVE list');

                $listDismissed = $controller->index(
                    AuthTesting::authed(Request::create('GET', '/api/v1/recommendations?status=DISMISSED'), $account)
                );
                $dismissedIds = array_column($listDismissed->data()['recommendations'], 'id');
                Assert::true(in_array($recId, $dismissedIds, true), 'Dismissed recommendation MUST appear in DISMISSED list');
            }),

            TestResult::check('Demo Scenarios SCEN-01 to SCEN-05 generate valid recommendations with honest metadata', function (): void {
                $pdo = Database::connection();
                $passwords = [];
                DemoScenarioSeeder::seed($pdo, $passwords);

                $repo = new RecommendationRepository($pdo);

                // SCEN-01: EVENING_DEFICIT_PREPARATION and SHIFT_TO_SOLAR_WINDOW
                $scen01 = DemoScenarioSeeder::SCENARIOS['SCEN-01'];
                $hh01Id = (string) $pdo->query("SELECT id FROM households WHERE label = '{$scen01['household_label']}'")->fetchColumn();
                $recs01 = $repo->list($hh01Id);
                $types01 = array_map(static fn ($r) => $r->type(), $recs01);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION, $types01, true), 'SCEN-01 must have EVENING_DEFICIT_PREPARATION');
                Assert::true(in_array(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $types01, true), 'SCEN-01 must have SHIFT_TO_SOLAR_WINDOW');
                foreach ($recs01 as $r) {
                    Assert::same('SIMULATED', $r->dataNature());
                    Assert::same('GOOD', $r->quality());
                }

                // SCEN-02: AVOID_DEFICIT_WINDOW, HIGH_DEMAND_REDUCTION, COST_AWARE_SHIFT
                $scen02 = DemoScenarioSeeder::SCENARIOS['SCEN-02'];
                $hh02Id = (string) $pdo->query("SELECT id FROM households WHERE label = '{$scen02['household_label']}'")->fetchColumn();
                $recs02 = $repo->list($hh02Id);
                $types02 = array_map(static fn ($r) => $r->type(), $recs02);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, $types02, true), 'SCEN-02 must have AVOID_DEFICIT_WINDOW');
                Assert::true(in_array(RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION, $types02, true), 'SCEN-02 must have HIGH_DEMAND_REDUCTION');
                Assert::true(in_array(RecommendationTaxonomy::TYPE_COST_AWARE_SHIFT, $types02, true), 'SCEN-02 must have COST_AWARE_SHIFT');

                // SCEN-03: USE_AVAILABLE_SOLAR
                $scen03 = DemoScenarioSeeder::SCENARIOS['SCEN-03'];
                $hh03Id = (string) $pdo->query("SELECT id FROM households WHERE label = '{$scen03['household_label']}'")->fetchColumn();
                $recs03 = $repo->list($hh03Id);
                $types03 = array_map(static fn ($r) => $r->type(), $recs03);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR, $types03, true), 'SCEN-03 must have USE_AVAILABLE_SOLAR');

                // SCEN-04: SOLAR_DECLINE_RESPONSE and DATA_LIMITATION (with DEGRADED quality, LOW confidence)
                $scen04 = DemoScenarioSeeder::SCENARIOS['SCEN-04'];
                $hh04Id = (string) $pdo->query("SELECT id FROM households WHERE label = '{$scen04['household_label']}'")->fetchColumn();
                $recs04 = $repo->list($hh04Id);
                $types04 = array_map(static fn ($r) => $r->type(), $recs04);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE, $types04, true), 'SCEN-04 must have SOLAR_DECLINE_RESPONSE');
                Assert::true(in_array(RecommendationTaxonomy::TYPE_DATA_LIMITATION, $types04, true), 'SCEN-04 must have DATA_LIMITATION');
                foreach ($recs04 as $r) {
                    Assert::same(RecommendationConfidence::CONFIDENCE_LOW, $r->confidence(), 'SCEN-04 recommendations must reflect LOW confidence');
                    Assert::same('DEGRADED', $r->quality(), 'SCEN-04 recommendations must reflect DEGRADED quality');
                }

                // SCEN-05: HIGH_DEMAND_REDUCTION
                $scen05 = DemoScenarioSeeder::SCENARIOS['SCEN-05'];
                $hh05Id = (string) $pdo->query("SELECT id FROM households WHERE label = '{$scen05['household_label']}'")->fetchColumn();
                $recs05 = $repo->list($hh05Id);
                $types05 = array_map(static fn ($r) => $r->type(), $recs05);
                Assert::true(in_array(RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION, $types05, true), 'SCEN-05 must have HIGH_DEMAND_REDUCTION');
            }),
        ];
    }
}
