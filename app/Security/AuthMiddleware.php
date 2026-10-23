<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Account\Account;
use App\Domain\Auth\SessionService;
use App\Repositories\AccountRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\SessionRepository;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Security\SessionCookie;
use App\Support\Security\TokenHasher;
use DateTimeImmutable;

/**
 * W1 — Authentication middleware (docs/10 D-055).
 *
 * Router → Middleware → Controller → Service/Domain → Repository.
 *
 * Reads the session cookie, hashes the opaque identifier, locates the
 * valid server-side session, rejects revoked/idle-expired/absolute-
 * expired sessions (lazily revoking the latter), verifies the account
 * can still authenticate, and establishes the AuthContext on the
 * request. Also enforces the CSRF synchronizer token on authenticated
 * state-changing requests (docs/10 D-052).
 *
 * Repositories never see this layer's decisions; React never influences
 * them.
 */
final class AuthMiddleware
{
    public const CSRF_HEADER = 'X-SPMS-CSRF';

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly SessionRepository $sessions = new SessionRepository(),
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly SessionService $sessionService = new SessionService(),
        private readonly SecurityEventRepository $events = new SecurityEventRepository()
    ) {
    }

    public static function process(Request $request): Request
    {
        return (new self())->handle($request);
    }

    public function handle(Request $request): Request
    {
        $context = $this->resolve($request);

        if ($context->isAuthenticated()) {
            if (in_array($request->method(), self::MUTATING_METHODS, true)) {
                $this->assertCsrfToken($request, $context);
            }

            $session = $context->session();
            if ($session !== null) {
                $this->sessionService->touch($session);
            }
        }

        return $request->withAuthContext($context);
    }

    private function resolve(Request $request): AuthContext
    {
        $tokens = SessionCookie::extractTokens($request->header('cookie'));

        if (empty($tokens)) {
            $this->applyUnauthenticatedEnvironmentHint($request);
            return AuthContext::anonymous();
        }

        $orderedTokens = $this->prioritizeTokens($tokens, $request);
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($orderedTokens as $cookieName => $rawToken) {
            $targetProfile = match ($cookieName) {
                'spms_demo_session' => 'demo',
                'spms_test_session' => 'test',
                default => 'real',
            };

            if ($targetProfile === 'test' && PHP_SAPI !== 'cli' && getenv('SPMS_DATA_ENVIRONMENT') !== 'test') {
                continue;
            }

            \App\Support\Database::switchTo($targetProfile);
            $session = $this->sessionService->resolve($rawToken, $now);

            if ($session !== null) {
                $account = $this->accounts->findById((string) $session['account_id']);

                if ($account === null || !$account->canAuthenticate()) {
                    $this->sessions->revoke((string) $session['id'], gmdate('Y-m-d H:i:s'));
                    $this->events->record(
                        SecurityEventRepository::SESSION_REVOKED,
                        null,
                        $session['account_id'],
                        ['session_id' => $session['id'], 'reason' => 'ACCOUNT_STATE'],
                        null
                    );
                    continue;
                }

                return AuthContext::authenticated($account, $session);
            }
        }

        $this->applyUnauthenticatedEnvironmentHint($request);
        return AuthContext::anonymous();
    }

    /**
     * @param array<string, string> $tokens
     * @return array<string, string>
     */
    private function prioritizeTokens(array $tokens, Request $request): array
    {
        $hint = null;
        if (str_contains($request->path(), '/demo/')) {
            $hint = 'demo';
        } else {
            $rawHint = $request->header('x-spms-environment')
                ?? $request->header('x-spms-environment-hint')
                ?? $request->bodyParam('environment')
                ?? $this->cookieValue($request, 'spms_env');

            if (is_string($rawHint) && trim($rawHint) !== '') {
                $hint = strtolower(trim($rawHint));
            }
        }

        $preferred = match ($hint) {
            'demo' => 'spms_demo_session',
            'test' => 'spms_test_session',
            'real' => 'spms_session',
            default => null,
        };

        if ($preferred !== null && isset($tokens[$preferred])) {
            $ordered = [$preferred => $tokens[$preferred]];
            foreach ($tokens as $name => $tok) {
                if ($name !== $preferred) {
                    $ordered[$name] = $tok;
                }
            }
            return $ordered;
        }

        return $tokens;
    }

    private function applyUnauthenticatedEnvironmentHint(Request $request): void
    {
        $rawHint = $request->bodyParam('environment')
            ?? $request->header('X-SPMS-Environment')
            ?? $request->header('X-SPMS-Environment-Hint')
            ?? $this->cookieValue($request, 'spms_env');

        if (is_string($rawHint) && trim($rawHint) !== '') {
            $hint = strtolower(trim($rawHint));

            if ($hint === 'test') {
                throw new AppException(
                    'VALIDATION_ERROR',
                    'TEST environment cannot be selected via HTTP interface.',
                    400,
                    ['field' => 'environment']
                );
            }

            if (in_array($hint, ['real', 'demo'], true)) {
                \App\Support\Database::switchTo($hint);
                return;
            }
        }

        $defaultProfile = \App\Support\Config::get('SPMS_DATA_ENVIRONMENT') ?: (getenv('SPMS_DATA_ENVIRONMENT') ?: 'real');
        if (in_array($defaultProfile, ['real', 'demo', 'test'], true)) {
            \App\Support\Database::switchTo($defaultProfile);
        } else {
            \App\Support\Database::switchTo('real');
        }
    }

    private function cookieValue(Request $request, string $name): ?string
    {
        $cookieHeader = $request->header('cookie');
        if (!is_string($cookieHeader) || $cookieHeader === '') {
            return null;
        }

        foreach (explode(';', $cookieHeader) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === strtolower($name)) {
                $val = trim($parts[1]);
                return $val === '' ? null : $val;
            }
        }

        return null;
    }

    private function assertCsrfToken(Request $request, AuthContext $context): void
    {
        $session = $context->session();
        $presented = $request->header(strtolower(self::CSRF_HEADER));

        if ($session === null
            || !is_string($presented)
            || $presented === ''
            || !hash_equals((string) $session['csrf_token'], $presented)) {
            $account = $context->account();

            $this->events->record(
                SecurityEventRepository::CSRF_REJECTED,
                null,
                $account?->id(),
                ['path' => $request->path(), 'method' => $request->method()],
                $request->ipAddress()
            );

            throw new AppException('CSRF_REJECTED', 'CSRF token missing or invalid.', 403);
        }
    }
}
