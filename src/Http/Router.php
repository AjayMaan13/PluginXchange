<?php

declare(strict_types=1);

namespace PluginXchange\Http;

use Closure;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function add(string $method, string $pattern, Closure $handler, string $access = Route::RESELLER): void
    {
        $this->routes[] = new Route($method, $pattern, $handler, $access);
    }

    /**
     * @return array{0: Route, 1: array<string, string>}
     */
    public function match(string $method, string $path): array
    {
        $pathExists = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route->regex, $path, $matches)) {
                continue;
            }
            $pathExists = true;
            if ($route->method === $method) {
                return [$route, array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }

        throw $pathExists
            ? new ApiException(405, 'METHOD_NOT_ALLOWED', "Method $method is not allowed here")
            : new ApiException(404, 'NOT_FOUND', 'Route not found');
    }
}
