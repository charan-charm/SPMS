<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Household\HouseholdService;
use App\Domain\Household\OnboardingReadiness;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use DateTimeImmutable;

/**
 * W3 — Authenticated household context API (docs/10 D-050, D-055).
 *
 * GET   /api/v1/household  the caller's own household context, derived
 *                          server-side (session → account → membership →
 *                          household), plus the W3.1 derived onboarding
 *                          readiness projection (docs/10 D-065). No
 *                          identifier is accepted from the client on
 *                          this route.
 * POST  /api/v1/household  v1 onboarding primitive: create the caller's
 *                          household + OWNER membership (CSRF-protected
 *                          by the auth middleware like every authenticated
 *                          mutation). Display context only — status/role
 *                          are server-assigned (D-049 rule).
 * PATCH /api/v1/household  W3.1 profile completion (docs/10 D-065,
 *                          W4-lite): modify the caller's own ACTIVE
 *                          household's current display context
 *                          (timezone/label/location_label). Scope stays
 *                          server-derived; unknown fields and wrong
 *                          types are rejected; no implicit creation.
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

    /** Accepted PATCH fields on the own-route household profile (D-065, extended by D-071). */
    private const PATCHABLE_FIELDS = ['timezone', 'label', 'location_label', 'service_number'];

    public function current(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $context = $this->households->activeHouseholdFor($account);
        $public = $account->toPublicArray();

        return Response::success([
            'authenticated' => true,
            'account' => $public,
            'household' => $context === null ? null : $context['household']->toPublicArray(),
            'membership' => $context === null ? null : ['role' => $context['membership']->role()],
            'readiness' => OnboardingReadiness::derive(
                (bool) $public['email_verified'],
                $context === null ? null : $context['household']
            ),
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

    /**
     * PATCH /api/v1/household — W3.1 profile completion (docs/10 D-065).
     *
     * The mutation is CSRF-protected by the auth middleware like every
     * authenticated state-changing request. Only timezone/label/
     * location_label may be supplied; everything else (ids, status,
     * role, unknown keys) is rejected, and invalid values fail closed
     * with a field-scoped 422 — never silent coercion.
     */
    public function update(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $body = $request->body();

        foreach (array_keys($body) as $field) {
            if (!in_array($field, self::PATCHABLE_FIELDS, true)) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    'Unrecognized field: ' . $field . '.',
                    422,
                    ['field' => (string) $field]
                );
            }
        }

        $changes = [];

        if (array_key_exists('timezone', $body)) {
            $timezone = $body['timezone'];
            if (!is_string($timezone) || trim($timezone) === '') {
                throw new AppException('VALIDATION_ERROR', 'timezone must be a non-empty string.', 422, ['field' => 'timezone']);
            }
            $changes['timezone'] = $timezone;
        }

        if (array_key_exists('label', $body)) {
            $changes['label'] = $this->nullablePatchValue($body['label'], 'label');
        }

        if (array_key_exists('location_label', $body)) {
            $changes['location_label'] = $this->nullablePatchValue($body['location_label'], 'location_label');
        }

        // D-071: the registered electricity service/USC number — stored
        // in canonical form by the domain; '' clears it honestly.
        if (array_key_exists('service_number', $body)) {
            $changes['service_number'] = $this->nullablePatchValue($body['service_number'], 'service_number');
        }

        $result = $this->households->updateProfile(
            $account,
            $changes,
            new DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );

        return Response::success([
            'household' => $result['household']->toPublicArray(),
            'membership' => ['role' => $result['membership']->role()],
        ]);
    }

    /**
     * A supplied nullable profile field: null or '' clears it, a
     * non-empty string updates it, any other type fails closed.
     */
    private function nullablePatchValue(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_string($value)) {
            throw new AppException('VALIDATION_ERROR', $field . ' must be a string or null.', 422, ['field' => $field]);
        }

        return $value;
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
