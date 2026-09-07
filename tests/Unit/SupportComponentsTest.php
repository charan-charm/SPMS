<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Config;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Request;
use App\Support\RequestId;
use App\Support\Response;
use Tests\Support\Assert;
use Tests\TestResult;

final class SupportComponentsTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Config parses KEY=VALUE and ignores comments/blank lines', function (): void {
                $env = Env::parse(<<<'ENV'
# comment line

APP_ENV=development
DB_HOST=127.0.0.1
DB_PASSWORD="s3cr3t"
DB_PORT='3307'
BAD LINE WITHOUT EQUALS
empty=
ENV);

                Assert::same('development', $env['APP_ENV']);
                Assert::same('127.0.0.1', $env['DB_HOST']);
                Assert::same('s3cr3t', $env['DB_PASSWORD']);
                Assert::same('3307', $env['DB_PORT']);
                Assert::same('', $env['empty']);
            }),

            TestResult::check('Config::get returns defaults for missing keys', function (): void {
                Config::overrideForTesting(['APP_ENV' => 'testing']);
                Assert::same('testing', Config::get('APP_ENV'));
                Assert::same('fallback', Config::get('APP_MISSING', 'fallback'));
                Assert::same(null, Config::get('APP_MISSING'));
            }),

            TestResult::check('Logger redacts sensitive context keys', function (): void {
                $dir = sys_get_temp_dir() . '/spms-test-logs-' . bin2hex(random_bytes(4));
                $logger = new Logger($dir, 'debug');

                $logger->error('db failed', ['password' => 'abc123', 'token' => 'xyz', 'host' => 'localhost']);

                $logFile = $dir . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log';
                $content = (string) file_get_contents($logFile);
                Assert::contains('[REDACTED]', $content);
                Assert::notContains('abc123', $content);
                Assert::notContains('xyz', $content);
                Assert::contains('localhost', $content);
                Assert::contains('db failed', $content);

                @unlink($logFile);
                @rmdir($dir);
            }),

            TestResult::check('RequestId is 26+ char unique identifier', function (): void {
                $a = RequestId::new();
                $b = RequestId::new();
                Assert::true(strlen($a) >= 20, 'ID too short');
                Assert::true($a !== $b, 'IDs must be unique');
            }),

            TestResult::check('Request::create exposes method/path/body/query/headers', function (): void {
                $request = Request::create('POST', '/api/health', ['q' => '1'], ['key' => 'value'], ['X-Request-Id' => 'abc']);

                Assert::same('POST', $request->method());
                Assert::same('/api/health', $request->path());
                Assert::same('1', $request->queryParam('q'));
                Assert::same('value', $request->bodyParam('key'));
                Assert::same('abc', $request->header('x-request-id'));
                Assert::same(null, $request->header('nope'));
            }),
        ];
    }
}
