<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Config;
use App\Support\Database;
use PDO;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * D-071 — the runner executes against the TEST environment only (the
 * suite process pins it); plus the fail-closed CLI guards for the new
 * multi-environment surface.
 */
final class MigrationRunnerTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Migration runner executes successfully and is idempotent', function (): void {
                $php = PHP_BINARY;
                $script = dirname(__DIR__, 2) . '/database/migrate.php';

                $first = self::runScript($php, $script, ['--environment=test']);
                Assert::same(0, $first['exit_code'], 'first run failed: ' . $first['output']);
                Assert::contains('migrations tracking table ready', $first['output']);

                $second = self::runScript($php, $script, ['--environment=test']);
                Assert::same(0, $second['exit_code'], 'second run failed: ' . $second['output']);
                Assert::notContains('[running]', $second['output'], 'second run must not re-apply');
            }),

            TestResult::check('Migrations tracking table exists in the test database', function (): void {
                $pdo = Database::connection();
                $row = $pdo->query("SHOW TABLES LIKE 'migrations'")->fetch(PDO::FETCH_NUM);

                Assert::true(is_array($row), 'migrations tracking table missing');

                $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
                Assert::same(Config::get('SPMS_DB_TEST_DATABASE'), (string) $current, 'tracking table must live in the test database');
            }),

            TestResult::check('--fresh without an exact DROP confirm is refused (D-071)', function (): void {
                $php = PHP_BINARY;
                $script = dirname(__DIR__, 2) . '/database/migrate.php';

                $refused = self::runScript($php, $script, ['--environment=test', '--fresh']);
                Assert::same(1, $refused['exit_code'], '--fresh must fail without the name-bound confirm');
                Assert::contains('--fresh refused', $refused['output']);

                $wrong = self::runScript($php, $script, ['--environment=test', '--fresh', '--confirm=DROP:something_else']);
                Assert::same(1, $wrong['exit_code'], 'a mismatched confirm token must be refused');
            }),

            TestResult::check('unknown environments are refused by the trusted CLI parser', function (): void {
                $php = PHP_BINARY;
                $script = dirname(__DIR__, 2) . '/database/migrate.php';

                $bogus = self::runScript($php, $script, ['--environment=bogus_db']);
                Assert::same(1, $bogus['exit_code'], 'only real|demo|test may be addressed');
            }),

            TestResult::check('migration 0024 exists with identical schema across real and demo environments', function (): void {
                $php = PHP_BINARY;
                $script = dirname(__DIR__, 2) . '/database/migrate.php';

                $verified = self::runScript($php, $script, ['--verify', '--all-environments']);
                Assert::same(0, $verified['exit_code'], 'verify failed: ' . $verified['output']);
                Assert::contains('`spms_real` at 0024_create_notifications', $verified['output']);
                Assert::contains('`spms_demo` at 0024_create_notifications', $verified['output']);
                Assert::contains('OK — identical migration set', $verified['output']);
            }),
        ];
    }

    /** @param list<string> $flags */
    private static function runScript(string $php, string $script, array $flags = []): array
    {
        $command = sprintf(
            '"%s" "%s"%s 2>&1',
            $php,
            $script,
            $flags === [] ? '' : ' ' . implode(' ', array_map('escapeshellarg', $flags))
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
        ];
    }
}
