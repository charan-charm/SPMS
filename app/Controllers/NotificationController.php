<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Household\HouseholdService;
use App\Domain\Notification\NotificationService;
use App\Repositories\NotificationRepository;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;

/**
 * SPMS F9 — Notification Center Controller.
 *
 * Exposes household-scoped notification endpoints:
 * - GET  /api/v1/notifications               List notifications (newest first, optional unread filter)
 * - POST /api/v1/notifications/{id}/read      Mark a single notification as read
 * - POST /api/v1/notifications/read-all       Mark all unread notifications as read
 */
final class NotificationController
{
    public function __construct(
        private readonly NotificationService $service = new NotificationService(),
        private readonly NotificationRepository $repository = new NotificationRepository(),
        private readonly HouseholdService $households = new HouseholdService()
    ) {
    }

    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $limitRaw = $request->queryParam('limit', '50');
        $limit = is_numeric($limitRaw) ? max(1, min(100, (int) $limitRaw)) : 50;

        $offsetRaw = $request->queryParam('offset', '0');
        $offset = is_numeric($offsetRaw) ? max(0, (int) $offsetRaw) : 0;

        $unreadOnlyRaw = $request->queryParam('unread_only');
        $unreadOnly = null;
        if ($unreadOnlyRaw !== null) {
            $unreadOnly = in_array(strtolower((string) $unreadOnlyRaw), ['1', 'true', 'yes'], true);
        }

        $result = $this->service->getNotifications(
            householdId: $householdId,
            limit: $limit,
            offset: $offset,
            unreadOnly: $unreadOnly
        );

        return Response::success([
            'notifications' => $result['notifications'],
            'unread_count' => $result['unread_count'],
            'total_count' => $result['total_count'],
        ]);
    }

    /**
     * POST /api/v1/notifications/{id}/read
     */
    public function markRead(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $id = $this->extractNotificationId($request->path());
        if ($id === null) {
            throw new AppException('NOT_FOUND', 'Notification ID missing or malformed.', 404);
        }

        $notification = $this->repository->findById($id, $householdId);
        if ($notification === null) {
            throw new AppException('NOT_FOUND', 'The requested notification was not found.', 404);
        }

        $this->service->markAsRead($id, $householdId);

        return Response::success([
            'status' => 'OK',
            'id' => $id,
            'is_read' => true,
        ]);
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function markAllRead(Request $request): Response
    {
        $householdId = $this->requireHouseholdId($request);
        $this->assertNoCrossHouseholdClaim($request, $householdId);

        $marked = $this->service->markAllAsRead($householdId);

        return Response::success([
            'status' => 'OK',
            'marked_count' => $marked,
        ]);
    }

    private function extractNotificationId(string $path): ?string
    {
        if (preg_match('#^/api/v1/notifications/([0-9a-fA-F\-]{36})/read$#', $path, $matches) === 1) {
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
                'An active household membership is required to access notifications.',
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
