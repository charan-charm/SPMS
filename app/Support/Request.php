<?php

declare(strict_types=1);

namespace App\Support;

use App\Security\AuthContext;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly ?string $ipAddress = null,
        private readonly ?AuthContext $authContext = null
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $path = '/';
        if (isset($_SERVER['REQUEST_URI'])) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
            $path = '/' . ltrim($path, '/');
        }

        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
            $path = $path === '' ? '/' : $path;
        }

        $query = is_array($_GET) ? $_GET : [];

        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    // Malformed JSON is a client error, never silently
                    // treated as an empty body.
                    throw new AppException(
                        'VALIDATION_ERROR',
                        'Request body must be valid JSON.',
                        400
                    );
                }
                $body = $decoded;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : null;

        return new self($method, $path, $query, $body, $headers, $ipAddress);
    }

    public static function create(string $method, string $path, array $query = [], array $body = [], array $headers = [], ?string $ipAddress = null): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return new self(strtoupper($method), $path, $query, $body, $normalized, $ipAddress);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function queryParam(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    public function bodyParam(string $name): mixed
    {
        return $this->body[$name] ?? null;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function authContext(): ?AuthContext
    {
        return $this->authContext;
    }

    /** Middleware injects the resolved authentication context here. */
    public function withAuthContext(AuthContext $authContext): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->headers,
            $this->ipAddress,
            $authContext
        );
    }
}
