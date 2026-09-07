<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Router
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private array $routes = [];

    public function add(string $method, string $path, array $handler): void
    {
        $method = strtoupper($method);

        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Unsupported HTTP method: ' . $method);
        }

        $path = $this->normalize($path);
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

        throw new NotFoundException($path);
    }

    public function hasRoute(string $method, string $path): bool
    {
        $path = $this->normalize($path);
        return isset($this->routes[$path][strtoupper($method)]);
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path;
    }
}
