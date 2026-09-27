<?php

declare(strict_types=1);

namespace App\Domain\Household;

use App\Domain\Account\Account;
use App\Repositories\HouseholdRepository;
use App\Repositories\MembershipRepository;
use App\Support\AppException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * W3 — Household use-cases (docs/10 D-050, D-055, D-056).
 *
 * Server-side ownership resolution and the v1 onboarding primitive.
 *
 * The effective household for an authenticated account is derived here,
 * from memberships over the account's own identity — never from a
 * client-supplied identifier. ADMIN receives no special resolution:
 * like every account, its reach is exactly its memberships (docs/10
 * D-055: ADMIN is not unrestricted cross-household access).
 *
 * v1 business rule (D-050): one account, one household — enforced by
 * this application layer while the schema stays N:N-capable.
 */
final class HouseholdService
{
    public function __construct(
        private readonly HouseholdRepository $households = new HouseholdRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository()
    ) {
    }

    /**
     * The account's active household context, or null when the account
     * has none yet (or its only household is no longer ACTIVE — deleted
     * households never resolve as active context).
     *
     * @return array{household: Household, membership: Membership}|null
     */
    public function activeHouseholdFor(Account $account): ?array
    {
        return $this->memberships->findActiveByAccount($account->id());
    }

    public function activeHouseholdId(Account $account): ?string
    {
        $context = $this->activeHouseholdFor($account);

        return $context === null ? null : $context['household']->id();
    }

    /**
     * Membership check for later household-scoped operations (insight
     * detail, bills, integrations): the caller's own derived household
     * must match the resource's household exactly.
     */
    public function assertsMembership(Account $account, string $householdId): bool
    {
        return $this->memberships->exists($householdId, $account->id());
    }

    /**
     * V1 onboarding primitive: create the account's household plus its
     * OWNER membership atomically. Status and role are server-assigned
     * only; the caller only supplies display context.
     *
     * @return array{household: Household, membership: Membership}
     */
    public function createForAccount(
        Account $account,
        ?string $label,
        string $timezone,
        ?string $locationLabel,
        DateTimeImmutable $now,
        ?string $serviceNumber = null
    ): array {
        if ($this->memberships->countForAccount($account->id()) > 0) {
            throw new AppException(
                'HOUSEHOLD_EXISTS',
                'This account already has a household.',
                409
            );
        }

        try {
            $household = Household::create($label, $timezone, $locationLabel, $now, $serviceNumber);
            $membership = Membership::create($household->id(), $account->id(), Membership::ROLE_OWNER, $now);
        } catch (InvalidArgumentException $e) {
            throw self::validationError($e);
        }

        $this->households->insertWithOwnerMembership($household, $membership);

        return ['household' => $household, 'membership' => $membership];
    }

    /**
     * O3 — Backend-authoritative DEMO household provisioning.
     *
     * In the DEMO environment (spms_demo), guarantees idempotently that the
     * caller account owns the prepared demonstration household. If already
     * present, returns it; if absent, provisions it server-side.
     * Fails closed with 403 if called outside the demo environment.
     *
     * @return array{household: Household, membership: Membership}
     */
    public function ensureDemoHouseholdForAccount(Account $account, DateTimeImmutable $now): array
    {
        $existing = $this->activeHouseholdFor($account);
        if ($existing !== null) {
            return $existing;
        }

        $profile = \App\Support\Database::currentProfile();
        if ($profile !== \App\Support\DataEnvironments::DEMO) {
            throw new AppException(
                'INVALID_ENVIRONMENT',
                'Demo household provisioning is only permitted in the demonstration environment.',
                403
            );
        }

        return $this->createForAccount(
            $account,
            'SPMS Demonstration Residence',
            'Asia/Kolkata',
            'Demonstration Residence',
            $now,
            'DEMO-1000-2000-300'
        );
    }

    /**
     * W3.1 — Household profile completion (docs/10 D-065, W4-lite):
     * modify the caller's own ACTIVE household's current display
     * context only. The target household is derived through the same
     * membership resolution as every other household read — a client
     * never selects it. With no active household the result is an
     * honest 404: this operation never creates implicitly, redirects,
     * or manufactures readiness.
     *
     * @param array{label?: ?string, timezone?: ?string, location_label?: ?string, service_number?: ?string} $changes
     *        supplied keys only; a nullable key set to '' clears it
     *
     * @return array{household: Household, membership: Membership}
     */
    public function updateProfile(Account $account, array $changes, DateTimeImmutable $now): array
    {
        $context = $this->activeHouseholdFor($account);

        if ($context === null) {
            throw new AppException(
                'NOT_FOUND',
                'The caller has no active household context to modify.',
                404
            );
        }

        try {
            $updated = $context['household']->withProfile(
                array_key_exists('label', $changes) ? $changes['label'] : null,
                array_key_exists('timezone', $changes) ? $changes['timezone'] : null,
                array_key_exists('location_label', $changes) ? $changes['location_label'] : null,
                array_key_exists('service_number', $changes) ? $changes['service_number'] : null,
                $now
            );
        } catch (InvalidArgumentException $e) {
            throw self::validationError($e);
        }

        $noChange = $updated->label() === $context['household']->label()
            && $updated->timezone() === $context['household']->timezone()
            && $updated->locationLabel() === $context['household']->locationLabel()
            && $updated->serviceNumber() === $context['household']->serviceNumber();

        if (!$noChange) {
            $this->households->updateProfile($updated);
        }

        return ['household' => $updated, 'membership' => $context['membership']];
    }

    /**
     * Map a domain validation failure onto the project's field-scoped
     * 422 envelope (shared by creation and profile modification).
     */
    private static function validationError(InvalidArgumentException $e): AppException
    {
        $message = $e->getMessage();
        $field = str_starts_with($message, 'location_label')
            ? 'location_label'
            : (str_starts_with($message, 'service_number')
                ? 'service_number'
                : (str_starts_with($message, 'label') ? 'label' : 'timezone'));

        return new AppException('VALIDATION_ERROR', $message, 422, ['field' => $field]);
    }
}
