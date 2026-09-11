<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Router
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private array $routes = [];

    /** Parameterized routes: pattern path (with {param} segments) => method => handler. */
    private array $patterns = [];

    public function add(string $method, string $path, array $handler): void
    {
        $method = strtoupper($method);

        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Unsupported HTTP method: ' . $method);
        }

        $path = $this->normalize($path);

        if ($this->isPattern($path)) {
            $this->patterns[$path][$method] = $handler;

            return;
        }

        $this->routes[$path][$method] = $handler;
    }

    public function dispatch(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path = $this->normalize($path);

        if (isset($this->routes[$path][$method])) {
            return $this->routes[$path][$method];
        }

        if (isset($this->routes[$path])) {
            throw new MethodNotAllowedException(array_keys($this->routes[$path]));
        }

        foreach ($this->patterns as $pattern => $handlers) {
            if (!$this->patternMatches($pattern, $path)) {
                continue;
            }

            if (isset($handlers[$method])) {
                return $handlers[$method];
            }

            throw new MethodNotAllowedException(array_keys($handlers));
        }

        throw new NotFoundException($path);
    }

    public function hasRoute(string $method, string $path): bool
    {
        $path = $this->normalize($path);

        if (isset($this->routes[$path][strtoupper($method)])) {
            return true;
        }

        foreach ($this->patterns as $pattern => $handlers) {
            if (isset($handlers[strtoupper($method)]) && $this->patternMatches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function isPattern(string $path): bool
    {
        return str_contains($path, '{');
    }

    /**
     * Exact-match routes always take precedence. A pattern matches when
     * every literal segment is equal and every {param} segment matches a
     * non-empty path segment. Parameters are read by the controller from
     * the request path — the dispatch contract stays [class, action].
     */
    private function patternMatches(string $pattern, string $path): bool
    {
        $patternSegments = explode('/', trim($pattern, '/'));
        $pathSegments = explode('/', trim($path, '/'));

        if (count($patternSegments) !== count($pathSegments)) {
            return false;
        }

        foreach ($patternSegments as $index => $patternSegment) {
            if (preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*\}$/', $patternSegment) === 1) {
                if ($pathSegments[$index] === '') {
                    return false;
                }

                continue;
            }

            if ($patternSegment !== $pathSegments[$index]) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path;
    }
}
