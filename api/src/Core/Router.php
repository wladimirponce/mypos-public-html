<?php

declare(strict_types=1);

namespace Mypos\Core;

final class Router
{
    /**
     * @var array<string, array<string, callable>>
     */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $normalizedPath = rtrim($path, '/') ?: '/';
        $normalizedMethod = strtoupper($method);

        $handler = $this->routes[$normalizedMethod][$normalizedPath] ?? null;

        if ($handler !== null) {
            $handler();
        }

        foreach ($this->routes[$normalizedMethod] ?? [] as $route => $routeHandler) {
            $parameterNames = [];
            $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $matches) use (&$parameterNames): string {
                $parameterNames[] = $matches[1];

                return '([^/]+)';
            }, $route);

            if ($pattern === null || $pattern === $route) {
                continue;
            }

            if (preg_match('#^' . $pattern . '$#', $normalizedPath, $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $params = [];

            foreach ($parameterNames as $index => $name) {
                $params[$name] = $matches[$index] ?? null;
            }

            $routeHandler($params);
        }

        Response::error('Ruta no encontrada', null, 404);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $normalizedPath = rtrim($path, '/') ?: '/';
        $this->routes[strtoupper($method)][$normalizedPath] = $handler;
    }
}
