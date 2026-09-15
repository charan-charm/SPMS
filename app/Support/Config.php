<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    private static ?Config $instance = null;

    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function initialize(string $basePath): void
    {
        if (self::$instance !== null) {
            return;
        }

        $values = [];

        $envFile = $basePath . DIRECTORY_SEPARATOR . '.env';

        if (is_readable($envFile)) {
            $values = Env::parse((string) file_get_contents($envFile));
        }

        // D-071: resolve the trusted REAL/DEMO/TEST data environment and
        // overlay its DB_* connection values BEFORE the singleton exists,
        // so the choice is immutable per process and never request-driven.
        $values = DataEnvironments::apply($values);

        self::$instance = new self($values);
    }

    public static function overrideForTesting(array $values): void
    {
        if (self::$instance === null) {
            self::$instance = new self($values);
            return;
        }

        foreach ($values as $key => $value) {
            self::$instance->values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $instance = self::$instance;

        if ($instance === null) {
            throw new \RuntimeException('Config has not been initialized');
        }

        return $instance->values[$key] ?? $default;
    }
}
