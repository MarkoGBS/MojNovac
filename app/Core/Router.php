<?php

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $pattern = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([0-9]+)', $path);
        $this->routes[] = [$method, '#^' . $pattern . '/?$#', $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = rtrim((string) ($GLOBALS['config']['base_url'] ?? ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod === $method && preg_match($pattern, $path, $matches)) {
                array_shift($matches);
                $handler(...array_map('intval', $matches));
                return;
            }
        }

        http_response_code(404);
        echo '404 - Stranica nije pronađena.';
    }
}
