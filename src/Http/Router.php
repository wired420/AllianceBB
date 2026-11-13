<?php

declare(strict_types=1);

namespace AllianceBB\Http;

/**
 * Small regex router. Patterns use {name} for a path segment and {name:regex}
 * for a custom match. Plugins add routes through the "routes" action.
 */
final class Router
{
    /** @var array<string, array{methods: list<string>, pattern: string, regex: string, handler: callable}> */
    private array $routes = [];

    /** @param list<string>|string $methods */
    public function add(array|string $methods, string $pattern, callable $handler, ?string $name = null): void
    {
        $methods = array_map('strtoupper', (array) $methods);
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            fn (array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern,
        );
        $route = ['methods' => $methods, 'pattern' => $pattern, 'regex' => '#^' . $regex . '$#u', 'handler' => $handler];
        if ($name !== null) {
            $this->routes[$name] = $route;
        } else {
            $this->routes[] = $route;
        }
    }

    public function get(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler, $name);
    }

    public function post(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $name);
    }

    /** @return array{0: callable, 1: array<string, string>} */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if (!in_array($method, $route['methods'], true)) {
                $allowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return [$route['handler'], array_map('strval', $params)];
        }
        throw new HttpException($allowed ? 405 : 404);
    }

    /** @param array<string, string|int> $params */
    public function url(string $name, array $params = []): string
    {
        $pattern = $this->routes[$name]['pattern'] ?? throw new \InvalidArgumentException("No route named $name");
        return (string) preg_replace_callback(
            '/\{(\w+)(?::[^}]+)?\}/',
            fn (array $m) => rawurlencode((string) ($params[$m[1]] ?? '')),
            $pattern,
        );
    }
}
