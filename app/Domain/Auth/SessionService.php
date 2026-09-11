<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Repositories\SessionRepository;
use App\Repositories\SecurityEventRepository;
use App\Support\Security\TokenHasher;
use DateTimeImmutable;

/**
 * W1 — Server-side session lifecycle (docs/10 D-051).
 *
 * The raw 256-bit session identifier exists only in the issued cookie;
 * the database stores its SHA-256 hash. Idle expiry (24h default) slides
 * with authenticated activity; absolute expiry (7d default) never
 * extends. Revoked/expired sessions can never authenticate again.
 */
final class SessionService
{
    public const IDLE_HOURS = 24;
    public const ABSOLUTE_DAYS = 7;

    public function __construct(
        private readonly SessionRepository $sessions = new SessionRepository(),
        private readonly SecurityEventRepository $events = new SecurityEventRepository()
    ) {
    }

    /**
     * Create a fresh session for an authenticated account. Any prior
     * session row passed in is revoked first, so presenting a stolen
     * cookie to the login endpoint cannot reuse the old identifier
     * (session fixation defense).
     *
     * @param array<string, mixed>|null $priorSession
     * @return array{session: array<string, mixed>, raw_token: string, csrf_token: string}
     */
    public function start(string $accountId, ?array $priorSession = null): array
    {
        if ($priorSession !== null) {
            $this->revoke($priorSession, 'LOGIN_REGENERATION');
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowUtc = $now->format('Y-m-d H:i:s');

        $rawToken = TokenHasher::generate();
        $csrfToken = TokenHasher::generate();

        $session = [
            'id' => $this->uuid(),
            'account_id' => $accountId,
            'session_token_hash' => TokenHasher::hash($rawToken),
            'csrf_token' => $csrfToken,
            'created_at' => $nowUtc,
            'last_activity_at' => $nowUtc,
            'expires_at' => $now->modify('+' . self::IDLE_HOURS . ' hours')->format('Y-m-d H:i:s'),
            'absolute_expires_at' => $now->modify('+' . self::ABSOLUTE_DAYS . ' days')->format('Y-m-d H:i:s'),
            'revoked_at' => null,
        ];

        $this->sessions->insert($session);

        return [
            'session' => $session,
            'raw_token' => $rawToken,
            'csrf_token' => $csrfToken,
        ];
    }

    /**
     * Resolve a presented cookie token into a live session row. Expired
     * (idle or absolute) sessions are lazily revoked and return null.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $rawToken, DateTimeImmutable $now): ?array
    {
        $session = $this->sessions->findBySessionTokenHash(TokenHasher::hash($rawToken));

        if ($session === null) {
            return null;
        }

        if ($session['revoked_at'] !== null) {
            return null;
        }

        $nowUtc = $now->format('Y-m-d H:i:s');

        if ($session['absolute_expires_at'] <= $nowUtc) {
            $this->revoke($session, 'ABSOLUTE_EXPIRY');

            return null;
        }

        if ($session['expires_at'] <= $nowUtc) {
            $this->revoke($session, 'IDLE_EXPIRY');

            return null;
        }

        return $session;
    }

    /**
     * Slide the idle window forward on authenticated activity.
     *
     * @param array<string, mixed> $session
     */
    public function touch(array $session): void
    {
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->sessions->touchActivity(
            (string) $session['id'],
            $now->modify('+' . self::IDLE_HOURS . ' hours')->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s')
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    public function revoke(array $session, string $reason): void
    {
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->sessions->revoke((string) $session['id'], $now->format('Y-m-d H:i:s'));

        $this->events->record(
            SecurityEventRepository::SESSION_REVOKED,
            null,
            (string) $session['account_id'],
            ['session_id' => $session['id'], 'reason' => $reason],
            null
        );
    }

    /**
     * Revoke every live session of an account (used after password
     * reset) — old sessions must not survive a credential change.
     *
     * @return int number of sessions revoked
     */
    public function revokeAllForAccount(string $accountId, string $reason): int
    {
        $active = $this->sessions->activeForAccount($accountId);

        $this->sessions->revokeAllForAccount($accountId, (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));

        foreach ($active as $session) {
            $this->events->record(
                SecurityEventRepository::SESSION_REVOKED,
                null,
                $accountId,
                ['session_id' => $session['id'], 'reason' => $reason],
                null
            );
        }

        return count($active);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
