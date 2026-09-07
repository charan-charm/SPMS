<?php

declare(strict_types=1);

namespace App\Support;

final class NotFoundException extends AppException
{
    public function __construct(string $path = '')
    {
        parent::__construct(
            'NOT_FOUND',
            'The requested resource was not found.',
            404,
            $path === '' ? [] : ['path' => $path]
        );
    }
}
