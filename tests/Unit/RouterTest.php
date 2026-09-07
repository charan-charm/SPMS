<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MethodNotAllowedException;
use App\Support\NotFoundException;
use App\Support\Router;
use InvalidArgumentException;
use Tests\Support\Assert;
use Tests\TestResult;

final class RouterTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Router dispatches registered routes to controller handlers', function (): void {
                $router = new Router();
                $router->add('GET', '/api/health', ['App\Controllers\HealthController', 'health']);

                [$class, $action] = $router->dispatch('GET', '/api/health');
                Assert::same('App\Controllers\HealthController', $class);
                Assert::same('health', $action);
            }),

            TestResult::check('Router supports all five explicit HTTP methods', function (): void {
                $router = new Router();
                foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $router->add($method, '/api/echo', ['X', $method]);
                }

                foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    [, $action] = $router->dispatch($method, '/api/echo');
                    Assert::same($method, $action);
                }
            }),

            TestResult::check('Unknown routes throw controlled NotFoundException', function (): void {
                $router = new Router();
                $router->add('GET', '/api/health', ['X', 'y']);

                Assert::throws(NotFoundException::class, function () use ($router): void {
                    $router->dispatch('GET', '/api/unknown');
                });
            }),

            TestResult::check('Known path with wrong method throws MethodNotAllowedException with allowed list', function (): void {
                $router = new Router();
                $router->add('GET', '/api/health', ['X', 'y']);
                $router->add('POST', '/api/health', ['X', 'y']);

                try {
                    $router->dispatch('DELETE', '/api/health');
                    Assert::true(false, 'Expected MethodNotAllowedException');
                } catch (MethodNotAllowedException $e) {
                    Assert::same(['GET', 'POST'], $e->allowedMethods());
                    Assert::same('METHOD_NOT_ALLOWED', $e->errorCode());
                    Assert::same(405, $e->httpStatus());
                }
            }),

            TestResult::check('Router rejects arbitrary HTTP methods', function (): void {
                $router = new Router();
                Assert::throws(InvalidArgumentException::class, function () use ($router): void {
                    $router->add('TRACE', '/api/x', ['X', 'y']);
                });
            }),

            TestResult::check('Router normalization treats trailing slash equivalently', function (): void {
                $router = new Router();
                $router->add('GET', '/api/health', ['X', 'y']);
                Assert::true($router->hasRoute('GET', '/api/health/'));
            }),
        ];
    }
}
