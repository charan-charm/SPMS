<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

class AppException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus,
        private readonly array $context = []
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function toResponse(): Response
    {
        return Response::error($this->errorCode, $this->getMessage(), $this->httpStatus, $this->context);
    }
}
