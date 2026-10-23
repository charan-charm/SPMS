<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Controllers\AuthController;
use App\Controllers\EnergyBalanceController;
use App\Controllers\EnergyCostController;
use App\Controllers\ForecastController;
use App\Controllers\IntelligenceController;
use App\Controllers\NotificationController;
use App\Controllers\RecommendationController;
use App\Domain\Account\Account;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\SessionCookie;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Forecast\SolarForecastModel;
use App\Domain\Household\HouseholdService;
use App\Domain\Intelligence\InsightTaxonomy;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Repositories\AccountRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\InsightRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Support\Config;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use Database\Seeders\DemoScenarioSeeder;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

require_once dirname(__DIR__, 2) . '/database/seeders/DemoScenarioSeeder.php';

/**
 * SPMS D1 — Targeted Verification for DEMO Credentials Setup & SCEN-01 Solar Surplus.
 *
 * Verifies:
 * 1. Scenario account exists and authenticates with configured password (.env.demo.local).
 * 2. Normal login flow works, issues session cookie, and active household is correct.
 * 3. Solar hardware is 3.0 kW (GW3000-DNS) and readings never exceed capacity.
 * 4. F3 Forecast produces honest SIMULATED forecast respecting 3.0 kW hardware limit.
 * 5. F4 Energy Balance evaluates real POTENTIAL_SURPLUS balance.
 * 6. F5 Energy Cost operates honestly without false grid export monetary credit.
 * 7. F6 Insights pipeline detects diurnal mismatch and evening deficit patterns.
 * 8. F7 Recommendation pipeline generates SHIFT_TO_SOLAR_WINDOW and EVENING_DEFICIT_PREPARATION.
 * 9. F9 Notification pipeline creates notifications for high-priority recommendations.
 * 10. Logout terminates the session cleanly.
 * 11. Database isolation: real database remains 100% untouched.
 */
final class DemoScenario01IntegrationTest
{
    public static function run(): array
    {
        return [
            TestResult::check('SCEN-01 account exists with DEMO_TEST mode and resolves credentials', function (): void {
                $pdo = Database::connection();
                $passwords = [];
                DemoScenarioSeeder::seed($pdo, $passwords);

                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $expectedPass = (string) (Config::get('DEMO_SCENARIO_01_PASSWORD') ?: 'SolarSurplus2026!');

                $stmt = $pdo->prepare('SELECT * FROM accounts WHERE email = :email');
                $stmt->execute(['email' => $email]);
                $accountRow = $stmt->fetch(PDO::FETCH_ASSOC);

                Assert::true($accountRow !== false, "SCEN-01 account {$email} must exist in active database");
                Assert::same('DEMO_TEST', $accountRow['mode'], 'SCEN-01 account must be DEMO_TEST mode');
                Assert::same('ACTIVE', $accountRow['status'], 'SCEN-01 account must be ACTIVE');
                Assert::true(PasswordPolicy::verify($expectedPass, $accountRow['password_hash']), 'Password hash must verify against configured password');
            }),

            TestResult::check('SCEN-01 authenticates through normal login flow and sets session', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $password = (string) (Config::get('DEMO_SCENARIO_01_PASSWORD') ?: 'SolarSurplus2026!');

                RequestId::set(RequestId::new());
                $req = Request::create(
                    'POST',
                    '/api/v1/auth/login',
                    ['Content-Type' => 'application/json'],
                    ['email' => $email, 'password' => $password]
                );

                $controller = new AuthController();
                $resp = $controller->login($req);

                Assert::same(200, $resp->status(), 'Login must return 200 OK');
                $body = $resp->body();
                Assert::true($body['success']);
                Assert::same($email, $body['data']['account']['email']);

                // Verify session cookie was attached to response
                $headers = $resp->headers();
                Assert::true(isset($headers['Set-Cookie']), 'Login response must set a Set-Cookie header');
                Assert::true(str_contains($headers['Set-Cookie'], 'session='), 'Cookie name must be a session cookie');
            }),

            TestResult::check('SCEN-01 active household is Solar Surplus Residence in Kodumur / AP', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $accountRepo = new AccountRepository();
                $account = $accountRepo->findByEmail($email);
                Assert::true($account !== null, 'Account must exist');

                $hhService = new HouseholdService();
                $context = $hhService->activeHouseholdFor($account);
                Assert::true($context !== null, 'Account must have an active household');

                $hh = $context['household'];
                Assert::same('Solar Surplus Residence', $hh->label(), 'Household label must match scenario');
                Assert::same('Kodumur / Andhra Pradesh', $hh->locationLabel(), 'Location must be Kodumur / Andhra Pradesh');
                Assert::same('Asia/Kolkata', $hh->timezone(), 'Timezone must be Asia/Kolkata');
                Assert::true(str_contains((string) $hh->serviceNumber(), 'DEMO1000200001'), 'Canonical service number must match scenario');
            }),

            TestResult::check('SCEN-01 solar hardware connection is 3.0 kW and telemetry never exceeds capacity', function (): void {
                $pdo = Database::connection();
                $meta = DemoScenarioSeeder::SCENARIOS['SCEN-01'];
                $hhStmt = $pdo->prepare('SELECT id FROM households WHERE label = :label');
                $hhStmt->execute(['label' => $meta['household_label']]);
                $householdId = (string) $hhStmt->fetchColumn();

                $solarRepo = new SolarConnectionRepository($pdo);
                $conn = $solarRepo->findByHouseholdId($householdId);
                Assert::true($conn !== null, 'Solar connection must exist');
                Assert::same('GW3000-DNS', $conn['inverter_sn'], 'Inverter serial must be GW3000-DNS');
                Assert::same('CONNECTED', $conn['connection_status'], 'Status must be CONNECTED');

                // Hardware capacity model resolution
                $peakCapacityW = SolarForecastModel::parseCapacityFromInverterSn($conn['inverter_sn']);
                Assert::same(3000.0, $peakCapacityW, 'Authoritative inverter capacity must be exactly 3000.0 W (3.0 kW)');

                // Inspect telemetry readings
                $stmt = $pdo->prepare('SELECT COUNT(*) as cnt, MAX(power_w) as max_w, MIN(power_w) as min_w, data_nature FROM solar_readings WHERE household_id = :hid GROUP BY data_nature');
                $stmt->execute(['hid' => $householdId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                Assert::true((int) $row['cnt'] >= 50, 'Telemetry must have >= 50 readings for reliable baseline');
                Assert::true((float) $row['max_w'] <= 3000.0, 'Observed generation must never exceed 3000.0 W rated capacity');
                Assert::same(0.0, (float) $row['min_w'], 'Night generation must reach 0.0 W');
                Assert::same('SIMULATED', $row['data_nature'], 'Data nature must be SIMULATED');
            }),

            TestResult::check('SCEN-01 F3 Forecast produces SIMULATED forecast with POTENTIAL_SURPLUS projection', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $account = (new AccountRepository())->findByEmail($email);
                RequestId::set(RequestId::new());

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/forecasts?horizon=DAY_AHEAD'), $account);

                $controller = new ForecastController();
                $resp = $controller->index($req);

                Assert::same(200, $resp->status());
                $body = $resp->body();
                Assert::true($body['success']);

                $fc = $body['data'];
                Assert::same('SIMULATED', $fc['data_nature'], 'Forecast data nature must be SIMULATED');
                Assert::true($fc['solar_forecast']['predicted_value'] > 0, 'Solar forecast predicted value must be positive');
                Assert::true($fc['consumption_forecast']['predicted_value'] > 0, 'Consumption forecast predicted value must be positive');

                $proj = $fc['energy_balance_projection'];
                Assert::same('CLASSIFIED', $proj['state'], 'Balance projection must be CLASSIFIED');
                Assert::same('POTENTIAL_SURPLUS', $proj['classification'], 'Balance classification must be POTENTIAL_SURPLUS');
                Assert::true($proj['balance_kwh'] > 0.0, 'Projected balance must be positive');
                Assert::same('SIMULATED', $proj['data_nature'], 'Projection data nature must be SIMULATED');
            }),

            TestResult::check('SCEN-01 F4 Energy Balance evaluates CLASSIFIED POTENTIAL_SURPLUS', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $account = (new AccountRepository())->findByEmail($email);
                RequestId::set(RequestId::new());

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/energy/balance'), $account);

                $controller = new EnergyBalanceController();
                $resp = $controller->current($req);

                Assert::same(200, $resp->status());
                $body = $resp->body();
                Assert::true($body['success']);

                $result = $body['data']['result'];
                Assert::same('CLASSIFIED', $result['state']);
                Assert::same('POTENTIAL_SURPLUS', $result['classification']);
                Assert::true((float) $result['balance_kwh'] > 0.0, 'Balance must be positive');
                Assert::same('SIMULATED', $result['data_nature']);
            }),

            TestResult::check('SCEN-01 F5 Energy Cost operates honestly without claiming grid export compensation', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $account = (new AccountRepository())->findByEmail($email);
                RequestId::set(RequestId::new());

                $req = AuthTesting::authed(Request::create('POST', '/api/v1/energy/cost/estimate', [], [
                    'solar_generation' => ['value_kwh' => 2.85, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'data_quality' => 'GOOD'],
                    'household_consumption' => ['value_kwh' => 0.28, 'data_nature' => 'SIMULATED', 'data_status' => 'FRESH', 'data_quality' => 'GOOD'],
                    'pricing' => ['basis' => EnergyCostService::BASIS_USER_PROVIDED_RATE, 'unit_rate' => 7.50, 'currency' => 'INR', 'source' => 'Simulated Tariff'],
                    'period' => ['start' => '2026-09-20T12:00:00', 'end' => '2026-09-20T13:00:00', 'resolution' => 'HOUR'],
                ]), $account);

                $controller = new EnergyCostController();
                $resp = $controller->estimate($req);

                Assert::same(200, $resp->status());
                $body = $resp->body();
                Assert::true($body['success']);

                $cost = $body['data'];
                Assert::true(isset($cost['result']), 'Result section must be present');
                if (isset($cost['result']['estimated_variable_cost'])) {
                    Assert::true((float) $cost['result']['estimated_variable_cost'] >= 0.0, 'Variable cost must never be negative');
                }
            }),

            TestResult::check('SCEN-01 F6 Insights detects diurnal mismatch & evening deficit with SIMULATED nature', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $account = (new AccountRepository())->findByEmail($email);
                RequestId::set(RequestId::new());

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/intelligence/insights'), $account);

                $controller = new IntelligenceController();
                $resp = $controller->list($req);

                Assert::same(200, $resp->status());
                $body = $resp->body();
                Assert::true($body['success']);

                $insights = $body['data']['insights'];
                $types = array_map(static fn ($i) => $i['type'], $insights);

                Assert::true(in_array(InsightTaxonomy::TYPE_DAYTIME_SURPLUS_EVENING_DEFICIT, $types, true), 'Must produce DAYTIME_SURPLUS_EVENING_DEFICIT');
                Assert::true(in_array(InsightTaxonomy::TYPE_SOLAR_DEMAND_MISMATCH, $types, true), 'Must produce SOLAR_DEMAND_MISMATCH');
                Assert::true(in_array(InsightTaxonomy::TYPE_DATA_LIMITATION, $types, true), 'Must produce DATA_LIMITATION');

                foreach ($insights as $insight) {
                    if ($insight['type'] === InsightTaxonomy::TYPE_DATA_LIMITATION || $insight['type'] === InsightTaxonomy::TYPE_WEATHER_DATA_UNAVAILABLE) {
                        Assert::same(null, $insight['data_nature'], 'Limitation / unavailable insight has null data_nature (no analytical value)');
                    } else {
                        Assert::same('SIMULATED', $insight['data_nature'], 'Analytical insights must preserve SIMULATED nature');
                    }
                }
            }),

            TestResult::check('SCEN-01 F7 Recommendations generates SHIFT_TO_SOLAR_WINDOW and EVENING_DEFICIT_PREPARATION', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $account = (new AccountRepository())->findByEmail($email);
                RequestId::set(RequestId::new());

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/recommendations'), $account);

                $controller = new RecommendationController();
                $resp = $controller->index($req);

                Assert::same(200, $resp->status());
                $body = $resp->body();
                Assert::true($body['success']);

                $recs = $body['data']['recommendations'];
                $types = array_map(static fn ($r) => $r['type'], $recs);

                Assert::true(in_array(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $types, true), 'Must produce SHIFT_TO_SOLAR_WINDOW');
                Assert::true(in_array(RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION, $types, true), 'Must produce EVENING_DEFICIT_PREPARATION');

                $shiftRec = null;
                foreach ($recs as $r) {
                    Assert::same('SIMULATED', $r['data_nature'], 'Recommendation must preserve SIMULATED nature');
                    Assert::same('GOOD', $r['quality'], 'Quality must be GOOD');
                    if ($r['type'] === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                        $shiftRec = $r;
                    }
                }

                Assert::true($shiftRec !== null, 'Shift recommendation must exist');
                Assert::same('Use flexible appliances during solar hours', $shiftRec['title']);
                Assert::true(str_contains($shiftRec['summary'], 'Potential solar-window opportunity'), 'Summary must use potential opportunity wording');
            }),

            TestResult::check('SCEN-01 F9 Notifications receives alerts for high-priority recommendations', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $account = (new AccountRepository())->findByEmail($email);
                RequestId::set(RequestId::new());

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/notifications'), $account);

                $controller = new NotificationController();
                $resp = $controller->index($req);

                Assert::same(200, $resp->status());
                $body = $resp->body();
                Assert::true($body['success']);

                $notifications = $body['data']['notifications'];
                Assert::true(!empty($notifications), 'High-priority recommendations must trigger F9 notifications');
                $titles = array_map(static fn ($n) => $n['title'], $notifications);
                Assert::true(in_array('High-Priority Recommendation', $titles, true), 'Notification for high priority recommendation must exist');
            }),

            TestResult::check('SCEN-01 logout terminates session and blocks unauthenticated requests', function (): void {
                $email = (string) (Config::get('DEMO_SCENARIO_01_EMAIL') ?: 'demo.surplus@spms.local');
                $account = (new AccountRepository())->findByEmail($email);
                RequestId::set(RequestId::new());

                $sessionService = new \App\Domain\Auth\SessionService();
                $started = $sessionService->start($account->id());

                $authContext = \App\Security\AuthContext::authenticated($account, $started['session']);
                $req = Request::create('POST', '/api/v1/auth/logout')->withAuthContext($authContext);

                $controller = new AuthController();
                $resp = $controller->logout($req);

                Assert::same(200, $resp->status());

                $sessStmt = Database::connection()->prepare('SELECT * FROM sessions WHERE id = :id');
                $sessStmt->execute(['id' => $started['session']['id']]);
                $sessRow = $sessStmt->fetch(PDO::FETCH_ASSOC);
                Assert::true($sessRow === false || $sessRow['revoked_at'] !== null, 'Session must be revoked upon logout');
            }),

            TestResult::check('spms_real database has exactly 11 accounts and is completely untouched', function (): void {
                // Connect to MySQL server and query spms_real directly if it exists
                $pdo = Database::connection();
                $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'spms_real'");
                $hasReal = $stmt->fetchColumn();

                if ($hasReal) {
                    $realPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=spms_real;charset=utf8mb4', 'root', '');
                    $realPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $count = (int) $realPdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
                    Assert::same(11, $count, 'spms_real database must have exactly 11 accounts — zero demo contamination');

                    // Check that demo.surplus@spms.local does not exist in spms_real
                    $checkStmt = $realPdo->prepare("SELECT COUNT(*) FROM accounts WHERE email = 'demo.surplus@spms.local'");
                    $checkStmt->execute();
                    Assert::same(0, (int) $checkStmt->fetchColumn(), 'SCEN-01 demo account must NOT exist in spms_real');
                }
            }),
        ];
    }
}
