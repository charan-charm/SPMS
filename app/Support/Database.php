<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = Config::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
        $port = Config::get('DB_PORT', '3306') ?? '3306';
        $database = Config::get('DB_DATABASE') ?? '';
        $username = Config::get('DB_USERNAME') ?? '';
        $password = Config::get('DB_PASSWORD') ?? '';
        $charset = Config::get('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4';

        if ($database === '' || $username === '') {
            throw new RuntimeException('Database configuration is incomplete');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            self::$pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Strict SQL semantics for the SPMS connection/session only —
            // the global MariaDB configuration is intentionally left
            // untouched (docs/10 D-058).
            self::$pdo->exec(
                "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
            );
        } catch (PDOException $e) {
            self::$pdo = null;

            throw new RuntimeException(
                'Database connection failed',
                (int) $e->getCode(),
                $e
            );
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
