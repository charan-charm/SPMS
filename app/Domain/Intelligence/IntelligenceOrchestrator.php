<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Recommendation\RecommendationService;
use App\Repositories\HouseholdRepository;
use App\Repositories\InsightRepository;
use App\Repositories\RecommendationRepository;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * SPMS Automatic Intelligence Orchestration System (docs/01 §14, §15; docs/02 §2; docs/10 D-046, D-072).
 *
 * Coordinates the automatic end-to-end intelligence pipeline:
 * 1. Identifies eligible households in current environment (REAL vs DEMO).
 * 2. Ingests and validates latest telemetry, forecasts, weather, and bills.
 * 3. Evaluates deterministic insight detectors (Solar, Weather, Consumption, Balance, Cost, Limitations).
 * 4. Generates and prioritizes actionable recommendations.
 * 5. Manages item lifecycle: deduplication, refreshing, superseding, and expiring past items.
 * 6. Records execution audit metrics and ensures fault isolation (one household failure never halts others).
 */
final class IntelligenceOrchestrator
{
    private readonly HouseholdRepository $householdRepo;
    private readonly IntelligenceService $intelligenceService;
    private readonly RecommendationService $recommendationService;
    private readonly InsightRepository $insightRepo;
    private readonly RecommendationRepository $recommendationRepo;
    private readonly Logger $logger;

    public function __construct(
        ?HouseholdRepository $householdRepo = null,
        ?IntelligenceService $intelligenceService = null,
        ?RecommendationService $recommendationService = null,
        ?InsightRepository $insightRepo = null,
        ?RecommendationRepository $recommendationRepo = null,
        ?Logger $logger = null
    ) {
        $this->householdRepo = $householdRepo ?? new HouseholdRepository();
        $this->insightRepo = $insightRepo ?? new InsightRepository();
        $this->intelligenceService = $intelligenceService ?? new IntelligenceService($this->insightRepo);
        $this->recommendationService = $recommendationService ?? new RecommendationService();
        $this->recommendationRepo = $recommendationRepo ?? new RecommendationRepository();
        $this->logger = $logger ?? Logger::default();
    }

    /**
     * Run the complete automatic intelligence orchestration pipeline.
     *
     * @param string|null $targetHouseholdId Optional specific household to evaluate
     * @param DateTimeImmutable|null $referenceTime Optional reference evaluation timestamp
     * @param string $triggeredBy Execution source (e.g. 'CLI_CRON', 'DAEMON', 'API_EVENT')
     * @return array<string, mixed> Structured execution metrics and summary
     */
    public function run(
        string $triggerSource = 'CLI_CRON',
        ?string $householdId = null,
        ?DateTimeImmutable $referenceTime = null
    ): array {
        return $this->orchestrate($householdId, $referenceTime, $triggerSource);
    }

    public function orchestrate(
        ?string $targetHouseholdId = null,
        ?DateTimeImmutable $referenceTime = null,
        string $triggeredBy = 'CLI_CRON'
    ): array {
        $startedAt = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $startTime = microtime(true);
        $runId = self::uuid();
        $environment = (string) Config::get('SPMS_DATA_ENVIRONMENT', 'real');

        $this->logger->info('Intelligence orchestrator run started', [
            'run_id' => $runId,
            'environment' => $environment,
            'triggered_by' => $triggeredBy,
            'target_household' => $targetHouseholdId,
        ]);

        // 1. Identify eligible households
        $households = $this->resolveEligibleHouseholds($targetHouseholdId);
        $totalHouseholds = count($households);

        $insightsCreated = 0;
        $insightsUpdated = 0;
        $insightsExpired = 0;
        $recommendationsCreated = 0;
        $recommendationsUpdated = 0;
        $recommendationsExpired = 0;
        $householdErrors = [];

        // 2. Evaluate each household with strict fault isolation
        foreach ($households as $household) {
            $householdId = (string) $household['id'];
            $householdName = (string) ($household['name'] ?? $householdId);

            try {
                // A. Expire past insights and recommendations for this household
                $expiredInsightsCount = $this->insightRepo->expirePast($householdId, $startedAt);
                $insightsExpired += $expiredInsightsCount;

                $this->recommendationRepo->expirePast($householdId, $startedAt);

                // B. Deterministic Insight Evaluation & Persistence (F6)
                $existingActiveInsights = $this->insightRepo->list([
                    'household_id' => $householdId,
                    'status' => 'ACTIVE',
                ]);
                $existingInsightCount = count($existingActiveInsights);

                $refreshedInsights = $this->intelligenceService->refreshFromEnergyPipeline($householdId, $startedAt);

                foreach ($refreshedInsights as $insight) {
                    if ($insight->observationCount() === 1) {
                        $insightsCreated++;
                    } else {
                        $insightsUpdated++;
                    }
                }

                // C. Actionable Recommendation Generation & Deduplication (F7)
                $existingActiveRecs = $this->recommendationRepo->list($householdId, [
                    'status' => \App\Domain\Recommendation\Recommendation::STATUS_ACTIVE,
                ]);
                $existingRecIds = array_map(static fn($r) => $r->id(), $existingActiveRecs);

                $activeRecs = $this->recommendationService->generate($householdId, $startedAt, forceFresh: false);

                foreach ($activeRecs as $rec) {
                    if (in_array($rec->id(), $existingRecIds, true)) {
                        $recommendationsUpdated++;
                    } else {
                        $recommendationsCreated++;
                    }
                }

                $this->logger->debug('Household intelligence orchestrated successfully', [
                    'run_id' => $runId,
                    'household_id' => $householdId,
                    'insights_count' => count($refreshedInsights),
                    'recommendations_count' => count($activeRecs),
                ]);
            } catch (Throwable $e) {
                $householdErrors[$householdId] = $e->getMessage();
                $this->logger->error('Household intelligence orchestration failed', [
                    'run_id' => $runId,
                    'household_id' => $householdId,
                    'error' => $e->getMessage(),
                ]);
                // Strict fault isolation: continue to next household
                continue;
            }
        }

        $completedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        $status = 'SUCCESS';
        if (!empty($householdErrors)) {
            $status = count($householdErrors) === $totalHouseholds ? 'FAILED' : 'PARTIAL_SUCCESS';
        }

        $summary = [
            'run_id' => $runId,
            'status' => $status,
            'environment' => $environment,
            'triggered_by' => $triggeredBy,
            'households_evaluated' => $totalHouseholds,
            'insights_created' => $insightsCreated,
            'insights_updated' => $insightsUpdated,
            'insights_expired' => $insightsExpired,
            'recommendations_created' => $recommendationsCreated,
            'recommendations_updated' => $recommendationsUpdated,
            'duration_ms' => $durationMs,
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'completed_at' => $completedAt->format('Y-m-d H:i:s'),
            'errors' => $householdErrors,
        ];

        // 3. Persist run audit record
        $this->recordRunAudit($summary);

        $this->logger->info('Intelligence orchestrator run completed', [
            'run_id' => $runId,
            'status' => $status,
            'duration_ms' => $durationMs,
            'insights_created' => $insightsCreated,
            'recommendations_created' => $recommendationsCreated,
            'errors_count' => count($householdErrors),
        ]);

        return $summary;
    }

    /**
     * Resolve eligible households for evaluation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveEligibleHouseholds(?string $targetHouseholdId): array
    {
        $pdo = Database::connection();

        if ($targetHouseholdId !== null && trim($targetHouseholdId) !== '') {
            $stmt = $pdo->prepare('SELECT id, label, location_label, timezone, status FROM households WHERE id = :hid LIMIT 1');
            $stmt->execute(['hid' => trim($targetHouseholdId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? [$row] : [];
        }

        // Evaluate all active households
        $stmt = $pdo->query("SELECT id, label, location_label, timezone, status FROM households WHERE status = 'ACTIVE' ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Record execution metrics into intelligence_orchestrator_runs table.
     *
     * @param array<string, mixed> $summary
     */
    private function recordRunAudit(array $summary): void
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO intelligence_orchestrator_runs
                    (id, environment, triggered_by, status, households_evaluated,
                     insights_created, insights_updated, insights_expired,
                     recommendations_created, recommendations_updated,
                     duration_ms, started_at, completed_at, error_message, summary_json)
                 VALUES
                    (:id, :environment, :triggered_by, :status, :households_evaluated,
                     (:insights_created), (:insights_updated), (:insights_expired),
                     (:recommendations_created), (:recommendations_updated),
                     (:duration_ms), :started_at, :completed_at, :error_message, :summary_json)'
            );

            $errorMessage = !empty($summary['errors'])
                ? json_encode($summary['errors'], JSON_UNESCAPED_SLASHES)
                : null;

            $stmt->execute([
                'id' => $summary['run_id'],
                'environment' => $summary['environment'],
                'triggered_by' => $summary['triggered_by'],
                'status' => $summary['status'],
                'households_evaluated' => $summary['households_evaluated'],
                'insights_created' => $summary['insights_created'],
                'insights_updated' => $summary['insights_updated'],
                'insights_expired' => $summary['insights_expired'],
                'recommendations_created' => $summary['recommendations_created'],
                'recommendations_updated' => $summary['recommendations_updated'],
                'duration_ms' => $summary['duration_ms'],
                'started_at' => $summary['started_at'],
                'completed_at' => $summary['completed_at'],
                'error_message' => $errorMessage,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to persist orchestrator run audit record', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
