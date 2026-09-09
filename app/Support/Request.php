<?php

declare(strict_types=1);

namespace App\Support;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers
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
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        return new self($method, $path, $query, $body, $headers);
    }

    public static function create(string $method, string $path, array $query = [], array $body = [], array $headers = []): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return new self(strtoupper($method), $path, $query, $body, $normalized);
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
}
