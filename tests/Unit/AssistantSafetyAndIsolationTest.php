<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Assistant\AIProviderGateway;
use App\Domain\Assistant\AssistantPrompt;
use App\Domain\Assistant\AssistantResponseValidator;
use App\Domain\Assistant\Providers\AssistantProviderException;
use App\Domain\Assistant\Providers\FakeAIProvider;
use App\Domain\Assistant\SPMSContextBuilder;
use App\Domain\Intelligence\Insight;
use App\Repositories\InsightRepository;
use Tests\Support\Assert;
use Tests\TestResult;

final class AssistantSafetyAndIsolationTest
{
    public static function run(): array
    {
        return [
            // Provider Gateway & Fallback Tests
            TestResult::check('AIProviderGateway returns primary provider response when healthy', function (): void {
                $fakePrimary = new FakeAIProvider('gemini', 'gemini-1.5-flash', true, 'Primary response text');
                $fakeSecondary = new FakeAIProvider('groq', 'llama-3.3-70b-versatile', true, 'Secondary response text');
                $fakeTertiary = new FakeAIProvider('openrouter', 'meta-llama/llama-3.3-70b-instruct', true, 'Tertiary response text');

                $gateway = new AIProviderGateway(
                    customProviders: [
                        'gemini' => $fakePrimary,
                        'groq' => $fakeSecondary,
                        'openrouter' => $fakeTertiary,
                    ],
                    primaryProvider: 'gemini',
                    fallbackEnabled: true
                );

                $result = $gateway->complete([
                    ['role' => 'system', 'content' => 'System prompt'],
                    ['role' => 'user', 'content' => 'Hello assistant'],
                ]);

                Assert::same('Primary response text', $result['response']->content);
                Assert::same('gemini', $result['response']->provider);
                Assert::same('gemini-1.5-flash', $result['response']->model);
                Assert::false($result['fallback_used']);
            }),

            TestResult::check('AIProviderGateway falls back from Gemini to Groq on provider error', function (): void {
                $failingGemini = new FakeAIProvider(
                    'gemini',
                    'gemini-1.5-flash',
                    true,
                    null,
                    AssistantProviderException::rateLimited('gemini', 'Rate limit 429')
                );

                $fakeGroq = new FakeAIProvider('groq', 'llama-3.3-70b-versatile', true, 'Groq fallback explanation');
                $fakeTertiary = new FakeAIProvider('openrouter', 'meta-llama/llama-3.3-70b-instruct', true, 'OpenRouter');

                $gateway = new AIProviderGateway(
                    customProviders: [
                        'gemini' => $failingGemini,
                        'groq' => $fakeGroq,
                        'openrouter' => $fakeTertiary,
                    ],
                    primaryProvider: 'gemini',
                    fallbackEnabled: true
                );

                $result = $gateway->complete([
                    ['role' => 'user', 'content' => 'Explain my forecast'],
                ]);

                Assert::same('Groq fallback explanation', $result['response']->content);
                Assert::same('groq', $result['response']->provider);
                Assert::true($result['fallback_used']);
            }),

            TestResult::check('AIProviderGateway falls back from Groq to OpenRouter when both Gemini and Groq fail', function (): void {
                $failingGemini = new FakeAIProvider(
                    'gemini',
                    'gemini-1.5-flash',
                    true,
                    null,
                    AssistantProviderException::timeout('gemini', 15)
                );

                $failingGroq = new FakeAIProvider(
                    'groq',
                    'llama-3.3-70b-versatile',
                    true,
                    null,
                    AssistantProviderException::providerUnavailable('groq', 'Groq 503 Overloaded')
                );

                $fakeOpenRouter = new FakeAIProvider('openrouter', 'meta-llama/llama-3.3-70b-instruct', true, 'OpenRouter tertiary explanation');

                $gateway = new AIProviderGateway(
                    customProviders: [
                        'gemini' => $failingGemini,
                        'groq' => $failingGroq,
                        'openrouter' => $fakeOpenRouter,
                    ],
                    primaryProvider: 'gemini',
                    fallbackEnabled: true
                );

                $result = $gateway->complete([
                    ['role' => 'user', 'content' => 'Explain balance'],
                ]);

                Assert::same('OpenRouter tertiary explanation', $result['response']->content);
                Assert::same('openrouter', $result['response']->provider);
                Assert::true($result['fallback_used']);
            }),

            TestResult::check('AIProviderGateway throws allFailed when all configured providers fail', function (): void {
                $failingGemini = new FakeAIProvider(
                    'gemini',
                    'gemini-1.5-flash',
                    true,
                    null,
                    AssistantProviderException::timeout('gemini', 15)
                );

                $failingGroq = new FakeAIProvider(
                    'groq',
                    'llama-3.3-70b-versatile',
                    true,
                    null,
                    AssistantProviderException::providerUnavailable('groq', 'Service down')
                );

                $failingOpenRouter = new FakeAIProvider(
                    'openrouter',
                    'meta-llama/llama-3.3-70b-instruct',
                    true,
                    null,
                    AssistantProviderException::rateLimited('openrouter', 'Credits exhausted')
                );

                $gateway = new AIProviderGateway(
                    customProviders: [
                        'gemini' => $failingGemini,
                        'groq' => $failingGroq,
                        'openrouter' => $failingOpenRouter,
                    ],
                    primaryProvider: 'gemini',
                    fallbackEnabled: true
                );

                try {
                    $gateway->complete([['role' => 'user', 'content' => 'Why do I have a surplus?']]);
                    Assert::true(false, 'Expected AssistantProviderException');
                } catch (AssistantProviderException $e) {
                    Assert::same('ALL_PROVIDERS_UNAVAILABLE', $e->errorCode);
                }
            }),

            // System Prompt & Safety Model Tests
            TestResult::check('AssistantPrompt builds strict safety system prompt enforcing numerical authority', function (): void {
                $contextText = "SOLAR_FORECAST: 16.292 kWh\nCONSUMPTION_FORECAST: 2.581 kWh\nBALANCE: +13.711 kWh (POTENTIAL_SURPLUS)";
                $systemPrompt = AssistantPrompt::buildSystemPrompt($contextText);

                Assert::true(str_contains($systemPrompt, 'SPMS Energy Assistant'));
                Assert::true(str_contains($systemPrompt, 'THE BACKEND IS THE SOLE NUMERICAL AUTHORITY'));
                Assert::true(str_contains($systemPrompt, 'does NOT automatically switch appliances on or off'));
                Assert::true(str_contains($systemPrompt, 'NEVER disclose API keys, passwords'));
                Assert::true(str_contains($systemPrompt, '16.292 kWh'));
                Assert::true(str_contains($systemPrompt, '+13.711 kWh'));
            }),

            // Response Validator Tests
            TestResult::check('AssistantResponseValidator passes safe factual responses', function (): void {
                $validator = new AssistantResponseValidator();
                $contextText = "BALANCE: +13.711 kWh\nSTATUS: POTENTIAL_SURPLUS";
                $safeAnswer = 'Your forecast indicates a potential surplus of 13.711 kWh during the midday window.';

                $result = $validator->validate($safeAnswer, $contextText);

                Assert::true($result['is_valid']);
                Assert::same($safeAnswer, $result['sanitized_content']);
            }),

            TestResult::check('AssistantResponseValidator blocks secret leaks (API keys, .env, DB passwords)', function (): void {
                $validator = new AssistantResponseValidator();
                $contextText = "BALANCE: +5.0 kWh";

                $leakyAnswers = [
                    'Here is your key: AIzaSyD98f7gH6j5k4L3m2n1P0q',
                    'Your configuration has DB_PASSWORD=SecretRootPass in .env',
                    'Groq key is gsk_abcd1234efgh5678ijkl9012mnop',
                    'GoodWe token is eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
                ];

                foreach ($leakyAnswers as $leaky) {
                    $result = $validator->validate($leaky, $contextText);
                    Assert::false($result['is_valid'], 'Must detect secret leak: ' . $leaky);
                    Assert::true(str_contains($result['sanitized_content'], 'contained sensitive or restricted key patterns'));
                }
            }),

            TestResult::check('AssistantResponseValidator blocks physical appliance control claims', function (): void {
                $validator = new AssistantResponseValidator();
                $contextText = "BALANCE: +5.0 kWh";

                $controlClaims = [
                    'I have switched off your inverter to prevent overcharging.',
                    'I will turn off your appliance right now.',
                    'Automatically controlled your pump based on weather.',
                ];

                foreach ($controlClaims as $claim) {
                    $result = $validator->validate($claim, $contextText);
                    Assert::true(str_contains($result['sanitized_content'], 'SPMS is an advisory analytics platform and does not directly control physical appliances'));
                    Assert::true(in_array('AUTOMATED_CONTROL_CLAIM_FLAGGED', $result['violations'], true));
                }
            }),

            TestResult::check('AssistantResponseValidator detects numerical contradictions against authoritative balance', function (): void {
                $validator = new AssistantResponseValidator();
                $contextText = "BALANCE: +13.711 kWh\nSOLAR_FORECAST: 16.292 kWh\nCONSUMPTION_FORECAST: 2.581 kWh";

                // LLM hallucinates 28.5 kWh surplus instead of authoritative 13.711
                $contradictoryAnswer = 'You have a confirmed surplus of 28.50 kWh today to export to the grid.';
                $result = $validator->validate($contradictoryAnswer, $contextText);

                Assert::true(str_contains($result['sanitized_content'], 'Authoritative SPMS Note'));
                Assert::true(str_contains($result['sanitized_content'], '13.711'));
                Assert::true(in_array('NUMERICAL_DISCREPANCY_FLAGGED', $result['violations'], true));
            }),

            TestResult::check('AssistantResponseValidator blocks unsupported grid export telemetry claims', function (): void {
                $validator = new AssistantResponseValidator();
                $contextText = "BALANCE: +13.711 kWh (POTENTIAL_SURPLUS, not measured grid flow)\nSOLAR_FORECAST: 16.292 kWh";

                $gridClaim = 'You exported 4.5 kWh to the grid during the peak afternoon.';
                $result = $validator->validate($gridClaim, $contextText);

                Assert::true(str_contains($result['sanitized_content'], 'does not interface with bidirectional grid export telemetry'));
                Assert::true(in_array('UNSUPPORTED_GRID_CLAIM_FLAGGED', $result['violations'], true));
            }),

            TestResult::check('AssistantResponseValidator detects predicted vs actual generation confusion', function (): void {
                $validator = new AssistantResponseValidator();
                $contextText = "### F3 FORECAST\nPredicted Solar Generation: 16.292 kWh";

                $confusedClaim = 'Your system actually generated 16.292 kWh yesterday.';
                $result = $validator->validate($confusedClaim, $contextText);

                Assert::true(str_contains($result['sanitized_content'], 'is a forecast projection for the upcoming cycle, not an observed measurement'));
                Assert::true(in_array('PREDICTED_ACTUAL_CONFUSION_FLAGGED', $result['violations'], true));
            }),

            TestResult::check('AssistantResponseValidator blocks unsupported guaranteed savings claims', function (): void {
                $validator = new AssistantResponseValidator();
                $contextText = "### F5 COST ESTIMATE\nEstimated Variable Cost: 84.50 INR";

                $guaranteedClaim = 'Following this recommendation gives guaranteed savings of ₹500 on your next APSPDCL bill.';
                $result = $validator->validate($guaranteedClaim, $contextText);

                Assert::true(str_contains($result['sanitized_content'], 'advisory indicators based on AP tariff baselines, not guaranteed contractual amounts'));
                Assert::true(in_array('UNSUPPORTED_SAVINGS_CLAIM_FLAGGED', $result['violations'], true));
            }),

            TestResult::check('SPMSContextBuilder detects domains and formats context without exposing credentials', function (): void {
                $builder = new SPMSContextBuilder();
                $domains = $builder->detectDomains('Why do I have a surplus?', 'forecasts');

                Assert::true(in_array(SPMSContextBuilder::DOMAIN_ENERGY_BALANCE, $domains, true));
                Assert::true(in_array(SPMSContextBuilder::DOMAIN_FORECAST, $domains, true));

                $solarDomains = $builder->detectDomains('Is my GoodWe inverter disconnected?', 'solar');
                Assert::true(in_array(SPMSContextBuilder::DOMAIN_GOODWE, $solarDomains, true));
                Assert::true(in_array(SPMSContextBuilder::DOMAIN_SOLAR, $solarDomains, true));
            }),

            TestResult::check('SPMSContextBuilder formats F6 insights context with title and summary from Insight domain objects', function (): void {
                $mockInsightRepo = new class extends InsightRepository {
                    public function __construct() {}
                    public function list(array $filters = []): array
                    {
                        return [
                            Insight::fromStorageRow([
                                'id' => 'ins-001',
                                'household_id' => 'hh-context-test',
                                'category' => 'SOLAR',
                                'type' => 'SOLAR_PEAK_SHIFT',
                                'title' => 'Midday Peak Solar Shift',
                                'summary' => 'Peak solar generation shifted by 1 hour later than baseline.',
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
                                'explanation' => 'Test explanation',
                                'explanation_source' => 'ENGINE',
                                'evidence_summary' => 'Test evidence',
                                'supporting_facts' => '[]',
                                'limitations' => '[]',
                                'provenance' => '{}',
                            ]),
                        ];
                    }
                };

                $builder = new SPMSContextBuilder(insightRepository: $mockInsightRepo);
                $context = $builder->build('hh-context-test', 'todays generation', 'insights');

                Assert::true(in_array(SPMSContextBuilder::DOMAIN_INSIGHTS, $context['domains'], true));
                Assert::true(str_contains($context['context_text'], '### F6 DETECTED PATTERNS & INSIGHTS'));
                Assert::true(str_contains($context['context_text'], 'Midday Peak Solar Shift'));
                Assert::true(str_contains($context['context_text'], 'Peak solar generation shifted by 1 hour later than baseline.'));
                Assert::true(str_contains($context['context_text'], '[HIGH] Midday Peak Solar Shift'));
            }),

            TestResult::check('SPMSContextBuilder routes natural question variations across 14 categories', function (): void {
                $builder = new SPMSContextBuilder();

                $cases = [
                    'this week generation' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'how much solar did we generate this week?' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'generation this month' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'compare this week with last week' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'what was our best generation day?' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'what was our lowest generation day?' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'last 7 days generation' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'power vs energy' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'can you turn off my AC to save power?' => [SPMSContextBuilder::DOMAIN_RECOMMENDATIONS],
                    'is my inverter data current?' => [SPMSContextBuilder::DOMAIN_SOLAR],
                    'what is the weather today?' => [SPMSContextBuilder::DOMAIN_WEATHER],
                    'why is the forecast different from actual generation?' => [SPMSContextBuilder::DOMAIN_FORECAST, SPMSContextBuilder::DOMAIN_SOLAR],
                    'how much of my bill is covered by solar?' => [SPMSContextBuilder::DOMAIN_BILLS, SPMSContextBuilder::DOMAIN_SOLAR],
                    'do I have unverified bills?' => [SPMSContextBuilder::DOMAIN_BILLS],
                    'why was this insight generated?' => [SPMSContextBuilder::DOMAIN_INSIGHTS],
                    'recommendations for today' => [SPMSContextBuilder::DOMAIN_RECOMMENDATIONS],
                ];

                foreach ($cases as $query => $expectedDomains) {
                    $ctx = $builder->build('hh-routing-test', $query);
                    foreach ($expectedDomains as $expectedDomain) {
                        Assert::true(
                            in_array($expectedDomain, $ctx['domains'], true),
                            "Query '{$query}' must detect domain '{$expectedDomain}'"
                        );
                    }
                }
            }),

            TestResult::check('SPMSContextBuilder formats unavailable metrics honestly without crash or zero-filling', function (): void {
                $builder = new SPMSContextBuilder();
                $context = $builder->build('hh-empty-metrics-test', 'this week generation');

                Assert::true(in_array(SPMSContextBuilder::DOMAIN_SOLAR, $context['domains'], true));
                Assert::true(str_contains($context['context_text'], 'Weekly Generation: UNAVAILABLE'));
                Assert::true(str_contains($context['context_text'], 'Rolling 7-Day Window: UNAVAILABLE'));
                Assert::true(str_contains($context['context_text'], 'Best Generation Day on Record: UNAVAILABLE'));
                Assert::true(str_contains($context['context_text'], 'Lowest Active Generation Day on Record: UNAVAILABLE'));
                Assert::true(str_contains($context['context_text'], 'Today Cumulative Generation: UNAVAILABLE'));
            }),

            TestResult::check('SPMSContextBuilder includes weather context when weather domain is queried', function (): void {
                $builder = new SPMSContextBuilder();
                $context = $builder->build('hh-weather-test', 'what is the weather today?');

                Assert::true(in_array(SPMSContextBuilder::DOMAIN_WEATHER, $context['domains'], true));
                Assert::true(str_contains($context['context_text'], '### LOCAL WEATHER CONDITIONS & SOLAR IMPACT'));
                Assert::true(str_contains($context['context_text'], 'Weather Outlook: UNAVAILABLE'));
            }),
        ];
    }
}
