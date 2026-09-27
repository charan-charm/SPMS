<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Intelligence\Insight;
use App\Repositories\BillRepository;
use App\Repositories\InsightRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SolarReadingRepository;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * SPMS F7 — Recommendation Application Service (docs/01 §15, docs/02 §2 F7, docs/10 D-072).
 *
 * Coordinates loading authoritative outputs from F3/F4/F5/F6/GoodWe/Bills,
 * evaluating deterministic recommendation rules, managing lifecycle transitions
 * (active, expired, dismissed, superseded), and persisting deduplicated recommendations.
 */
final class RecommendationService
{
    private readonly RecommendationRepository $repository;
    private readonly RecommendationEngine $engine;
    private readonly ForecastService $forecastService;
    private readonly BillRepository $bills;
    private readonly SolarReadingRepository $solarReadings;
    private readonly InsightRepository $insightRepo;
    private readonly RecommendationThresholds $thresholds;
    private readonly \App\Domain\Intelligence\IntelligenceService $intelligenceService;
    private readonly NotificationService $notifications;

    public function __construct(
        ?RecommendationRepository $repository = null,
        ?RecommendationEngine $engine = null,
        ?ForecastService $forecastService = null,
        ?BillRepository $bills = null,
        ?SolarReadingRepository $solarReadings = null,
        ?InsightRepository $insightRepo = null,
        ?RecommendationThresholds $thresholds = null,
        ?\App\Domain\Intelligence\IntelligenceService $intelligenceService = null,
        ?NotificationService $notifications = null
    ) {
        $this->repository = $repository ?? new RecommendationRepository();
        $this->engine = $engine ?? new RecommendationEngine($thresholds ?? new RecommendationThresholds());
        $this->forecastService = $forecastService ?? new ForecastService();
        $this->bills = $bills ?? new BillRepository();
        $this->solarReadings = $solarReadings ?? new SolarReadingRepository();
        $this->insightRepo = $insightRepo ?? new InsightRepository();
        $this->thresholds = $thresholds ?? new RecommendationThresholds();
        $this->intelligenceService = $intelligenceService ?? new \App\Domain\Intelligence\IntelligenceService($this->insightRepo);
        $this->notifications = $notifications ?? new NotificationService();
    }

    /**
     * Generate or refresh recommendations for a household idempotently.
     *
     * @param string $householdId
     * @param DateTimeImmutable|null $referenceTime
     * @param bool $forceFresh
     * @return list<Recommendation> List of currently active recommendations
     */
    public function generate(
        string $householdId,
        ?DateTimeImmutable $referenceTime = null,
        bool $forceFresh = false
    ): array {
        $now = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Expire past active recommendations
        $this->repository->expirePast($householdId, $now);

        // Refresh F6 insights so F7 receives fresh pipeline evidence
        try {
            $this->intelligenceService->refreshFromEnergyPipeline($householdId, $now);
        } catch (Throwable) {
            // Insights refresh is non-blocking to recommendations
        }

        // 2. Fetch authoritative inputs
        // F3 Forecast (which encapsulates F4 Energy Balance & F5 Cost)
        $forecast = null;
        try {
            $forecast = $this->forecastService->getForecast(
                $householdId,
                ForecastHorizon::DAY_AHEAD,
                $now,
                $forceFresh
            );
        } catch (Throwable) {
            // Forecast may be unavailable or fail gracefully
            $forecast = null;
        }

        // Verified bills
        $allBills = $this->bills->listForHousehold($householdId);
        $verifiedBills = array_values(array_filter(
            $allBills,
            static fn ($b): bool => $b->status() === 'VERIFIED'
        ));

        // Latest solar readings
        $latestReading = $this->solarReadings->findLatestByHouseholdId($householdId);

        // Active F6 insights (DETECTED, VALIDATED, ACTIVE, UPDATED)
        $allInsights = $this->insightRepo->list([
            'household_id' => $householdId,
        ]);
        $insightEntities = array_values(array_filter(
            $allInsights,
            static fn (Insight $i): bool => in_array($i->status(), [
                \App\Domain\Intelligence\InsightLifecycle::DETECTED,
                \App\Domain\Intelligence\InsightLifecycle::VALIDATED,
                \App\Domain\Intelligence\InsightLifecycle::ACTIVE,
                \App\Domain\Intelligence\InsightLifecycle::UPDATED,
            ], true)
        ));
        $insights = array_map(
            static fn (Insight $i): array => $i->toArray(),
            $insightEntities
        );

        // 3. Evaluate deterministic rules
        $candidates = $this->engine->evaluate(
            $forecast,
            $latestReading,
            $verifiedBills,
            $insights,
            $now
        );

        // 4. Deduplicate, cooldown check, and persist candidates
        $cooldownCutoff = $now->modify("-{$this->thresholds->cooldownHours} hours");

        foreach ($candidates as $candidate) {
            // Check cooldown if recently dismissed
            $recentDismissed = $this->repository->findRecentDismissed(
                $householdId,
                $candidate->type,
                $cooldownCutoff
            );
            if ($recentDismissed !== null) {
                // User dismissed an equivalent recommendation recently; respect cooldown
                continue;
            }

            // Check if an equivalent active recommendation exists
            $existingActive = $this->repository->findActiveByPeriod(
                $householdId,
                $candidate->type,
                $candidate->relevantPeriodStart,
                $candidate->relevantPeriodEnd
            );

            if ($existingActive !== null) {
                // Refresh evidence & validity
                $existingActive->refreshEvidence($candidate, $now);
                $this->repository->update($existingActive);
            } else {
                // Create and insert new active recommendation
                $rec = Recommendation::createFromCandidate(
                    self::uuid(),
                    $householdId,
                    $candidate,
                    $now
                );
                $this->repository->save($rec);

                if ($rec->priority() === Recommendation::PRIORITY_HIGH) {
                    try {
                        $this->notifications->notify(
                            householdId: $householdId,
                            type: Notification::TYPE_RECOMMENDATION,
                            severity: Notification::SEVERITY_WARNING,
                            title: 'High-Priority Recommendation',
                            message: "{$rec->title()}: {$rec->action()}",
                            relatedResourceType: 'RECOMMENDATION',
                            relatedResourceId: $rec->id(),
                            eventKey: "rec_high_{$rec->id()}"
                        );
                    } catch (\Throwable) {
                    }
                }
            }
        }

        // 5. Return all currently active recommendations
        return $this->repository->list($householdId, ['status' => Recommendation::STATUS_ACTIVE]);
    }

    /**
     * List recommendations with optional status and type filters.
     *
     * @param string $householdId
     * @param array<string, mixed> $filters
     * @return list<Recommendation>
     */
    public function listRecommendations(string $householdId, array $filters = []): array
    {
        return $this->repository->list($householdId, $filters);
    }

    /**
     * Get a single recommendation by ID scoped to household.
     */
    public function findRecommendation(string $id, string $householdId): ?Recommendation
    {
        return $this->repository->findById($id, $householdId);
    }

    /**
     * Dismiss an active recommendation.
     */
    public function dismiss(string $id, string $householdId, ?DateTimeImmutable $now = null): bool
    {
        $time = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->repository->dismiss($id, $householdId, $time);
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }
}
