<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Database;
use PDO;
use PDOException;
use Tests\Support\Assert;
use Tests\TestResult;

final class DatabaseConnectionTest
{
    public static function run(): array
    {
        return [
            TestResult::check('PDO connects to the configured MariaDB test database (D-071)', function (): void {
                $pdo = Database::connection();
                Assert::true($pdo instanceof PDO, 'connection() did not return PDO');

                $current = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
                \Tests\Support\Assert::same(
                    (string) \App\Support\Config::get('SPMS_DB_TEST_DATABASE'),
                    $current,
                    'automated tests must execute inside the dedicated test database, never REAL or DEMO'
                );
            }),

            TestResult::check('Prepared statement SELECT 1 executes', function (): void {
                $pdo = Database::connection();
                $statement = $pdo->prepare('SELECT 1');
                $statement->execute();
                $row = $statement->fetch(PDO::FETCH_NUM);
                $statement->closeCursor();

                Assert::same('1', (string) ($row[0] ?? '0'));
            }),

            TestResult::check('PDO failure message never contains the password', function (): void {
                try {
                    new PDO(
                        'mysql:host=127.0.0.1;port=1;dbname=spms;charset=utf8mb4',
                        'invalid_user_for_test',
                        'invalid_password_for_test',
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
                    );

                    return;
                } catch (PDOException $e) {
                    Assert::notContains('invalid_password_for_test', $e->getMessage(), 'password leaked into PDO message');
                }
            }),

            TestResult::check('Database connection uses utf8mb4 charset', function (): void {
                $pdo = Database::connection();
                $row = $pdo->query("SHOW VARIABLES LIKE 'collation_connection'")->fetch(PDO::FETCH_ASSOC);

                Assert::true(is_array($row), 'collation_connection variable missing');
                Assert::contains('utf8mb4', (string) ($row['Value'] ?? ''));
            }),
        ];
    }
}
