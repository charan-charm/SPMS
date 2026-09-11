<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Account\Account;

/**
 * W1 — Authenticated request context (docs/10 D-055).
 *
 * Established by AuthMiddleware from the server-side session. Carries
 * the authenticated account and the live session row, or represents an
 * anonymous request. No authorization decisions are made here: context
 * is input for controller-boundary authorization (W3) and service rules.
 */
final class AuthContext
{
    /**
     * @param array<string, mixed>|null $session live session row (hashes only)
     */
    private function __construct(
        private readonly ?Account $account,
        private readonly ?array $session
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null, null);
    }

    public static function authenticated(Account $account, array $session): self
    {
        return new self($account, $session);
    }

    public function isAuthenticated(): bool
    {
        return $this->account !== null && $this->session !== null;
    }

    public function account(): ?Account
    {
        return $this->account;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function session(): ?array
    {
        return $this->session;
    }
}
