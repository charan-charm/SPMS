<?php

declare(strict_types=1);

namespace Tests\API;

use App\Support\Request;
use App\Support\RequestId;
use App\Support\Router;
use App\Controllers\HealthController;
use Tests\Support\Assert;
use Tests\TestResult;

final class HealthApiTest
{
    public static function run(): array
    {
        return [
            TestResult::check('GET /api/health returns 200 with correct envelope when DB is up', function (): void {
                RequestId::set(RequestId::new());

                $controller = new HealthController();
                $response = $controller->health(Request::create('GET', '/api/health'));

                Assert::same(200, $response->status());
                $body = $response->body();
                Assert::same(true, $body['success']);
                Assert::same('healthy', $body['data']['overall']);
                Assert::same('available', $body['data']['application']['state']);
                Assert::same('connected', $body['data']['database']['state']);
            }),

            TestResult::check('Router + controller dispatch path resolves and returns Response', function (): void {
                $router = new Router();
                $router->add('GET', '/api/health', [HealthController::class, 'health']);

                [$class, $action] = $router->dispatch('GET', '/api/health');
                $controller = new $class();
                $response = $controller->{$action}(Request::create('GET', '/api/health'));

                Assert::true($response instanceof \App\Support\Response);
                Assert::same('healthy', $response->body()['data']['overall']);
            }),
        ];
    }
}
