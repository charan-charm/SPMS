<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Support\Config;
use App\Controllers\HealthController;
use App\Support\Database;
use App\Support\MethodNotAllowedException;
use App\Support\NotFoundException;
use App\Support\Request;
use Tests\Support\Assert;
use Tests\TestResult;

final class SafeErrorsTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Database failure yields degraded health without exposing internals', function (): void {
                // Capture the test-pinned connection settings (D-071: the
                // suite runs on spms_test — restoring hardcoded dev values
                // here would silently repoint every later test).
                $original = [
                    'DB_HOST' => Config::get('DB_HOST'),
                    'DB_PORT' => Config::get('DB_PORT'),
                    'DB_DATABASE' => Config::get('DB_DATABASE'),
                    'DB_USERNAME' => Config::get('DB_USERNAME'),
                    'DB_PASSWORD' => Config::get('DB_PASSWORD'),
                ];
                Config::overrideForTesting(['DB_PORT' => '1', 'DB_USERNAME' => 'secret_user_test', 'DB_PASSWORD' => 'secret_pass_test']);
                Database::reset();

                $response = (new HealthController())->health(Request::create('GET', '/api/health'));

                Assert::same(503, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same('degraded', $body['data']['overall']);
                Assert::same('unavailable', $body['data']['database']['state']);

                $encoded = json_encode($body, JSON_UNESCAPED_SLASHES) ?: '';
                foreach (['SQLSTATE', 'secret_user_test', 'secret_pass_test', 'mysql:host', 'C:', 'D:', 'vendor', '.php'] as $leak) {
                    Assert::notContains($leak, $encoded, "internals leaked: {$leak}");
                }

                Config::overrideForTesting(array_filter($original, static fn (?string $v): bool => $v !== null));
                Database::reset();
            }),

            TestResult::check('Health recovers after database comes back', function (): void {
                $response = (new HealthController())->health(Request::create('GET', '/api/health'));

                Assert::same(200, $response->status());
                Assert::same('healthy', $response->body()['data']['overall']);
            }),

            TestResult::check('404 response is safe and stable', function (): void {
                $response = (new NotFoundException('/api/private/x'))->toResponse();

                Assert::same(404, $response->status());
                $body = $response->body();
                Assert::same('NOT_FOUND', $body['error']['code']);

                $encoded = json_encode($body, JSON_UNESCAPED_SLASHES) ?: '';
                foreach (['C:', 'D:', 'stack', 'SQLSTATE', 'vendor'] as $leak) {
                    Assert::notContains($leak, $encoded, "internals leaked: {$leak}");
                }
            }),

            TestResult::check('405 response reports allowed methods and stays safe', function (): void {
                $response = (new MethodNotAllowedException(['GET']))->toResponse();

                Assert::same(405, $response->status());
                $body = $response->body();
                Assert::same('METHOD_NOT_ALLOWED', $body['error']['code']);
                Assert::same('GET', $body['meta']['allow']);
            }),

            TestResult::check('Logs are written outside the public web root', function (): void {
                $logDir = dirname(__DIR__, 2) . '/storage/logs';

                Assert::true(is_dir($logDir), 'log directory missing');
                $publicDir = dirname(__DIR__, 2) . '/public';
                Assert::false(str_starts_with($logDir, $publicDir), 'logs must not live under public/');
            }),
        ];
    }
}
