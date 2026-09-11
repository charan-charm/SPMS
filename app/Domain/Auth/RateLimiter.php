<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Repositories\SecurityEventRepository;
use App\Repositories\RateLimitException;

/**
 * W1 — DB-backed rate limiting (docs/10 D-053.6–7).
 *
 * The authoritative state lives in the security_events append stream —
 * never in process memory. Throttling is bounded and temporary: a
 * sliding window of recent failed attempts per bucket; the cooldown is
 * the window itself. There is no permanent lockout, so throttling cannot
 * be weaponized into a denial-of-service against another account.
 *
 * Buckets are keyed by hashed email and by IP (both orientations are
 * always evaluated; the broadest denial wins).
 */
final class RateLimiter
{
    public function __construct(
        private readonly SecurityEventRepository $events = new SecurityEventRepository(),
        private readonly int $windowMinutes = 15,
        private readonly int $maxAttempts = 5
    ) {
    }

    /**
     * @param array<int, string> $contextKeys bucket keys (e.g. EMAIL:<hash>, IP:<addr>)
     *
     * @throws RateLimitException when any bucket is at/over the threshold
     */
    public function assertAllowed(array $eventTypes, array $contextKeys, ?string $ipAddress): void
    {
        $worstAttempts = 0;

        foreach ($eventTypes as $eventType) {
            foreach ($contextKeys as $contextKey) {
                $count = $this->events->countRecent($eventType, $contextKey, $this->windowMinutes);
                $worstAttempts = max($worstAttempts, $count);
            }
        }

        if ($worstAttempts >= $this->maxAttempts) {
            $this->events->record(
                SecurityEventRepository::RATE_LIMIT_REJECTED,
                $contextKeys[0],
                null,
                ['window_minutes' => $this->windowMinutes, 'recent_attempts' => $worstAttempts],
                $ipAddress
            );

            throw new RateLimitException(
                sprintf('Too many attempts. Try again in %d minutes.', $this->windowMinutes),
                $this->windowMinutes * 60
            );
        }
    }

    /**
     * Deterministic email bucket key — hashed so raw addresses are not
     * stored as throttle identifiers.
     */
    public static function emailBucket(string $normalizedEmail): string
    {
        return 'EMAIL:' . hash('sha256', $normalizedEmail);
    }

    public static function ipBucket(?string $ipAddress): ?string
    {
        return $ipAddress === null || $ipAddress === '' ? null : 'IP:' . $ipAddress;
    }
}
