<?php

declare(strict_types=1);

namespace App;

use App\Support\Config;
use App\Support\Logger;
use App\Support\RequestId;
use App\Support\Request;
use App\Support\Response;
use App\Support\HtmlResponse;
use App\Support\CsvResponse;
use App\Support\ExcelResponse;
use App\Support\PdfResponse;
use App\Support\Router;
use App\Support\AppException;
use App\Controllers\HealthController;
use App\Controllers\PageController;
use App\Controllers\EnergyBalanceController;
use App\Controllers\EnergyCostController;
use App\Controllers\IntelligenceController;
use App\Controllers\AuthController;
use App\Controllers\HouseholdController;
use App\Controllers\DemoScenarioController;
use App\Controllers\BillController;
use App\Controllers\SolarController;
use App\Controllers\ForecastController;
use App\Controllers\RecommendationController;
use App\Controllers\ReportController;
use App\Controllers\AnalyticsController;
use App\Controllers\NotificationController;
use App\Controllers\AssistantController;
use App\Controllers\WeatherController;
use App\Repositories\HealthRepository;
use App\Security\AuthMiddleware;
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
        ob_start();
        try {
            $this->bootstrap();
            $this->handle();
        } catch (Throwable $e) {
            $this->renderThrowable($e);
        } finally {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
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
        Logger::setDefault($this->logger);

        $requestId = RequestId::new();
        RequestId::set($requestId);
    }

    private function handle(): void
    {
        $logger = $this->logger;

        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $isAllowedOrigin = self::isOriginAllowed($origin);

        // Preflight OPTIONS handling (CORS support across local dev ports e.g. 5173 / 5174 / 8080 / 8081)
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            if (PHP_SAPI !== 'cli') {
                http_response_code(204);
                if ($isAllowedOrigin) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Access-Control-Allow-Credentials: true');
                    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
                    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-SPMS-CSRF, X-Request-Id, Accept');
                    header('Access-Control-Max-Age: 86400');
                }
            }

            return;
        }

        // Malformed request bodies are client errors and must surface as
        // a 400 envelope, never an internal error.
        try {
            $request = Request::fromGlobals();
        } catch (AppException $e) {
            $logger?->warning('request.malformed_body', [
                'error_code' => $e->errorCode(),
                'status' => $e->httpStatus(),
            ]);
            $errResponse = $e->toResponse();
            if ($isAllowedOrigin) {
                $errResponse = $errResponse
                    ->withHeader('Access-Control-Allow-Origin', $origin)
                    ->withHeader('Access-Control-Allow-Credentials', 'true');
            }
            $errResponse->send();

            return;
        }

        $requestId = RequestId::current() ?? '';

        $router = new Router();
        $this->registerRoutes($router);

        try {
            $logger?->info('request.received', [
                'method' => $request->method(),
                'path' => $request->path(),
            ]);

            [$controllerClass, $action] = $router->dispatch($request->method(), $request->path());

            // Middleware seam (docs/10 D-055): Router → Middleware →
            // Controller. Authentication context is resolved from the
            // server-side session; CSRF is enforced here for
            // authenticated state-changing requests.
            $request = AuthMiddleware::process($request);

            $controller = new $controllerClass();
            $response = $controller->{$action}($request);

            if (!$response instanceof Response && !$response instanceof HtmlResponse && !$response instanceof CsvResponse && !$response instanceof ExcelResponse && !$response instanceof PdfResponse) {
                throw new \RuntimeException('Controller did not return a Response');
            }

            $logger?->info('request.completed', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->status(),
            ]);

            if ($response instanceof Response) {
                if ($isAllowedOrigin) {
                    $response = $response
                        ->withHeader('Access-Control-Allow-Origin', $origin)
                        ->withHeader('Access-Control-Allow-Credentials', 'true')
                        ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id, X-SPMS-CSRF');
                }
            } elseif (($response instanceof HtmlResponse || $response instanceof CsvResponse || $response instanceof ExcelResponse || $response instanceof PdfResponse) && $isAllowedOrigin && PHP_SAPI !== 'cli') {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Expose-Headers', 'Content-Disposition, X-Request-Id, X-SPMS-CSRF');
            }

            $response->send();
        } catch (AppException $e) {
            $logger?->warning('request.app_exception', [
                'method' => $request->method(),
                'path' => $request->path(),
                'error_code' => $e->errorCode(),
                'status' => $e->httpStatus(),
            ]);

            $errResponse = $e->toResponse();
            if ($isAllowedOrigin) {
                $errResponse = $errResponse
                    ->withHeader('Access-Control-Allow-Origin', $origin)
                    ->withHeader('Access-Control-Allow-Credentials', 'true')
                    ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id, X-SPMS-CSRF');
            }
            $errResponse->send();
        } catch (Throwable $e) {
            $logger?->error('request.unhandled_exception', array_filter([
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ], static fn ($v) => $v !== null));

            $internalResponse = $this->internalErrorResponse();
            if ($isAllowedOrigin) {
                $internalResponse = $internalResponse
                    ->withHeader('Access-Control-Allow-Origin', $origin)
                    ->withHeader('Access-Control-Allow-Credentials', 'true')
                    ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id, X-SPMS-CSRF');
            }
            $internalResponse->send();
        }
    }

    private function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/health', [HealthController::class, 'health']);

        // F4 — Energy Balance & Situation Analysis (versioned API boundary)
        $router->add('POST', '/api/v1/energy/balance', [EnergyBalanceController::class, 'classify']);
        $router->add('GET', '/api/v1/energy/balance', [EnergyBalanceController::class, 'current']);

        // F5 — Energy Cost Analysis (versioned API boundary)
        $router->add('POST', '/api/v1/energy/cost/estimate', [EnergyCostController::class, 'estimate']);
        $router->add('GET', '/api/v1/energy/cost/historical', [EnergyCostController::class, 'historical']);

        // F6 — Deterministic Intelligence (versioned API boundary)
        $router->add('GET', '/api/v1/intelligence/insights', [IntelligenceController::class, 'list']);
        $router->add('GET', '/api/v1/intelligence/insights/{id}', [IntelligenceController::class, 'show']);

        // W1 — Authentication Foundation (versioned API boundary)
        $router->add('POST', '/api/v1/auth/register', [AuthController::class, 'register']);
        $router->add('POST', '/api/v1/auth/verify', [AuthController::class, 'verify']);
        $router->add('POST', '/api/v1/auth/verify/resend', [AuthController::class, 'verifyResend']);
        $router->add('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
        $router->add('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);
        $router->add('GET', '/api/v1/auth/session', [AuthController::class, 'session']);
        $router->add('POST', '/api/v1/auth/recovery/request', [AuthController::class, 'recoveryRequest']);
        $router->add('POST', '/api/v1/auth/recovery/verify', [AuthController::class, 'recoveryVerify']);
        $router->add('POST', '/api/v1/auth/recovery/confirm', [AuthController::class, 'recoveryConfirm']);

        // W3 — Household ownership context (versioned API boundary). The
        // household is always derived server-side from membership; there
        // is no household-id-addressed read route by design (docs/10 D-050).
        // W3.1 — PATCH completes the profile: own-route current-context
        // modification only, scope still server-derived (docs/10 D-065).
        $router->add('GET', '/api/v1/household', [HouseholdController::class, 'current']);
        $router->add('POST', '/api/v1/household', [HouseholdController::class, 'create']);
        $router->add('POST', '/api/v1/household/demo/ensure', [HouseholdController::class, 'ensureDemo']);
        $router->add('PATCH', '/api/v1/household', [HouseholdController::class, 'update']);

        // D1 — Demo Scenario Discovery & Switching (DEMO-only, CSRF-protected for mutations)
        $router->add('GET', '/api/v1/demo/scenarios', [DemoScenarioController::class, 'index']);
        $router->add('POST', '/api/v1/demo/scenarios/switch', [DemoScenarioController::class, 'switch']);

        // B2 — Secure bill document intake (docs/10 D-068). Authenticated,
        // CSRF-protected multipart upload; household derived server-side;
        // intake + temporary private storage only — no OCR, no drafts.
        $router->add('POST', '/api/v1/bills/upload', [BillController::class, 'upload']);

        // B3 — bill extraction workflow (docs/10 D-069). Authenticated +
        // CSRF + household-scoped (foreign ids are 404). Runs the private
        // temporary document through the ratified Mistral client, persists
        // the immutable AI draft + provenance + validations + flags and
        // lets the B1 lifecycle decide the honest state. NEVER verifies.
        $router->add('POST', '/api/v1/bills/{bill_id}/process', [BillController::class, 'process']);

        // B4 — bills workspace read surface. Authenticated + household
        // scoped like every bill route (foreign/unknown ids are the same
        // indistinguishable 404); reads expose the B1 vocabulary only
        // (whitelisted values, provenance, validations, quality flags,
        // document assessment) — never paths, keys, or provider payloads.
        $router->add('GET', '/api/v1/bills', [BillController::class, 'index']);
        $router->add('GET', '/api/v1/bills/{bill_id}', [BillController::class, 'show']);
        $router->add('POST', '/api/v1/bills/{bill_id}/verify', [BillController::class, 'verify']);
        $router->add('PATCH', '/api/v1/bills/{bill_id}', [BillController::class, 'update']);
        $router->add('POST', '/api/v1/bills/{bill_id}/reconcile', [BillController::class, 'reconcile']);

        // SPMS O4 — Solar Connection & Inverter Telemetry surface (docs/10 D-059, D-060, D-061).
        // Authenticated + CSRF (mutations) + household-scoped.
        $router->add('GET', '/api/v1/solar/connection', [SolarController::class, 'connection']);
        $router->add('POST', '/api/v1/solar/stations/discover', [SolarController::class, 'discoverStations']);
        $router->add('POST', '/api/v1/solar/connection/test', [SolarController::class, 'testConnection']);
        $router->add('POST', '/api/v1/solar/connection', [SolarController::class, 'saveConnection']);
        $router->add('DELETE', '/api/v1/solar/connection', [SolarController::class, 'disconnect']);
        $router->add('GET', '/api/v1/solar/latest', [SolarController::class, 'latest']);
        $router->add('GET', '/api/v1/solar/history', [SolarController::class, 'history']);
        $router->add('GET', '/api/v1/solar/days', [SolarController::class, 'days']);
        $router->add('POST', '/api/v1/solar/sync', [SolarController::class, 'sync']);
        $router->add('POST', '/api/v1/solar/sync/auto', [SolarController::class, 'autoSync']);

        // F3 — Energy Forecasting (docs/02 §2, docs/06 §3, docs/10 D-040).
        // Authenticated + CSRF (mutations) + household-scoped.
        $router->add('GET', '/api/v1/forecasts', [ForecastController::class, 'index']);
        $router->add('GET', '/api/v1/forecasts/validation', [ForecastController::class, 'validation']);
        $router->add('GET', '/api/v1/forecasts/performance', [ForecastController::class, 'performance']);
        $router->add('POST', '/api/v1/forecasts/generate', [ForecastController::class, 'generate']);

        // F12 — Weather Intelligence (Section 5).
        // Authenticated + household-scoped.
        $router->add('GET', '/api/v1/weather', [WeatherController::class, 'current']);

        // F7 — Recommendations (docs/01 §15, docs/02 §2 F7, docs/06 §3, docs/10 D-072).
        // Authenticated + CSRF (mutations) + household-scoped.
        $router->add('GET', '/api/v1/recommendations', [RecommendationController::class, 'index']);
        $router->add('GET', '/api/v1/recommendations/{id}', [RecommendationController::class, 'show']);
        $router->add('POST', '/api/v1/recommendations/generate', [RecommendationController::class, 'generate']);
        $router->add('POST', '/api/v1/recommendations/{id}/dismiss', [RecommendationController::class, 'dismiss']);
        $router->add('POST', '/api/v1/recommendations/{id}/feedback', [RecommendationController::class, 'submitFeedback']);

        // F8 — Reporting & Data Export (docs/02 §2 F8, docs/03 §4).
        // Authenticated + household-scoped.
        $router->add('GET', '/api/v1/reports/summary', [ReportController::class, 'summary']);
        $router->add('GET', '/api/v1/reports/monthly', [ReportController::class, 'monthly']);
        $router->add('GET', '/api/v1/reports/monthly/export/pdf', [ReportController::class, 'exportPdf']);
        $router->add('GET', '/api/v1/reports/monthly/export/excel', [ReportController::class, 'exportExcel']);
        $router->add('GET', '/api/v1/reports/bills/export', [ReportController::class, 'exportBills']);
        $router->add('GET', '/api/v1/reports/solar/export', [ReportController::class, 'exportSolar']);

        // F10 — Analytics Workspace (docs/02 §2 F10).
        // Authenticated + household-scoped. Read-only aggregation over existing data.
        $router->add('GET', '/api/v1/analytics/summary', [AnalyticsController::class, 'summary']);
        $router->add('GET', '/api/v1/analytics/monthly', [AnalyticsController::class, 'monthly']);
        $router->add('GET', '/api/v1/analytics/daily', [AnalyticsController::class, 'daily']);
        $router->add('GET', '/api/v1/analytics/day-curve', [AnalyticsController::class, 'dayCurve']);

        // F9 — Notification Center
        // Authenticated + household-scoped.
        $router->add('GET', '/api/v1/notifications', [NotificationController::class, 'index']);
        $router->add('POST', '/api/v1/notifications/read-all', [NotificationController::class, 'markAllRead']);
        $router->add('POST', '/api/v1/notifications/{id}/read', [NotificationController::class, 'markRead']);

        // F8 — AI Energy Assistant (Restricted context-aware assistant).
        // Authenticated + CSRF (mutations) + household-scoped.
        $router->add('POST', '/api/v1/assistant', [AssistantController::class, 'ask']);
        $router->add('GET', '/api/v1/assistant', [AssistantController::class, 'history']);
        $router->add('DELETE', '/api/v1/assistant', [AssistantController::class, 'clear']);

        // Public web pages
        $router->add('GET', '/', [PageController::class, 'landing']);
        $router->add('GET', '/login', [PageController::class, 'login']);
        $router->add('GET', '/register', [PageController::class, 'register']);
        $router->add('GET', '/privacy', [PageController::class, 'privacy']);
        $router->add('GET', '/contact', [PageController::class, 'contact']);
    }

    /**
     * Parse and canonicalize an HTTP/HTTPS web origin per RFC 6454.
     *
     * Returns canonical '<scheme>://<host>[:<port>]' string, or null if malformed/disallowed.
     * Rejects wildcards, paths (beyond optional '/'), userinfo, query strings, fragments,
     * and unsupported schemes. Normalizes scheme and host to lowercase and strips default ports
     * (80 for http, 443 for https).
     */
    public static function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || str_contains($origin, '*')) {
            return null;
        }

        if (str_contains($origin, '@') || str_contains($origin, '?') || str_contains($origin, '#')) {
            return null;
        }

        $parts = parse_url($origin);
        if ($parts === false || !is_array($parts)) {
            return null;
        }

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '' || str_contains($host, ' ') || str_contains($host, '*')) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null) {
            if ($port <= 0 || $port > 65535) {
                return null;
            }
            if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
                $port = null;
            }
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * Determine whether an HTTP Origin header is permitted under CORS policy.
     *
     * Production enforces a canonicalized origin match against CORS_ALLOWED_ORIGIN (wildcards disallowed).
     * Local loopback origins (localhost / 127.0.0.1) are permitted ONLY when APP_ENV === 'development'.
     */
    public static function isOriginAllowed(string $origin, ?string $appEnv = null, ?string $configuredOrigin = null): bool
    {
        $origin = trim($origin);
        if ($origin === '') {
            return false;
        }

        $appEnv = $appEnv ?? (Config::get('APP_ENV', 'production') ?? 'production');
        $configuredOrigin = $configuredOrigin ?? Config::get('CORS_ALLOWED_ORIGIN');

        // 1. Explicitly configured production origin (canonicalized exact match)
        if (is_string($configuredOrigin) && trim($configuredOrigin) !== '') {
            $normConfigured = self::normalizeOrigin($configuredOrigin);
            $normCandidate = self::normalizeOrigin($origin);

            if ($normConfigured !== null && $normCandidate !== null && hash_equals($normConfigured, $normCandidate)) {
                return true;
            }
        }

        // 2. Loopback development origins permitted only when APP_ENV === 'development'
        if ($appEnv === 'development') {
            return (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);
        }

        return false;
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

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            ob_clean();
        }

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
