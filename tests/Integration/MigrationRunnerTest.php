<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Database;
use PDO;
use Tests\Support\Assert;
use Tests\TestResult;

final class MigrationRunnerTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Migration runner executes successfully and is idempotent', function (): void {
                $php = PHP_BINARY;
                $script = dirname(__DIR__, 2) . '/database/migrate.php';

                $first = self::runScript($php, $script);
                Assert::same(0, $first['exit_code'], 'first run failed: ' . $first['output']);
                Assert::contains('[ok] migrations tracking table ready', $first['output']);

                $second = self::runScript($php, $script);
                Assert::same(0, $second['exit_code'], 'second run failed: ' . $second['output']);
                Assert::contains('[ok] migrations tracking table ready', $second['output']);
                Assert::notContains('[running]', $second['output'], 'second run must not re-apply');
            }),

            TestResult::check('Migrations tracking table exists in the database', function (): void {
                $pdo = Database::connection();
                $row = $pdo->query("SHOW TABLES LIKE 'migrations'")->fetch(PDO::FETCH_NUM);

                Assert::true(is_array($row), 'migrations tracking table missing');
            }),
        ];
    }

    private static function runScript(string $php, string $script): array
    {
        $command = sprintf('"%s" "%s" 2>&1', $php, $script);

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
        ];
    }
}
