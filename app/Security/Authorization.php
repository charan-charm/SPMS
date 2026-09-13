<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Account\Account;
use App\Support\AppException;
use App\Support\Request;

/**
 * W3 — Request authorization helper (docs/10 D-050, D-055).
 *
 * Thin, single-purpose gate used by controller boundaries: extract the
 * authenticated account from the middleware-established AuthContext or
 * reject with the project's standard UNAUTHENTICATED envelope. It makes
 * no household decisions — HouseholdService resolves ownership from the
 * returned account server-side.
 */
final class Authorization
{
    public static function requireAccount(Request $request): Account
    {
        $context = $request->authContext();

        if (!$context instanceof AuthContext || !$context->isAuthenticated()) {
            throw new AppException('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $account = $context->account();

        if ($account === null) {
            throw new AppException('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        return $account;
    }
}
