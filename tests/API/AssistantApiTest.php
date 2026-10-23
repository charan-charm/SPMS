<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\AssistantController;
use App\Domain\Assistant\AIProviderGateway;
use App\Domain\Assistant\AssistantResponseValidator;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Providers\FakeAIProvider;
use App\Domain\Assistant\SPMSContextBuilder;
use App\Domain\Household\HouseholdService;
use App\Domain\Intelligence\Insight;
use App\Repositories\AssistantMessageRepository;
use App\Repositories\InsightRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class AssistantApiTest
{
    private static function makeController(?AssistantService $service = null): AssistantController
    {
        AuthTesting::configure();

        if ($service === null) {
            $fakeProvider = new FakeAIProvider(
                name: 'gemini',
                model: 'gemini-1.5-flash',
                configured: true,
                responseContent: 'Your solar generation is currently performing well with 13.711 kWh forecast surplus.'
            );
            $gateway = new AIProviderGateway(customProviders: ['gemini' => $fakeProvider], primaryProvider: 'gemini', fallbackEnabled: false);
            $service = new AssistantService(
                contextBuilder: new SPMSContextBuilder(),
                gateway: $gateway,
                validator: new AssistantResponseValidator(),
                messageRepository: new AssistantMessageRepository(Database::connection())
            );
        }

        return new AssistantController(
            service: $service,
            households: new HouseholdService()
        );
    }

    public static function run(): array
    {
        return [
            TestResult::check('POST /api/v1/assistant requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::makeController()->ask(Request::create('POST', '/api/v1/assistant', [], ['message' => 'Hello']));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('GET /api/v1/assistant requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::makeController()->history(Request::create('GET', '/api/v1/assistant'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('DELETE /api/v1/assistant requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::makeController()->clear(Request::create('DELETE', '/api/v1/assistant'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/assistant validates non-empty message', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $req = AuthTesting::authed(Request::create('POST', '/api/v1/assistant', [], ['message' => '   ']), $account);

                try {
                    self::makeController()->ask($req);
                    Assert::true(false, 'Expected validation error for empty message');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/assistant blocks cross-household scope claims', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $foreignHhId = '00000000-0000-0000-0000-000000000099';
                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'How much solar?',
                        'household_id' => $foreignHhId,
                    ]),
                    $account
                );

                try {
                    self::makeController()->ask($req);
                    Assert::true(false, 'Expected 403 on cross-household claim');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_ACCESS_DENIED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/assistant processes question and adheres to API response contract', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $fakeResponse = 'Based on your latest SPMS data, your solar forecast indicates potential surplus of 13.711 kWh.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);
                $service = new AssistantService(
                    contextBuilder: new SPMSContextBuilder(),
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'Why do I have a potential surplus?',
                        'page_context' => 'forecasts',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(isset($data['answer']));
                Assert::same('gemini', $data['provider']);
                Assert::same('gemini-1.5-flash', $data['model']);
                Assert::true(is_array($data['context_domains']));
                Assert::true(in_array('forecast', $data['context_domains'], true) || in_array('energy_balance', $data['context_domains'], true));
                Assert::true(isset($data['data_nature']));
                Assert::true(isset($data['confidence']));
                Assert::true(is_array($data['limitations']));
                Assert::false($data['fallback_used']);
            }),

            TestResult::check('POST /api/v1/assistant succeeds with page_context=insights when active insights exist', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];
                $householdId = $fixture['household_id'];

                $fakeResponse = 'Your solar generation has a detected midday pattern with peak output observed.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);

                $mockInsightRepo = new class($householdId) extends InsightRepository {
                    public function __construct(private readonly string $targetHhId) {}
                    public function list(array $filters = []): array
                    {
                        return [
                            Insight::fromStorageRow([
                                'id' => '00000000-0000-0000-0000-000000000055',
                                'household_id' => $this->targetHhId,
                                'category' => 'SOLAR',
                                'type' => 'SOLAR_PEAK_SHIFT',
                                'title' => 'Midday Solar Shift Observed',
                                'summary' => 'Peak solar generation was observed shifted by 1 hour.',
                                'significance' => 'HIGH',
                                'confidence' => 'HIGH',
                                'status' => 'DETECTED',
                                'affected_period_start' => '2026-10-21 10:00:00',
                                'affected_period_end' => '2026-10-21 14:00:00',
                                'affected_period_resolution' => 'HOUR',
                                'detected_at' => '2026-10-21 11:00:00',
                                'first_detected_at' => '2026-10-21 11:00:00',
                                'last_observed_at' => '2026-10-21 11:00:00',
                                'updated_at' => '2026-10-21 11:00:00',
                                'observation_count' => 1,
                                'data_nature' => 'ACTUAL',
                                'data_status' => 'FRESH',
                                'quality' => 'GOOD',
                                'explanation' => 'Midday solar generation analysis.',
                                'explanation_source' => 'F6_DETERMINISTIC_ENGINE_V1',
                                'evidence_summary' => 'Peak solar observed.',
                                'supporting_facts' => '[]',
                                'limitations' => '[]',
                                'provenance' => '{}',
                            ]),
                        ];
                    }
                };

                $contextBuilder = new SPMSContextBuilder(insightRepository: $mockInsightRepo);
                $service = new AssistantService(
                    contextBuilder: $contextBuilder,
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'todays generation',
                        'page_context' => 'insights',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(isset($data['answer']));
                Assert::same('gemini', $data['provider']);
                Assert::true(in_array('insights', $data['context_domains'], true));
                Assert::false($data['fallback_used']);
            }),

            TestResult::check('GET and DELETE /api/v1/assistant enforce strict household conversation isolation', function (): void {
                RequestId::set(RequestId::new());
                $fixture1 = AuthTesting::householdFixture();
                $account1 = $fixture1['account'];

                $fixture2 = AuthTesting::householdFixture();
                $account2 = $fixture2['account'];

                $controller = self::makeController();

                // Household 1 asks a question
                $req1 = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], ['message' => 'User 1 Question']),
                    $account1
                );
                $controller->ask($req1);

                // Household 2 asks a question
                $req2 = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], ['message' => 'User 2 Secret Question']),
                    $account2
                );
                $controller->ask($req2);

                // Household 1 fetches history: must NOT contain User 2's question
                $histReq1 = AuthTesting::authed(Request::create('GET', '/api/v1/assistant'), $account1);
                $histResp1 = $controller->history($histReq1);
                $messages1 = $histResp1->data()['messages'];

                foreach ($messages1 as $m) {
                    Assert::false(str_contains((string) $m['content'], 'User 2 Secret Question'), 'Household 1 must not see Household 2 messages');
                }

                // Household 2 fetches history: must contain User 2's question
                $histReq2 = AuthTesting::authed(Request::create('GET', '/api/v1/assistant'), $account2);
                $histResp2 = $controller->history($histReq2);
                $messages2 = $histResp2->data()['messages'];

                $foundUser2 = false;
                foreach ($messages2 as $m) {
                    if (str_contains((string) $m['content'], 'User 2 Secret Question')) {
                        $foundUser2 = true;
                    }
                }
                Assert::true($foundUser2, 'Household 2 must find own message');

                // Household 1 clears history: must only clear Household 1
                $clearReq1 = AuthTesting::authed(Request::create('DELETE', '/api/v1/assistant'), $account1);
                $controller->clear($clearReq1);

                $postClearHist1 = $controller->history($histReq1)->data()['messages'];
                Assert::same(0, count($postClearHist1), 'Household 1 history must be cleared');

                $postClearHist2 = $controller->history($histReq2)->data()['messages'];
                Assert::true(count($postClearHist2) > 0, 'Household 2 history must remain untouched');
            }),

            TestResult::check('POST /api/v1/assistant answers weekly solar generation question with authoritative context', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $fakeResponse = 'Based on your solar records, your most recent recorded week generated 125.320 kWh.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);
                $service = new AssistantService(
                    contextBuilder: new SPMSContextBuilder(),
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'this week generation',
                        'page_context' => 'insights',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(isset($data['answer']));
                Assert::same('gemini', $data['provider']);
                Assert::true(in_array('solar', $data['context_domains'], true));
                Assert::true(in_array('insights', $data['context_domains'], true));
            }),

            TestResult::check('POST /api/v1/assistant routes weather questions to weather domain', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $fakeResponse = 'Today the weather is partly cloudy with good conditions for solar production.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);
                $service = new AssistantService(
                    contextBuilder: new SPMSContextBuilder(),
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'what is the weather today?',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(in_array('weather', $data['context_domains'], true));
            }),

            TestResult::check('POST /api/v1/assistant routes bill coverage questions to bills and solar domains', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $fakeResponse = 'Your solar generation covered approximately 78% of your billed consumption.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);
                $service = new AssistantService(
                    contextBuilder: new SPMSContextBuilder(),
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'how much of my bill is covered by solar?',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(in_array('bills', $data['context_domains'], true));
                Assert::true(in_array('solar', $data['context_domains'], true));
            }),

            TestResult::check('POST /api/v1/assistant routes historical comparison questions accurately', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $fakeResponse = 'Comparing this week with last week requires historical data from both periods.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);
                $service = new AssistantService(
                    contextBuilder: new SPMSContextBuilder(),
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'compare this week with last week',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(in_array('solar', $data['context_domains'], true));
            }),

            TestResult::check('POST /api/v1/assistant answers lowest generation day query', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $fakeResponse = 'Your lowest recorded active generation day was 19.810 kWh.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);
                $service = new AssistantService(
                    contextBuilder: new SPMSContextBuilder(),
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'what was our lowest generation day?',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(in_array('solar', $data['context_domains'], true));
            }),

            TestResult::check('POST /api/v1/assistant handles appliance control request with advisory safety refusal', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $account = $fixture['account'];

                $fakeResponse = 'I cannot switch off your AC. SPMS is strictly an analytical advisory system that suggests optimal load windows.';
                $fake = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, $fakeResponse);
                $gateway = new AIProviderGateway(customProviders: ['gemini' => $fake], primaryProvider: 'gemini', fallbackEnabled: false);
                $service = new AssistantService(
                    contextBuilder: new SPMSContextBuilder(),
                    gateway: $gateway,
                    validator: new AssistantResponseValidator(),
                    messageRepository: new AssistantMessageRepository(Database::connection())
                );

                $controller = self::makeController($service);

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/assistant', [], [
                        'message' => 'can you turn off my AC to save power?',
                    ]),
                    $account
                );

                $response = $controller->ask($req);
                Assert::same(200, $response->status());

                $data = $response->data();
                Assert::true(isset($data['answer']));
                Assert::true(str_contains($data['answer'], 'advisory'));
            }),
        ];
    }
}
