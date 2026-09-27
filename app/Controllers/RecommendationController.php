<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Household\HouseholdService;
use App\Domain\Recommendation\Recommendation;
use App\Domain\Recommendation\RecommendationService;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use InvalidArgumentException;

/**
 * SPMS F7 — Recommendations API Controller (docs/01 §15, docs/02 §2 F7, docs/06 §3, docs/10 D-072).
 *
 * Exposes authenticated, household-scoped endpoints:
 * - GET  /api/v1/recommendations            List active recommendations (optional status/type filter)
 * - GET  /api/v1/recommendations/{id}       Single recommendation detail
 * - POST /api/v1/recommendations/generate   Idempotent recalculation and snapshot update
 * - POST /api/v1/recommendations/{id}/dismiss Dismiss an active recommendation
 */
final class RecommendationController
{
    public function __construct(
        private readonly RecommendationService $service = new RecommendationService(),
        private readonly HouseholdService $households = new HouseholdService()
    ) {
    }

    /**
     * GET /api/v1/recommendations
     */
    public function index(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $status = strtoupper(trim((string) $request->queryParam('status', Recommendation::STATUS_ACTIVE)));
        $type = $request->queryParam('type');

        if ($type !== null && !RecommendationTaxonomy::isValid((string) $type)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('Invalid recommendation type "%s".', $type),
                422,
                ['field' => 'type']
            );
        }

        $filters = ['status' => $status];
        if ($type !== null && $type !== '') {
            $filters['type'] = (string) $type;
        }

        $recommendations = $this->service->listRecommendations($householdId, $filters);

        $activeCount = 0;
        foreach ($recommendations as $r) {
            if ($r->isActive()) {
                $activeCount++;
            }
        }

        return Response::success([
            'recommendations' => array_map(
                static fn (Recommendation $r): array => $r->toArray(),
                $recommendations
            ),
            'count' => count($recommendations),
            'active_count' => $activeCount,
            'filters' => [
                'status' => $status,
                'type' => $type,
            ],
        ]);
    }

    /**
     * GET /api/v1/recommendations/{id}
     */
    public function show(Request $request, array $params = []): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $id = (string) ($params['id'] ?? $this->extractId($request->path()) ?? '');
        if ($id === '') {
            throw new AppException('VALIDATION_ERROR', 'Recommendation ID is required.', 422);
        }

        $recommendation = $this->service->findRecommendation($id, $householdId);
        if ($recommendation === null) {
            throw new AppException(
                'NOT_FOUND',
                'Recommendation not found or you do not have access to it.',
                404
            );
        }

        return Response::success([
            'recommendation' => $recommendation->toArray(),
        ]);
    }

    /**
     * POST /api/v1/recommendations/generate
     */
    public function generate(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $activeRecommendations = $this->service->generate($householdId, forceFresh: true);

        return Response::success([
            'recommendations' => array_map(
                static fn (Recommendation $r): array => $r->toArray(),
                $activeRecommendations
            ),
            'count' => count($activeRecommendations),
        ]);
    }

    /**
     * POST /api/v1/recommendations/{id}/dismiss
     */
    public function dismiss(Request $request, array $params = []): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $id = (string) ($params['id'] ?? $this->extractId($request->path()) ?? '');
        if ($id === '') {
            throw new AppException('VALIDATION_ERROR', 'Recommendation ID is required.', 422);
        }

        $dismissed = $this->service->dismiss($id, $householdId);
        if (!$dismissed) {
            throw new AppException(
                'NOT_FOUND',
                'Recommendation not found or already dismissed.',
                404
            );
        }

        $fresh = $this->service->findRecommendation($id, $householdId);

        return Response::success([
            'recommendation' => $fresh?->toArray(),
            'dismissed' => true,
            'id' => $id,
        ]);
    }

    private function extractId(string $path): ?string
    {
        if (preg_match('#^/api/v1/recommendations/([^/]+)(?:/dismiss)?$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function requireHouseholdId(Request $request): string
    {
        $account = Authorization::requireAccount($request);
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException(
                'HOUSEHOLD_NOT_FOUND',
                'An active household membership is required to access recommendations.',
                404
            );
        }

        return $householdId;
    }

    private function assertNoCrossHouseholdClaim(Request $request, string $effectiveHouseholdId): void
    {
        $claimed = $request->queryParam('household_id') ?? $request->bodyParam('household_id');
        if ($claimed !== null && (string) $claimed !== $effectiveHouseholdId) {
            throw new AppException(
                'HOUSEHOLD_ACCESS_DENIED',
                'You do not have access to this household scope.',
                403
            );
        }
    }
}
