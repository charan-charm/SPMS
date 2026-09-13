<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Household\HouseholdService;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use DateTimeImmutable;

/**
 * W3 — Authenticated household context API (docs/10 D-050, D-055).
 *
 * GET  /api/v1/household  the caller's own household context, derived
 *                         server-side (session → account → membership →
 *                         household). No identifier is accepted from the
 *                         client on this route.
 * POST /api/v1/household  v1 onboarding primitive: create the caller's
 *                         household + OWNER membership (CSRF-protected
 *                         by the auth middleware like every authenticated
 *                         mutation). Display context only — status/role
 *                         are server-assigned (D-049 rule).
 *
 * There is deliberately no GET /api/v1/households/{id}: no documented
 * need exists for enumerating arbitrary households, and the endpoint
 * would invite client-selected ownership.
 */
final class HouseholdController
{
    public function __construct(
        private readonly HouseholdService $households = new HouseholdService()
    ) {
    }

    public function current(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $context = $this->households->activeHouseholdFor($account);

        return Response::success([
            'authenticated' => true,
            'account' => $account->toPublicArray(),
            'household' => $context === null ? null : $context['household']->toPublicArray(),
            'membership' => $context === null ? null : ['role' => $context['membership']->role()],
        ]);
    }

    public function create(Request $request): Response
    {
        $account = Authorization::requireAccount($request);

        $timezone = $request->bodyParam('timezone');
        if (!is_string($timezone) || trim($timezone) === '') {
            throw new AppException('VALIDATION_ERROR', 'timezone is required.', 422, ['field' => 'timezone']);
        }

        $label = $this->optionalString($request->bodyParam('label'), 'label');
        $locationLabel = $this->optionalString($request->bodyParam('location_label'), 'location_label');

        $result = $this->households->createForAccount(
            $account,
            $label,
            $timezone,
            $locationLabel,
            new DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );

        return Response::success([
            'household' => $result['household']->toPublicArray(),
            'membership' => ['role' => $result['membership']->role()],
        ], 201);
    }

    private function optionalString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new AppException('VALIDATION_ERROR', $field . ' must be a string.', 422, ['field' => $field]);
        }

        return $value;
    }
}
