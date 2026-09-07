<?php

declare(strict_types=1);

namespace App;

use App\Support\Config;
use App\Support\Logger;
use App\Support\RequestId;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\AppException;
use App\Controllers\HealthController;
use App\Repositories\HealthRepository;
use App\Services\HealthService;
use Throwable;

final class App
{
    private ?Logger $logger = null;

    public static function create(string $basePath): self
    {
        return new self($basePath);
    }

    private function __construct(
        private readonly string $basePath
    ) {
    }

    public function run(): void
    {
        try {
            $this->bootstrap();
            $this->handle();
        } catch (Throwable $e) {
            $this->renderThrowable($e);
        }
    }

    private function bootstrap(): void
    {
        Config::initialize($this->basePath);

        $timezone = Config::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
        date_default_timezone_set($timezone);

        $environment = Config::get('APP_ENV', 'production') ?? 'production';
        ini_set('display_errors', $environment === 'development' ? '1' : '0');
        ini_set('display_startup_errors', $environment === 'development' ? '1' : '0');
        error_reporting(E_ALL);

        $this->logger = new Logger(
            $this->basePath . '/storage/logs',
            Config::get('APP_LOG_LEVEL', 'debug') ?? 'debug'
        );

        $requestId = RequestId::new();
        RequestId::set($requestId);
    }

    private function handle(): void
    {
        $logger = $this->logger;
        $request = Request::fromGlobals();
        $requestId = RequestId::current() ?? '';

        $router = new Router();
        $this->registerRoutes($router);

        try {
            $logger?->info('request.received', [
                'method' => $request->method(),
                'path' => $request->path(),
            ]);

            [$controllerClass, $action] = $router->dispatch($request->method(), $request->path());

            $controller = new $controllerClass();
            $response = $controller->{$action}($request);

            if (!$response instanceof Response) {
                throw new \RuntimeException('Controller did not return a Response');
            }

            $logger?->info('request.completed', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->status(),
            ]);

            $response->send();
        } catch (AppException $e) {
            $logger?->warning('request.app_exception', [
                'method' => $request->method(),
                'path' => $request->path(),
                'error_code' => $e->errorCode(),
                'status' => $e->httpStatus(),
            ]);

            $e->toResponse()->send();
        } catch (Throwable $e) {
            $logger?->error('request.unhandled_exception', [
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            $this->internalErrorResponse()->send();
        }
    }

    private function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/health', [HealthController::class, 'health']);
    }

    private function internalErrorResponse(): Response
    {
        return Response::error(
            'INTERNAL_ERROR',
            'An unexpected error occurred. Please try again later.',
            500
        );
    }

    private function renderThrowable(Throwable $e): void
    {
        // Last-resort handler: bootstrap itself failed or response already sent.
        $logger = $this->logger;

        $logger?->error('bootstrap.failure', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        if (headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        $environment = Config::get('APP_ENV', 'production') ?? 'production';
        $body = [
            'success' => false,
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'An unexpected error occurred. Please try again later.',
            ],
            'meta' => [
                'request_id' => RequestId::current(),
            ],
        ];

        if ($environment === 'development') {
            $body['meta']['diagnostic'] = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ];
        }

        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
