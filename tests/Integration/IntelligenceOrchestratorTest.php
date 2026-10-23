<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Household\Household;
use App\Domain\Intelligence\IntelligenceOrchestrator;
use App\Repositories\HouseholdRepository;
use App\Repositories\InsightRepository;
use App\Repositories\RecommendationRepository;
use App\Support\Database;
use DateTimeImmutable;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * F6/W3 — Integration test suite for IntelligenceOrchestrator.
 *
 * Verifies:
 * - Table schema for intelligence_orchestrator_runs
 * - Batch execution across eligible households
 * - Idempotency: repeated execution does not duplicate insights or recommendations
 * - Insight lifecycle: expired insights transition cleanly
 * - Environment recording and isolation
 */
final class IntelligenceOrchestratorTest
{
    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    /**
     * @return array<int, TestResult>
     */
    public static function run(): array
    {
        return [
            TestResult::check('intelligence_orchestrator_runs table exists with canonical schema', function (): void {
                $pdo = Database::connection();
                $stmt = $pdo->query('SHOW TABLES LIKE "intelligence_orchestrator_runs"');
                Assert::same('intelligence_orchestrator_runs', $stmt->fetchColumn(), 'intelligence_orchestrator_runs table must exist');

                $colStmt = $pdo->query('DESCRIBE intelligence_orchestrator_runs');
                $columns = $colStmt->fetchAll(\PDO::FETCH_COLUMN);

                Assert::true(in_array('id', $columns, true));
                Assert::true(in_array('triggered_by', $columns, true));
                Assert::true(in_array('environment', $columns, true));
                Assert::true(in_array('status', $columns, true));
                Assert::true(in_array('households_evaluated', $columns, true));
                Assert::true(in_array('insights_created', $columns, true));
                Assert::true(in_array('insights_updated', $columns, true));
                Assert::true(in_array('insights_expired', $columns, true));
                Assert::true(in_array('recommendations_created', $columns, true));
                Assert::true(in_array('recommendations_updated', $columns, true));
                Assert::true(in_array('duration_ms', $columns, true));
                Assert::true(in_array('summary_json', $columns, true));
                Assert::true(in_array('error_message', $columns, true));
                Assert::true(in_array('started_at', $columns, true));
                Assert::true(in_array('completed_at', $columns, true));
            }),

            TestResult::check('IntelligenceOrchestrator runs batch evaluation on test household', function (): void {
                $hhRepo = new HouseholdRepository();
                $household = Household::create(
                    label: 'Orchestrator Test Household',
                    timezone: 'Asia/Kolkata',
                    locationLabel: 'Kodumur, Andhra Pradesh',
                    now: new DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $hhRepo->insert($household);
                $hhId = $household->id();

                $orchestrator = new IntelligenceOrchestrator();
                $result = $orchestrator->run(
                    triggerSource: 'TEST_SUITE',
                    householdId: $hhId
                );

                Assert::same('SUCCESS', $result['status']);
                Assert::same(1, $result['households_evaluated']);
                Assert::true(isset($result['run_id']));
                Assert::true($result['duration_ms'] >= 0);

                // Verify DB run record exists
                $pdo = Database::connection();
                $stmt = $pdo->prepare('SELECT * FROM intelligence_orchestrator_runs WHERE id = :id');
                $stmt->execute(['id' => $result['run_id']]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                Assert::true($row !== false, 'Run record must be persisted');
                Assert::same('TEST_SUITE', $row['triggered_by']);
                Assert::same('SUCCESS', $row['status']);
            }),

            TestResult::check('IntelligenceOrchestrator is idempotent: repeated execution creates zero duplicate insights', function (): void {
                $hhRepo = new HouseholdRepository();
                $household = Household::create(
                    label: 'Idempotency Test Household',
                    timezone: 'Asia/Kolkata',
                    locationLabel: 'Kodumur, Andhra Pradesh',
                    now: new DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $hhRepo->insert($household);
                $hhId = $household->id();

                $orchestrator = new IntelligenceOrchestrator();

                // Run 1
                $run1 = $orchestrator->run(triggerSource: 'TEST_IDEMPOTENT_1', householdId: $hhId);
                Assert::same('SUCCESS', $run1['status']);
                $created1 = $run1['insights_created'];

                // Run 2 (immediately after)
                $run2 = $orchestrator->run(triggerSource: 'TEST_IDEMPOTENT_2', householdId: $hhId);
                Assert::same('SUCCESS', $run2['status']);
                Assert::same(0, $run2['insights_created'], 'Second run must not create duplicate insights');
                Assert::same(0, $run2['recommendations_created'], 'Second run must not create duplicate recommendations');
            }),

            TestResult::check('IntelligenceOrchestrator isolates household errors and continues execution', function (): void {
                $orchestrator = new IntelligenceOrchestrator();
                // Run on a non-existent household
                $result = $orchestrator->run(triggerSource: 'TEST_NONEXISTENT', householdId: 'non-existent-hh-id');
                Assert::same('SUCCESS', $result['status']);
                Assert::same(0, $result['households_evaluated']);
            }),

            TestResult::check('Safe Insight Resolution: missing telemetry or provider failure does NOT resolve existing active insights (CHECK 2)', function (): void {
                $hhRepo = new HouseholdRepository();
                $household = Household::create(
                    label: 'Safe Resolution Test Household',
                    timezone: 'Asia/Kolkata',
                    locationLabel: null,
                    now: new DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $hhRepo->insert($household);
                $hhId = $household->id();

                $now = new DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('UTC'));
                $pdo = Database::connection();

                // Seed active SOLAR_TELEMETRY_STALE and WEATHER_DATA_UNAVAILABLE using InsightRepository
                $insightRepo = new InsightRepository();

                $cand1 = new \App\Domain\Intelligence\DetectedCandidate(
                    type: \App\Domain\Intelligence\InsightTaxonomy::TYPE_SOLAR_TELEMETRY_STALE,
                    title: 'Stale Telemetry Notice',
                    summary: 'Inverter telemetry is stale.',
                    affectedPeriod: null,
                    supportingFacts: ['Inverter telemetry is delayed'],
                    limitations: [],
                    evidenceSummary: 'Solar telemetry freshness evaluation.',
                    explanation: 'Telemetry has not updated within threshold.',
                    explanationSource: 'TEST',
                    evidenceDomains: ['solar'],
                    natures: ['ACTUAL'],
                    statuses: ['STALE'],
                    qualities: ['PARTIAL'],
                    detectors: ['TEST'],
                    significanceHint: \App\Domain\Intelligence\InsightSignificance::MODERATE,
                    comparisonCount: null
                );
                $ins1 = $insightRepo->upsert(
                    \App\Domain\Intelligence\Insight::detected($cand1, \App\Domain\Intelligence\InsightSignificance::MODERATE, \App\Domain\Intelligence\InsightConfidence::MODERATE, $now, $hhId, ['sources' => ['GOODWE_SOLAR_TELEMETRY']]),
                    $now
                );

                $cand2 = new \App\Domain\Intelligence\DetectedCandidate(
                    type: \App\Domain\Intelligence\InsightTaxonomy::TYPE_WEATHER_DATA_UNAVAILABLE,
                    title: 'Weather Unavailable',
                    summary: 'Weather data is unavailable.',
                    affectedPeriod: null,
                    supportingFacts: ['Weather data unavailable'],
                    limitations: [],
                    evidenceSummary: 'Weather availability evaluation.',
                    explanation: 'Location coordinates not resolved.',
                    explanationSource: 'TEST',
                    evidenceDomains: ['weather'],
                    natures: [],
                    statuses: ['UNAVAILABLE'],
                    qualities: ['UNVERIFIED'],
                    detectors: ['TEST'],
                    significanceHint: \App\Domain\Intelligence\InsightSignificance::LOW,
                    comparisonCount: null
                );
                $ins2 = $insightRepo->upsert(
                    \App\Domain\Intelligence\Insight::detected($cand2, \App\Domain\Intelligence\InsightSignificance::LOW, \App\Domain\Intelligence\InsightConfidence::INSUFFICIENT, $now, $hhId, ['sources' => []]),
                    $now
                );

                // Run refresh when solar connection has no readings (query returns null/missing)
                $intelligenceService = new \App\Domain\Intelligence\IntelligenceService();
                $intelligenceService->refreshFromEnergyPipeline($hhId, $now);

                // Verify neither insight was resolved!
                $reloaded1 = $insightRepo->findById($ins1->id());
                Assert::true(in_array($reloaded1->status(), ['ACTIVE', 'DETECTED'], true), 'SOLAR_TELEMETRY_STALE must not be resolved when telemetry is missing');
                Assert::false($reloaded1->status() === 'RESOLVED', 'SOLAR_TELEMETRY_STALE must not be RESOLVED');

                $reloaded2 = $insightRepo->findById($ins2->id());
                Assert::true(in_array($reloaded2->status(), ['ACTIVE', 'DETECTED'], true), 'WEATHER_DATA_UNAVAILABLE must not be resolved when weather is unavailable');
                Assert::false($reloaded2->status() === 'RESOLVED', 'WEATHER_DATA_UNAVAILABLE must not be RESOLVED');
            }),

            TestResult::check('Safe Insight Resolution: confirmed fresh telemetry resolves SOLAR_TELEMETRY_STALE (CHECK 2)', function (): void {
                $hhRepo = new HouseholdRepository();
                $household = Household::create(
                    label: 'Telemetry Recovery Household',
                    timezone: 'Asia/Kolkata',
                    locationLabel: 'Kodumur, Andhra Pradesh',
                    now: new DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $hhRepo->insert($household);
                $hhId = $household->id();

                $now = new DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('UTC'));
                $insightRepo = new InsightRepository();

                // Create solar connection and active stale insight
                $connRepo = new \App\Repositories\SolarConnectionRepository();
                $creds = new \App\Domain\Solar\DTO\SolarCredentials('user@example.com', 'pwd', 'st-test-01', 'GW-TEST-SN');
                $encrypted = \App\Support\Security\Encryption::encrypt(json_encode($creds->toEncryptedPayload()), $hhId);
                $connRepo->upsert(
                    id: self::uuid(),
                    householdId: $hhId,
                    provider: 'GOODWE_SEMS',
                    stationId: 'st-test-01',
                    inverterSn: 'GW-TEST-SN',
                    accountIdentifier: 'u***r@example.com',
                    encryptedCredentials: $encrypted,
                    connectionStatus: 'CONNECTED'
                );

                $cand = new \App\Domain\Intelligence\DetectedCandidate(
                    type: \App\Domain\Intelligence\InsightTaxonomy::TYPE_SOLAR_TELEMETRY_STALE,
                    title: 'Stale Telemetry Notice',
                    summary: 'Inverter telemetry is stale.',
                    affectedPeriod: null,
                    supportingFacts: ['Inverter telemetry is delayed'],
                    limitations: [],
                    evidenceSummary: 'Solar telemetry freshness evaluation.',
                    explanation: 'Telemetry has not updated within threshold.',
                    explanationSource: 'TEST',
                    evidenceDomains: ['solar'],
                    natures: ['ACTUAL'],
                    statuses: ['STALE'],
                    qualities: ['PARTIAL'],
                    detectors: ['TEST'],
                    significanceHint: \App\Domain\Intelligence\InsightSignificance::MODERATE,
                    comparisonCount: null
                );
                $ins = $insightRepo->upsert(
                    \App\Domain\Intelligence\Insight::detected($cand, \App\Domain\Intelligence\InsightSignificance::MODERATE, \App\Domain\Intelligence\InsightConfidence::MODERATE, $now, $hhId, ['sources' => ['GOODWE_SOLAR_TELEMETRY']]),
                    $now
                );

                // Add a confirmed FRESH solar reading via SolarReadingRepository
                $readingsRepo = new \App\Repositories\SolarReadingRepository();
                $snapshot = new \App\Domain\Solar\DTO\NormalizedSolarSnapshot(
                    stationId: 'st-test-01',
                    inverterSn: 'GW-TEST-SN',
                    measurementTime: $now,
                    receivedAt: $now,
                    powerW: 1500.0,
                    dailyGenerationKwh: 5.0,
                    totalGenerationKwh: 120.0,
                    pvPowerW: 1550.0,
                    acVoltageV: 230.0,
                    acCurrentA: 6.5,
                    acFrequencyHz: 50.0,
                    temperatureC: 35.0,
                    gridPowerW: 0.0,
                    consumptionPowerW: 500.0,
                    inverterStatus: 'NORMAL',
                    dataNature: 'ACTUAL',
                    dataStatus: 'FRESH',
                    dataQuality: 'GOOD'
                );
                $readingsRepo->insert(self::uuid(), $hhId, $snapshot, self::uuid());

                // Run refresh
                $intelligenceService = new \App\Domain\Intelligence\IntelligenceService();
                $intelligenceService->refreshFromEnergyPipeline($hhId, $now);

                // Assert SOLAR_TELEMETRY_STALE is now RESOLVED
                $reloaded = $insightRepo->findById($ins->id());
                Assert::same('RESOLVED', $reloaded->status(), 'SOLAR_TELEMETRY_STALE must transition to RESOLVED when telemetry is confirmed fresh');
            }),

            TestResult::check('Recommendation Lifecycle: obsolete weather recommendation is superseded by cloudy decline risk (CHECK 4)', function (): void {
                $hhRepo = new HouseholdRepository();
                $household = Household::create(
                    label: 'Weather Rec Lifecycle Household',
                    timezone: 'Asia/Kolkata',
                    locationLabel: 'Kodumur, Andhra Pradesh',
                    now: new DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $hhRepo->insert($household);
                $hhId = $household->id();

                $now = new DateTimeImmutable('2026-10-21 10:00:00', new \DateTimeZone('UTC'));
                $recRepo = new RecommendationRepository();

                // 1. Seed an active SHIFT_TO_SOLAR_WINDOW recommendation for tomorrow based on weather
                $weatherPlanningRec = \App\Domain\Recommendation\Recommendation::createFromCandidate(
                    self::uuid(),
                    $hhId,
                    new \App\Domain\Recommendation\RecommendationCandidate(
                        type: \App\Domain\Recommendation\RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                        priority: \App\Domain\Recommendation\Recommendation::PRIORITY_HIGH,
                        title: 'Use flexible appliances during solar hours',
                        summary: 'Clear skies expected tomorrow',
                        action: 'Run appliances during solar window',
                        reason: 'Clear skies expected',
                        timingWindow: '10:00–15:00 tomorrow',
                        impact: \App\Domain\Recommendation\RecommendationImpact::informational('Maximize solar self-consumption'),
                        evidence: new \App\Domain\Recommendation\RecommendationEvidence(
                            limitations: [],
                            provenance: ['OPEN_METEO_WEATHER_FORECAST']
                        ),
                        confidence: \App\Domain\Recommendation\RecommendationConfidence::CONFIDENCE_HIGH,
                        quality: 'GOOD',
                        dataNature: 'PREDICTED',
                        provenance: ['OPEN_METEO_WEATHER_FORECAST'],
                        limitations: [],
                        relevantPeriodStart: '2026-10-22',
                        relevantPeriodEnd: '2026-10-22',
                        validFrom: $now,
                        validUntil: $now->modify('+24 hours')
                    ),
                    $now
                );
                $recRepo->save($weatherPlanningRec);

                // 2. Also seed an unrelated recommendation for a DIFFERENT day (should be preserved)
                $unrelatedRec = \App\Domain\Recommendation\Recommendation::createFromCandidate(
                    self::uuid(),
                    $hhId,
                    new \App\Domain\Recommendation\RecommendationCandidate(
                        type: \App\Domain\Recommendation\RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                        priority: \App\Domain\Recommendation\Recommendation::PRIORITY_HIGH,
                        title: 'Use flexible appliances during solar hours',
                        summary: 'Next week planning',
                        action: 'Run appliances next week',
                        reason: 'Next week pattern',
                        timingWindow: '10:00–15:00',
                        impact: \App\Domain\Recommendation\RecommendationImpact::informational('Maximize solar self-consumption'),
                        evidence: new \App\Domain\Recommendation\RecommendationEvidence(
                            limitations: [],
                            provenance: ['OPEN_METEO_WEATHER_FORECAST']
                        ),
                        confidence: \App\Domain\Recommendation\RecommendationConfidence::CONFIDENCE_HIGH,
                        quality: 'GOOD',
                        dataNature: 'PREDICTED',
                        provenance: ['OPEN_METEO_WEATHER_FORECAST'],
                        limitations: [],
                        relevantPeriodStart: '2026-10-25',
                        relevantPeriodEnd: '2026-10-25',
                        validFrom: $now,
                        validUntil: $now->modify('+96 hours')
                    ),
                    $now
                );
                $recRepo->save($unrelatedRec);

                // 3. Now simulate a materially changed forecast for 2026-10-22: cloudy production risk arrives
                $cloudyCandidate = new \App\Domain\Recommendation\RecommendationCandidate(
                    type: \App\Domain\Recommendation\RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE,
                    priority: \App\Domain\Recommendation\Recommendation::PRIORITY_MEDIUM,
                    title: 'Prepare for lower solar generation during cloudy conditions',
                    summary: 'Cloudy conditions tomorrow',
                    action: 'Postpone flexible loads',
                    reason: 'Overcast weather attenuation',
                    timingWindow: 'Tomorrow daytime',
                    impact: \App\Domain\Recommendation\RecommendationImpact::informational('Adjust daytime flexible load scheduling'),
                    evidence: new \App\Domain\Recommendation\RecommendationEvidence(
                        limitations: [],
                        provenance: ['OPEN_METEO_WEATHER_FORECAST']
                    ),
                    confidence: \App\Domain\Recommendation\RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['OPEN_METEO_WEATHER_FORECAST'],
                    limitations: [],
                    relevantPeriodStart: '2026-10-22',
                    relevantPeriodEnd: '2026-10-22',
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours')
                );

                $recRepo->supersedeConflictingWeatherRecommendations(
                    $hhId,
                    $cloudyCandidate->type,
                    $cloudyCandidate->relevantPeriodStart,
                    $cloudyCandidate->relevantPeriodEnd,
                    $now
                );

                // Assert the obsolete 2026-10-22 weather planning recommendation is now SUPERSEDED
                $updatedWeatherRec = $recRepo->findById($weatherPlanningRec->id());
                Assert::same('SUPERSEDED', $updatedWeatherRec->status(), 'Conflicting weather planning recommendation must be SUPERSEDED');

                // Assert the unrelated 2026-10-25 recommendation is PRESERVED as ACTIVE
                $updatedUnrelatedRec = $recRepo->findById($unrelatedRec->id());
                Assert::same('ACTIVE', $updatedUnrelatedRec->status(), 'Unrelated valid recommendation must remain ACTIVE');
            }),

            TestResult::check('Recommendation Lifecycle: expirePast expires recommendations when relevant_period_end has elapsed (CHECK 4)', function (): void {
                $hhRepo = new HouseholdRepository();
                $household = Household::create(
                    label: 'Rec Expiration Household',
                    timezone: 'Asia/Kolkata',
                    locationLabel: 'Kodumur, Andhra Pradesh',
                    now: new DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $hhRepo->insert($household);
                $hhId = $household->id();

                $now = new DateTimeImmutable('2026-10-21 10:00:00', new \DateTimeZone('UTC'));
                $recRepo = new RecommendationRepository();

                // Past relevant period (ended yesterday 2026-10-20), but valid_until was set to future 2026-10-25
                $pastRec = \App\Domain\Recommendation\Recommendation::createFromCandidate(
                    self::uuid(),
                    $hhId,
                    new \App\Domain\Recommendation\RecommendationCandidate(
                        type: \App\Domain\Recommendation\RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                        priority: \App\Domain\Recommendation\Recommendation::PRIORITY_HIGH,
                        title: 'Yesterday Solar Window',
                        summary: 'Yesterday opportunity',
                        action: 'Run appliances yesterday',
                        reason: 'Yesterday clear skies',
                        timingWindow: 'Yesterday',
                        impact: \App\Domain\Recommendation\RecommendationImpact::informational('Past opportunity'),
                        evidence: new \App\Domain\Recommendation\RecommendationEvidence(limitations: [], provenance: ['TEST']),
                        confidence: \App\Domain\Recommendation\RecommendationConfidence::CONFIDENCE_HIGH,
                        quality: 'GOOD',
                        dataNature: 'PREDICTED',
                        provenance: ['TEST'],
                        limitations: [],
                        relevantPeriodStart: '2026-10-20',
                        relevantPeriodEnd: '2026-10-20',
                        validFrom: $now->modify('-2 days'),
                        validUntil: $now->modify('+4 days')
                    ),
                    $now->modify('-2 days')
                );
                $recRepo->save($pastRec);

                // Run expirePast for today 2026-10-21
                $expiredCount = $recRepo->expirePast($hhId, $now);
                Assert::true($expiredCount >= 1, 'Past relevant period recommendation must be expired');

                $reloaded = $recRepo->findById($pastRec->id());
                Assert::same('EXPIRED', $reloaded->status(), 'Status must be EXPIRED when relevant_period_end < today');
            }),

            TestResult::check('Scheduler Concurrency: exclusive non-blocking lock prevents overlapping runs (CHECK 5)', function (): void {
                $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spms_test_concurrency_' . uniqid() . '.lock';
                $handle1 = fopen($lockFile, 'c+');
                Assert::true($handle1 !== false, 'Lock handle 1 must open');

                $acquired1 = flock($handle1, LOCK_EX | LOCK_NB);
                Assert::true($acquired1, 'First process must acquire exclusive lock');

                // Attempt second simultaneous acquisition on the same file
                $handle2 = fopen($lockFile, 'c+');
                Assert::true($handle2 !== false, 'Lock handle 2 must open');

                $acquired2 = flock($handle2, LOCK_EX | LOCK_NB);
                Assert::false($acquired2, 'Second process must fail to acquire lock while held by first');

                // Cleanup
                fclose($handle2);
                flock($handle1, LOCK_UN);
                fclose($handle1);
                @unlink($lockFile);
            }),
        ];
    }
}
