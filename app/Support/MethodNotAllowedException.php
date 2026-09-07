<?php

declare(strict_types=1);

namespace App\Support;

final class MethodNotAllowedException extends AppException
{
    private array $allowedMethods;

    public function __construct(array $allowedMethods = [])
    {
        parent::__construct(
            'METHOD_NOT_ALLOWED',
            'The HTTP method is not allowed for this resource.',
            405
        );

        $this->allowedMethods = $allowedMethods;
    }

    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }

    public function toResponse(): Response
    {
        return Response::error(
            $this->errorCode(),
            $this->getMessage(),
            $this->httpStatus(),
            $this->context(),
            ['allow' => implode(', ', $this->allowedMethods)]
        );
    }
}
