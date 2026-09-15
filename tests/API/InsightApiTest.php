<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\IntelligenceController;
use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Energy\EnergyCostService;
use App\Domain\Intelligence\EnergyIntelligenceAdapter;
use App\Domain\Intelligence\InsightEngine;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\InsightLifecycle;
use App\Domain\Intelligence\IntelligenceService;
use App\Repositories\InsightRepository;
use App\Support\AppException;
use App\Support\MethodNotAllowedException;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * F6 — Intelligence API boundary tests (docs/02 §2 F6, docs/10 D-046/D-047).
 *
 * Covers the controlled internal generation seam (the only place detection
 * and persistence happen), strictly read-only GET endpoints (no INSERT or
 * UPDATE on reads, repeated GETs leave counters/timestamps unchanged),
 * validated filters, household ownership scoping, the detail endpoint,
 * the simulated evidence path, duplicate-key convergence under a lost
 * first-observation race, and the absence of any public generation
 * endpoint.
 *
 * W3 (docs/10 D-050, D-057 §4): reads are authenticated and the effective
 * household scope is derived server-side from membership; fixtures use
 * real household rows (the synthetic 'f6-hh-*' identifiers are no longer
 * referencable since migration 0007). Insight semantics are unchanged.
 */
final class InsightApiTest
{
    private static function listController(): IntelligenceController
    {
        return new IntelligenceController();
    }

    /**
     * @param array<string, string> $query
     */
    private static function authedList(Request $request, array $fixture): mixed
    {
        return self::listController()->list(AuthTesting::authed($request, $fixture['account']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function simulatedEstimate(float $consumptionKwh, float $unitRate, string $day): array
    {
        return (new EnergyCostService())->estimate(
            [
                'solar' => ['value_kwh' => 0.0, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                'consumption' => ['value_kwh' => $consumptionKwh, 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                'pricing' => ['basis' => 'USER_PROVIDED_RATE', 'unit_rate' => $unitRate, 'currency' => 'INR'],
            ],
            ['start' => $day, 'end' => $day, 'resolution' => 'DAY']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function classifiedBalance(string $classification, float $balanceKwh, string $start): array
    {
        $payload = (new EnergyBalanceService())->classify(
            [
                'solar' => ['value_kwh' => max(0.0, $balanceKwh), 'nature' => 'SIMULATED', 'status' => 'FRESH'],
                'consumption' => ['value_kwh' => max(0.0, -$balanceKwh), 'nature' => 'SIMULATED', 'status' => 'FRESH'],
            ],
            'TIME_PERIOD',
            ['start' => $start, 'end' => $start, 'resolution' => 'HOUR']
        );

        // Mirror the adapter normalization: F4 echoes the period inside
        // analysis.period; the canonical evidence shape expects it on top.
        $payload['period'] = $payload['analysis']['period'] ?? null;

        return $payload;
    }

    public static function run(): array
    {
        return [
            TestResult::check('Insight reads require authentication; anonymous list/detail are rejected', function (): void {
                AuthTesting::configure();
                RequestId::set(RequestId::new());

                foreach (['list', 'show'] as $action) {
                    try {
                        self::listController()->{$action}(Request::create(
                            'GET',
                            $action === 'list'
                                ? '/api/v1/intelligence/insights'
                                : '/api/v1/intelligence/insights/00000000-0000-4000-8000-000000000000'
                        ));
                        Assert::true(false, 'Expected AppException for anonymous ' . $action);
                    } catch (AppException $e) {
                        Assert::same('UNAUTHENTICATED', $e->errorCode());
                        Assert::same(401, $e->httpStatus());
                    }
                }
            }),

            TestResult::check('GET insights returns 200 envelope with insights, count and echoed filters', function (): void {
                $fixture = AuthTesting::householdFixture();
                RequestId::set(RequestId::new());

                $response = self::authedList(
                    Request::create('GET', '/api/v1/intelligence/insights'),
                    $fixture
                );

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same(null, $body['error']);
                Assert::true(is_array($body['data']['insights']));
                Assert::same(count($body['data']['insights']), $body['data']['count']);
                Assert::same('DETERMINISTIC_V1', $body['data']['generation']['mode']);
                Assert::same(50, $body['data']['filters']['limit']);

                // W3: the echoed household scope is the server-derived
                // membership household, not a client choice.
                Assert::same($fixture['household_id'], $body['data']['filters']['household_id']);
                foreach ($body['data']['insights'] as $insight) {
                    Assert::true(
                        $insight['household_id'] === null || $insight['household_id'] === $fixture['household_id'],
                        'Reads never surface another household\'s insights'
                    );
                }

                ob_start();
                $response->send();
                $raw = (string) ob_get_clean();
                $sent = Assert::jsonDecodes($raw);
                Assert::same(RequestId::current(), $sent['meta']['request_id']);
            }),

            TestResult::check('Controlled internal seam persists one deduplicated data limitation insight', function (): void {
                $fixture = AuthTesting::householdFixture();
                $repository = new InsightRepository();
                $service = new IntelligenceService($repository);

                $limitationRows = static fn (): int => count(array_filter(
                    $repository->list(['type' => 'DATA_LIMITATION', 'limit' => 200]),
                    static fn ($insight): bool => $insight->householdId() === null
                ));

                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $created = $service->refreshFromEnergyPipeline(null, $now);

                // Fresh-database-safe (D-071 W): the first controlled
                // evaluation CREATES the one honest limitation insight;
                // any evaluation on a database that already has it
                // converges on the same row instead. Either way the
                // global total stays exactly one.
                Assert::same(1, count($created));
                Assert::same(1, $limitationRows(), 'a controlled evaluation leaves exactly one global limitation insight');
                $row = $created[0]->toArray();
                Assert::same('DATA_LIMITATION', $row['type']);
                Assert::same(null, $row['household_id']);
                Assert::same(null, $row['data_nature']);
                Assert::same('UNAVAILABLE', $row['data_status']);
                Assert::same('LOW', $row['significance']);
                Assert::same('INSUFFICIENT', $row['confidence']);
                Assert::true(count($row['limitations']) >= 2);
                Assert::true(in_array($row['status'], ['DETECTED', 'VALIDATED', 'ACTIVE', 'UPDATED'], true));

                // A repeated controlled evaluation converges on the same
                // row (dedup) as a re-observation.
                $later = $now->modify('+1 minute');
                $repeat = $service->refreshFromEnergyPipeline(null, $later);
                Assert::same(1, count($repeat));
                Assert::same($created[0]->id(), $repeat[0]->id());
                Assert::same($created[0]->observationCount() + 1, $repeat[0]->observationCount());
                Assert::same(1, $limitationRows(), 'Repeated controlled evaluations must not duplicate the limitation insight');

                // The persisted insight is queryable through the read path.
                $listed = self::authedList(
                    Request::create('GET', '/api/v1/intelligence/insights', query: ['type' => 'DATA_LIMITATION']),
                    $fixture
                );
                Assert::same(200, $listed->status());
                Assert::true($listed->body()['data']['count'] >= 1);
            }),

            TestResult::check('GET /insights is strictly read-only: no INSERT or UPDATE on reads', function (): void {
                $fixture = AuthTesting::householdFixture();
                $repository = new InsightRepository();

                $snapshot = static function () use ($repository): array {
                    $state = ['count' => 0, 'rows' => []];
                    foreach ($repository->list(['limit' => 200]) as $insight) {
                        $state['rows'][$insight->id()] = [
                            $insight->observationCount(),
                            $insight->toArray()['last_observed_at'],
                        ];
                        $state['count']++;
                    }

                    return $state;
                };

                $before = $snapshot();

                self::authedList(Request::create('GET', '/api/v1/intelligence/insights'), $fixture);
                self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: [
                    'category' => 'GENERAL',
                    'significance' => 'LOW',
                    'status' => 'ONGOING',
                    'limit' => '10',
                ]), $fixture);
                self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: [
                    'type' => 'DATA_LIMITATION',
                ]), $fixture);

                $after = $snapshot();

                Assert::same($before['count'], $after['count'], 'Reads must not INSERT insight rows');
                Assert::same($before['rows'], $after['rows'], 'Reads must not UPDATE observation_count or last_observed_at');
            }),

            TestResult::check('Filters are backend-owned: valid filters apply, unknown values are rejected', function (): void {
                $fixture = AuthTesting::householdFixture();

                $response = self::authedList(
                    Request::create('GET', '/api/v1/intelligence/insights', query: ['category' => 'GENERAL', 'significance' => 'LOW']),
                    $fixture
                );
                $body = $response->body();
                Assert::same(200, $response->status());
                Assert::same('GENERAL', $body['data']['filters']['category']);
                Assert::same('LOW', $body['data']['filters']['significance']);
                foreach ($body['data']['insights'] as $insight) {
                    Assert::same('GENERAL', $insight['category']);
                    Assert::same('LOW', $insight['significance']);
                }

                try {
                    self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: ['type' => 'MAGIC_TYPE']), $fixture);
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('type', $e->context()['field']);
                }

                try {
                    self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: ['limit' => '0']), $fixture);
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('limit', $e->context()['field']);
                }

                try {
                    self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: ['status' => 'MAGIC_STATE']), $fixture);
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('VALIDATION_ERROR', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                    Assert::same('status', $e->context()['field']);
                }
            }),

            TestResult::check('ONGOING status filter is normalized to UPDATED, never persisted verbatim', function (): void {
                $fixture = AuthTesting::householdFixture();

                $response = self::authedList(
                    Request::create('GET', '/api/v1/intelligence/insights', query: ['status' => 'ONGOING']),
                    $fixture
                );

                Assert::same(200, $response->status());
                Assert::same('UPDATED', $response->body()['data']['filters']['status']);
            }),

            TestResult::check('Detail endpoint returns one persisted insight and honest 404 for unknown ids', function (): void {
                $fixture = AuthTesting::householdFixture();
                $controller = self::listController();

                // System-scope insight (NULL household): visible to every
                // authenticated household through the derived scope rule.
                $listResponse = self::authedList(
                    Request::create('GET', '/api/v1/intelligence/insights', query: ['type' => 'DATA_LIMITATION']),
                    $fixture
                );
                $insights = $listResponse->body()['data']['insights'];
                Assert::true(count($insights) >= 1);

                $knownId = (string) $insights[0]['id'];

                $showResponse = $controller->show(AuthTesting::authed(
                    Request::create('GET', '/api/v1/intelligence/insights/' . $knownId),
                    $fixture['account']
                ));
                Assert::same(200, $showResponse->status());
                Assert::same(true, $showResponse->body()['success']);
                Assert::same($knownId, $showResponse->body()['data']['insight']['id']);
                Assert::true(isset($showResponse->body()['data']['insight']['explanation_source']));
                Assert::true(isset($showResponse->body()['data']['insight']['provenance']['engine']));

                try {
                    $controller->show(AuthTesting::authed(
                        Request::create('GET', '/api/v1/intelligence/insights/00000000-0000-4000-8000-000000000000'),
                        $fixture['account']
                    ));
                    Assert::true(false, 'Expected AppException was not thrown');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }
            }),

            TestResult::check('Household insights are isolated server-side from authenticated membership', function (): void {
                $householdA = AuthTesting::householdFixture();
                $householdB = AuthTesting::householdFixture();

                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $engine = new InsightEngine();
                $repository = new InsightRepository();

                $persistFor = static function (string $householdId, string $day) use ($engine, $repository, $now): array {
                    $evidence = InsightEvidenceInput::fromArray([
                        'household_id' => $householdId,
                        'balance' => [
                            self::classifiedBalance('POTENTIAL_SURPLUS', 2.0, $day . 'T12:00:00'),
                            self::classifiedBalance('POTENTIAL_DEFICIT', -2.0, $day . 'T19:00:00'),
                        ],
                    ]);

                    $created = [];
                    foreach ($engine->evaluate($evidence, $now) as $insight) {
                        $created[] = $repository->upsert($insight, $now);
                    }

                    return $created;
                };

                $day = '2026-09-0' . random_int(1, 8);
                $insightsA = $persistFor($householdA['household_id'], $day);
                $insightsB = $persistFor($householdB['household_id'], $day);
                Assert::same(2, count($insightsA), 'Expected mismatch and daytime/evening insights');
                Assert::same(2, count($insightsB));

                // A's listing never contains B's rows.
                $listA = self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: [
                    'limit' => '200',
                ]), $householdA);
                $rowsA = $listA->body()['data']['insights'];
                foreach ($rowsA as $insight) {
                    Assert::true(
                        $insight['household_id'] === $householdA['household_id'] || $insight['household_id'] === null,
                        'A must never see another household\'s insights'
                    );
                }
                $idsA = array_map(static fn (array $i): string => $i['id'], $rowsA);
                foreach ($insightsB as $bInsight) {
                    Assert::true(!in_array($bInsight->id(), $idsA, true), 'B-scoped insight leaked into A listing');
                }

                // Types are visible to their owner (evidence semantics intact).
                $typesA = array_map(static fn (array $i): string => $i['type'], array_values(array_filter(
                    $rowsA,
                    static fn (array $i): bool => $i['household_id'] === $householdA['household_id']
                )));
                Assert::true(in_array('SOLAR_DEMAND_MISMATCH', $typesA, true));
                Assert::true(in_array('DAYTIME_SURPLUS_EVENING_DEFICIT', $typesA, true));

                // Cross-household detail: 404 for B, 200 for A — existence
                // is not disclosed and no client claim can change that.
                $bId = (string) $insightsB[0]->id();
                $aId = (string) $insightsA[0]->id();

                try {
                    self::listController()->show(AuthTesting::authed(
                        Request::create('GET', '/api/v1/intelligence/insights/' . $bId),
                        $householdA['account']
                    ));
                    Assert::true(false, 'Expected 404 for cross-household detail');
                } catch (AppException $e) {
                    Assert::same('NOT_FOUND', $e->errorCode());
                    Assert::same(404, $e->httpStatus());
                }

                $matching = self::listController()->show(AuthTesting::authed(
                    Request::create('GET', '/api/v1/intelligence/insights/' . $aId),
                    $householdA['account']
                ));
                Assert::same(200, $matching->status());
                Assert::same($aId, $matching->body()['data']['insight']['id']);

                // A client-supplied household_id can only restate the
                // caller's own derived scope; any other claim is rejected
                // outright (403) — never honored.
                try {
                    self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: [
                        'household_id' => $householdB['household_id'],
                    ]), $householdA);
                    Assert::true(false, 'Expected 403 for a foreign household claim');
                } catch (AppException $e) {
                    Assert::same('HOUSEHOLD_ACCESS_DENIED', $e->errorCode());
                    Assert::same(403, $e->httpStatus());
                }

                $restated = self::authedList(Request::create('GET', '/api/v1/intelligence/insights', query: [
                    'household_id' => $householdA['household_id'],
                ]), $householdA);
                Assert::same(200, $restated->status());

                // An account with no household sees only system-scope rows.
                $accountless = AuthTesting::activateAccount();
                $listEmpty = self::listController()->list(AuthTesting::authed(
                    Request::create('GET', '/api/v1/intelligence/insights'),
                    $accountless
                ));
                Assert::same(null, $listEmpty->body()['data']['filters']['household_id']);
                foreach ($listEmpty->body()['data']['insights'] as $insight) {
                    Assert::same(null, $insight['household_id'], 'Membership-less accounts see system scope only');
                }
            }),

            TestResult::check('Simulated evidence path stays simulated end to end with dedup lifecycle', function (): void {
                $householdId = AuthTesting::bareHouseholdId();

                $evidence = EnergyIntelligenceAdapter::fromF4F5ResultsWithEvidence(
                    null,
                    null,
                    [
                        'cost' => [
                            self::simulatedEstimate(10.0, 10.0, '2026-09-01'),
                            self::simulatedEstimate(20.0, 15.0, '2026-09-02'),
                        ],
                    ],
                    $householdId
                );

                $engine = new InsightEngine();
                $repository = new InsightRepository();
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                $candidates = $engine->evaluate($evidence, $now);
                Assert::same(1, count($candidates));
                Assert::same('COST_INCREASE', $candidates[0]->type());

                $first = $repository->upsert($candidates[0], $now);
                Assert::same('SIMULATED', $first->dataNature());
                Assert::same(InsightLifecycle::DETECTED, $first->status());
                Assert::same(1, $first->observationCount());

                $later = $now->modify('+1 minute');
                $repeat = $repository->upsert($engine->evaluate($evidence, $later)[0], $later);
                Assert::same(InsightLifecycle::VALIDATED, $repeat->status());
                Assert::same(2, $repeat->observationCount());

                $stored = $repository->findById((string) $first->id());
                Assert::true($stored !== null);
                Assert::same($householdId, $stored->householdId());
                Assert::same('SIMULATED', $stored->dataNature());
                Assert::same('MODERATE', $stored->significance());
                Assert::same('LOW', $stored->confidence());
                Assert::true(str_contains(strtolower($stored->toArray()['explanation']), 'estimate'), 'Cost insights must stay framed as estimates');
            }),

            TestResult::check('Duplicate-key convergence: a lost first-observation race merges, never fails', function (): void {
                $householdId = AuthTesting::bareHouseholdId();

                $evidence = InsightEvidenceInput::fromArray([
                    'household_id' => $householdId,
                    'balance' => [
                        self::classifiedBalance('POTENTIAL_SURPLUS', 2.0, '2026-09-05T12:00:00'),
                        self::classifiedBalance('POTENTIAL_SURPLUS', 3.0, '2026-09-05T13:00:00'),
                    ],
                ]);

                $candidates = (new InsightEngine())->evaluate($evidence, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                Assert::same(1, count($candidates));
                Assert::same('RECURRING_POTENTIAL_SURPLUS', $candidates[0]->type());

                $repository = new InsightRepository();
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $first = $repository->upsert($candidates[0], $now);
                Assert::same(InsightLifecycle::DETECTED, $first->status());
                Assert::same(1, $first->observationCount());

                // Simulate the lost race deterministically: the concurrent
                // writer's dedup SELECT misses (as under true concurrency),
                // so its INSERT hits the unique key created by $first. The
                // repository must recover by re-reading and merging instead
                // of surfacing a duplicate-key 500.
                $racing = new class extends InsightRepository {
                    protected bool $missNextLookup = true;

                    protected function findByDedupKey(Insight $candidate, PDO $pdo): ?Insight
                    {
                        if ($this->missNextLookup) {
                            $this->missNextLookup = false;

                            return null;
                        }

                        return parent::findByDedupKey($candidate, $pdo);
                    }
                };

                $later = $now->modify('+1 minute');
                $recovered = $racing->upsert($candidates[0], $later);

                Assert::same($first->id(), $recovered->id(), 'Lost race must converge on the winning row');
                Assert::same(InsightLifecycle::VALIDATED, $recovered->status());
                Assert::same(2, $recovered->observationCount());
                Assert::same($first->toArray()['first_detected_at'], $recovered->toArray()['first_detected_at']);

                // No duplicate row was created by the recovery path.
                $rows = array_filter(
                    (new InsightRepository())->list(['household_id' => $householdId, 'type' => 'RECURRING_POTENTIAL_SURPLUS', 'limit' => 200]),
                    static fn ($insight): bool => $insight->affectedPeriod()['start'] === '2026-09-05T12:00:00'
                );
                Assert::same(1, count($rows), 'Duplicate-key recovery must not create a second row');
            }),

            TestResult::check('Router dispatches intelligence routes; no public generation endpoint exists', function (): void {
                $router = new Router();
                $router->add('GET', '/api/v1/intelligence/insights', [IntelligenceController::class, 'list']);
                $router->add('GET', '/api/v1/intelligence/insights/{id}', [IntelligenceController::class, 'show']);

                [$listClass, $listAction] = $router->dispatch('GET', '/api/v1/intelligence/insights');
                Assert::same(IntelligenceController::class, $listClass);
                Assert::same('list', $listAction);

                [$showClass, $showAction] = $router->dispatch('GET', '/api/v1/intelligence/insights/some-id-123');
                Assert::same(IntelligenceController::class, $showClass);
                Assert::same('show', $showAction);

                Assert::throws(MethodNotAllowedException::class, function () use ($router): void {
                    $router->dispatch('POST', '/api/v1/intelligence/insights');
                });

                Assert::throws(\App\Support\NotFoundException::class, function () use ($router): void {
                    $router->dispatch('GET', '/api/v1/intelligence/insights/a/b');
                });
            }),
        ];
    }
}
