<?php

declare(strict_types=1);

namespace App\Support;

final class RequestId
{
    private static ?string $current = null;

    public static function new(): string
    {
        return bin2hex(random_bytes(8)) . '-' . time();
    }

    public static function set(string $id): void
    {
        self::$current = $id;
    }

    public static function current(): ?string
    {
        return self::$current;
    }
}
