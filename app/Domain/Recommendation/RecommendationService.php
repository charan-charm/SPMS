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
    private readonly \App\Repositories\RecommendationFeedbackRepository $feedbackRepo;

    public function __construct(
        ?RecommendationRepository $repository = null,
        ?RecommendationEngine $engine = null,
        ?ForecastService $forecastService = null,
        ?BillRepository $bills = null,
        ?SolarReadingRepository $solarReadings = null,
        ?InsightRepository $insightRepo = null,
        ?RecommendationThresholds $thresholds = null,
        ?\App\Domain\Intelligence\IntelligenceService $intelligenceService = null,
        ?NotificationService $notifications = null,
        ?\App\Repositories\RecommendationFeedbackRepository $feedbackRepo = null
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
        $this->feedbackRepo = $feedbackRepo ?? new \App\Repositories\RecommendationFeedbackRepository();
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
            $existingActive = $this->repository->findActiveEquivalent(
                $householdId,
                $candidate->type,
                $candidate->relevantPeriodStart,
                $candidate->relevantPeriodEnd,
                $candidate->title
            );

            if ($existingActive !== null) {
                // Refresh evidence & validity
                $existingActive->refreshEvidence($candidate, $now);
                $this->repository->update($existingActive);
                $this->repository->supersedeDuplicateActives($householdId, $candidate->type, $existingActive->id(), $now);
                $canonicalRec = $existingActive;

                // Mutual suppression across overlapping taxonomy types:
                if ($candidate->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                    $this->repository->supersedeActivesByType($householdId, RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR, $now);
                } elseif ($candidate->type === RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION) {
                    $this->repository->supersedeActivesByType($householdId, RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, $now);
                }

                // Weather forecast conflict superseding (CHECK 4):
                if ($candidate->type === RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE
                    || $candidate->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                    $this->repository->supersedeConflictingWeatherRecommendations(
                        $householdId,
                        $candidate->type,
                        $candidate->relevantPeriodStart,
                        $candidate->relevantPeriodEnd,
                        $now
                    );
                }
            } else {
                // Create and insert new active recommendation
                $rec = Recommendation::createFromCandidate(
                    self::uuid(),
                    $householdId,
                    $candidate,
                    $now
                );
                $this->repository->save($rec);
                $this->repository->supersedeDuplicateActives($householdId, $candidate->type, $rec->id(), $now);
                $canonicalRec = $rec;

                // Mutual suppression across overlapping taxonomy types:
                if ($candidate->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                    $this->repository->supersedeActivesByType($householdId, RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR, $now);
                } elseif ($candidate->type === RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION) {
                    $this->repository->supersedeActivesByType($householdId, RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, $now);
                }

                // Weather forecast conflict superseding (CHECK 4):
                if ($candidate->type === RecommendationTaxonomy::TYPE_SOLAR_DECLINE_RESPONSE
                    || $candidate->type === RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW) {
                    $this->repository->supersedeConflictingWeatherRecommendations(
                        $householdId,
                        $candidate->type,
                        $candidate->relevantPeriodStart,
                        $candidate->relevantPeriodEnd,
                        $now
                    );
                }
            }

            if ($canonicalRec->priority() === Recommendation::PRIORITY_HIGH) {
                $periodDate = $canonicalRec->relevantPeriodStart() !== null
                    ? substr($canonicalRec->relevantPeriodStart(), 0, 10)
                    : $canonicalRec->validFrom()->format('Y-m-d');
                $actionHash = substr(hash('sha256', $canonicalRec->action() . '|' . $canonicalRec->title()), 0, 12);
                $eventKey = sprintf('rec_high_%s_%s_%s', $canonicalRec->type(), $periodDate, $actionHash);

                try {
                    $this->notifications->notify(
                        householdId: $householdId,
                        type: Notification::TYPE_RECOMMENDATION,
                        severity: Notification::SEVERITY_WARNING,
                        title: 'High-Priority Recommendation',
                        message: "{$canonicalRec->title()}: {$canonicalRec->action()}",
                        relatedResourceType: 'RECOMMENDATION',
                        relatedResourceId: $canonicalRec->id(),
                        eventKey: $eventKey
                    );
                } catch (\Throwable) {
                }
            }

            $this->recordPendingOutcome($canonicalRec, $candidate, $now);

        }

        // Resolve / supersede hardware connectivity alert once inverter telemetry is verified fresh and online
        $readingDataStatus = is_array($latestReading)
            ? ($latestReading['data_status'] ?? '')
            : ($latestReading?->dataStatus() ?? '');
        $readingInvStatus = is_array($latestReading)
            ? ($latestReading['inverter_status'] ?? '')
            : ($latestReading?->inverterStatus() ?? '');

        if (
            $latestReading !== null
            && $readingDataStatus === 'FRESH'
            && !in_array(strtoupper(trim((string) $readingInvStatus)), ['OFFLINE', 'FAULT', 'DISCONNECTED', ''], true)
        ) {
            $this->repository->supersedeActivesByTitle(
                $householdId,
                RecommendationTaxonomy::TYPE_DATA_LIMITATION,
                'Check solar inverter connectivity',
                $now
            );
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

    public function submitFeedback(
        string $householdId,
        string $recommendationId,
        string $accountId,
        array $data,
        ?\DateTimeImmutable $now = null
    ): \App\Domain\Recommendation\RecommendationFeedback {
        $rec = $this->repository->findById($recommendationId, $householdId);
        if ($rec === null) {
            throw new \App\Support\AppException('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $usefulnessRaw = strtoupper(trim((string) ($data['usefulness'] ?? '')));
        $usefulness = match ($usefulnessRaw) {
            'YES', 'USEFUL' => \App\Domain\Recommendation\RecommendationFeedback::USEFULNESS_USEFUL,
            'NO', 'NOT_REALLY', 'NOT_USEFUL' => \App\Domain\Recommendation\RecommendationFeedback::USEFULNESS_NOT_USEFUL,
            default => throw new \App\Support\AppException('VALIDATION_ERROR', 'Valid usefulness rating (YES or NO) is required.', 422),
        };

        $followedRaw = isset($data['followed_status']) && $data['followed_status'] !== null && trim((string) $data['followed_status']) !== ''
            ? strtoupper(trim((string) $data['followed_status']))
            : null;
        $followedStatus = match ($followedRaw) {
            'YES' => \App\Domain\Recommendation\RecommendationFeedback::FOLLOWED_YES,
            'PARTLY' => \App\Domain\Recommendation\RecommendationFeedback::FOLLOWED_PARTLY,
            'NOT_YET', 'NO' => \App\Domain\Recommendation\RecommendationFeedback::FOLLOWED_NOT_YET,
            null => null,
            default => throw new \App\Support\AppException('VALIDATION_ERROR', 'Invalid followed status.', 422),
        };

        $reasonRaw = isset($data['reason']) && $data['reason'] !== null && trim((string) $data['reason']) !== ''
            ? strtoupper(trim((string) $data['reason']))
            : null;
        $reason = match ($reasonRaw) {
            'NOT_RELEVANT', 'WRONG_TIMING', 'COULD_NOT_FOLLOW', 'ALREADY_DOING', 'OTHER' => $reasonRaw,
            null => null,
            default => \App\Domain\Recommendation\RecommendationFeedback::REASON_OTHER,
        };

        $comment = isset($data['optional_comment']) ? (string) $data['optional_comment'] : null;

        $currentTime = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $existing = $this->feedbackRepo->findByRecommendationAndAccount($recommendationId, $accountId);

        if ($existing !== null) {
            $existing->updateFeedback($usefulness, $followedStatus, $reason, $comment, $currentTime);
            $this->feedbackRepo->saveFeedback($existing);
            return $existing;
        }

        $feedback = \App\Domain\Recommendation\RecommendationFeedback::create(
            id: self::uuid(),
            recommendationId: $recommendationId,
            householdId: $householdId,
            accountId: $accountId,
            usefulness: $usefulness,
            followedStatus: $followedStatus,
            reason: $reason,
            optionalComment: $comment,
            now: $currentTime
        );

        $this->feedbackRepo->saveFeedback($feedback);
        return $feedback;
    }

    /**
     * @param list<string> $recommendationIds
     * @return array<string, \App\Domain\Recommendation\RecommendationFeedback>
     */
    public function getFeedbackForRecommendations(array $recommendationIds, ?string $accountId = null): array
    {
        return $this->feedbackRepo->findForRecommendations($recommendationIds, $accountId);
    }

    private function recordPendingOutcome(
        Recommendation $rec,
        RecommendationCandidate $candidate,
        \DateTimeImmutable $now
    ): void {
        try {
            $outcomeType = match ($rec->type()) {
                RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW,
                RecommendationTaxonomy::TYPE_USE_AVAILABLE_SOLAR,
                RecommendationTaxonomy::TYPE_EVENING_DEFICIT_PREPARATION => \App\Domain\Recommendation\RecommendationOutcome::OUTCOME_SOLAR_WINDOW_GENERATION,
                RecommendationTaxonomy::TYPE_DATA_LIMITATION => \App\Domain\Recommendation\RecommendationOutcome::OUTCOME_INVERTER_RECOVERY,
                RecommendationTaxonomy::TYPE_HIGH_DEMAND_REDUCTION => \App\Domain\Recommendation\RecommendationOutcome::OUTCOME_DEMAND_REDUCTION,
                default => null,
            };

            if ($outcomeType === null) {
                return;
            }

            $predictedVal = null;
            if (isset($candidate->impact->kWh) && is_numeric($candidate->impact->kWh)) {
                $predictedVal = (float) $candidate->impact->kWh;
            }

            $start = null;
            if ($candidate->relevantPeriodStart !== null) {
                try {
                    $start = new \DateTimeImmutable($candidate->relevantPeriodStart, new \DateTimeZone('UTC'));
                } catch (\Throwable) {}
            }

            $end = null;
            if ($candidate->relevantPeriodEnd !== null) {
                try {
                    $end = new \DateTimeImmutable($candidate->relevantPeriodEnd, new \DateTimeZone('UTC'));
                } catch (\Throwable) {}
            }

            $outcome = \App\Domain\Recommendation\RecommendationOutcome::create(
                id: self::uuid(),
                recommendationId: $rec->id(),
                householdId: $rec->householdId(),
                outcomeType: $outcomeType,
                targetPeriodStart: $start,
                targetPeriodEnd: $end,
                predictedValue: $predictedVal,
                details: [
                    'rule' => $rec->type(),
                    'timing_window' => $rec->timingWindow(),
                ],
                now: $now
            );

            $this->feedbackRepo->saveOutcome($outcome);
        } catch (\Throwable) {
            // Objective outcome logging should never disrupt recommendation lifecycle
        }
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }
}
