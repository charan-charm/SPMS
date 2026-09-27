<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /** @var array<string, PDO> */
    private static array $connections = [];
    private static ?string $currentProfile = null;

    public static function switchTo(string $profile): PDO
    {
        $profile = DataEnvironments::assertProfile($profile);
        self::$currentProfile = $profile;

        if (isset(self::$connections[$profile])) {
            return self::$connections[$profile];
        }

        $config = DataEnvironments::connectionConfig($profile);
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['HOST'], $config['PORT'], $config['DATABASE'], $config['CHARSET']);

        try {
            $pdo = new PDO($dsn, $config['USERNAME'], $config['PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $pdo->exec(
                "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Database connection failed for environment ' . $profile,
                (int) $e->getCode(),
                $e
            );
        }

        self::$connections[$profile] = $pdo;

        return $pdo;
    }

    public static function connection(?string $profile = null): PDO
    {
        if ($profile !== null) {
            return self::switchTo($profile);
        }

        if (self::$currentProfile === null) {
            self::$currentProfile = DataEnvironments::resolveProfile(Config::all());
        }

        return self::switchTo(self::$currentProfile);
    }

    public static function currentProfile(): string
    {
        if (self::$currentProfile === null) {
            self::$currentProfile = DataEnvironments::resolveProfile(Config::all());
        }

        return self::$currentProfile;
    }

    public static function reset(): void
    {
        self::$connections = [];
        self::$currentProfile = null;
    }
}
