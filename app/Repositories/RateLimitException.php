<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AppException;

/**
 * W1 — Thrown when DB-backed rate limiting denies an operation
 * (docs/10 D-053.7). Mapped to HTTP 429 by the envelope.
 */
final class RateLimitException extends AppException
{
    public function __construct(string $message, private readonly int $retryAfterSeconds)
    {
        parent::__construct('RATE_LIMITED', $message, 429, [
            'retry_after_seconds' => $retryAfterSeconds,
        ]);
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
