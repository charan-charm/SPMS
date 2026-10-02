<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Assistant\AssistantService;
use App\Domain\Household\HouseholdService;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use InvalidArgumentException;

/**
 * SPMS F8 — AI Energy Assistant API Controller.
 *
 * Exposes authenticated, household-scoped endpoints:
 * - POST   /api/v1/assistant          Send query to Energy Assistant with context building & safe validation
 * - GET    /api/v1/assistant          Retrieve recent assistant conversation history for household
 * - DELETE /api/v1/assistant          Clear conversation history for household
 */
final class AssistantController
{
    public function __construct(
        private readonly AssistantService $service = new AssistantService(),
        private readonly HouseholdService $households = new HouseholdService()
    ) {
    }

    /**
     * POST /api/v1/assistant
     */
    public function ask(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $householdId = $this->requireHouseholdId($request, $account);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $message = trim((string) ($request->bodyParam('message') ?? ''));
        if ($message === '') {
            throw new AppException(
                'VALIDATION_ERROR',
                'Question message is required.',
                422,
                ['field' => 'message']
            );
        }

        if (mb_strlen($message) > 2000) {
            throw new AppException(
                'VALIDATION_ERROR',
                'Question message cannot exceed 2000 characters.',
                422,
                ['field' => 'message']
            );
        }

        $pageContext = $request->bodyParam('page_context');
        if ($pageContext !== null) {
            $pageContext = trim((string) $pageContext);
            if ($pageContext === '') {
                $pageContext = null;
            } elseif (strlen($pageContext) > 64) {
                $pageContext = substr($pageContext, 0, 64);
            }
        }

        $clientHistory = $request->bodyParam('history');
        if (!is_array($clientHistory)) {
            $clientHistory = [];
        }

        $result = $this->service->ask(
            householdId: $householdId,
            accountId: (string) $account->id(),
            userMessage: $message,
            pageContext: $pageContext,
            clientHistory: $clientHistory
        );

        return Response::success($result);
    }

    /**
     * GET /api/v1/assistant
     */
    public function history(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $householdId = $this->requireHouseholdId($request, $account);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $limit = (int) ($request->queryParam('limit', 20));
        if ($limit < 1 || $limit > 50) {
            $limit = 20;
        }

        $messages = $this->service->history($householdId, $limit);

        return Response::success([
            'messages' => $messages,
            'count' => count($messages),
        ]);
    }

    /**
     * DELETE /api/v1/assistant
     */
    public function clear(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $householdId = $this->requireHouseholdId($request, $account);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $this->service->clearHistory($householdId);

        return Response::success([
            'cleared' => true,
        ]);
    }

    private function requireHouseholdId(Request $request, object $account): string
    {
        $householdId = $this->households->activeHouseholdId($account);

        if ($householdId === null) {
            throw new AppException(
                'HOUSEHOLD_NOT_FOUND',
                'An active household membership is required to access the Energy Assistant.',
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
