<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;
use PDOException;
use RuntimeException;

final class HealthRepository
{
    public function ping(): bool
    {
        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare('SELECT 1');
            $statement->execute();
            $statement->closeCursor();

            return true;
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed', 0, $e);
        }
    }
}
