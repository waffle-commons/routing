<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Routing\Attribute\Route;

final readonly class RouteParser
{
    /**
     * @param class-string $controllerClass
     * @return list<MatchedRoute>
     */
    public function parse(string $controllerClass): array
    {
        if (!class_exists($controllerClass)) {
            return [];
        }

        // 1. Analyze class via Reflection FIRST to avoid unnecessary/unsafe instantiation.
        $reflection = new ReflectionClass($controllerClass);

        // Ignore abstracts and interfaces
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return [];
        }

        // Check if the class has the #[Route] attribute.
        // If not, it's not a controller we care about.
        $attributes = $reflection->getAttributes(Route::class);
        if ($attributes === []) {
            return [];
        }

        // 2. Instantiate the Route attribute directly from reflection
        $classRoute = $attributes[0]->newInstance();

        $routes = [];
        foreach ($reflection->getMethods() as $method) {
            $newRoute = $this->createRoute($controllerClass, $classRoute, $method, $routes);
            if ($newRoute !== null) {
                $routes[] = $newRoute;
            }
        }

        return $routes;
    }

    /**
     * @param class-string       $file
     * @param list<MatchedRoute> $routes
     */
    private function createRoute(
        string $file,
        Route $classRoute,
        ReflectionMethod $method,
        array $routes,
    ): ?MatchedRoute {
        $attributes = $method->getAttributes(Route::class);
        if ($attributes === []) {
            return null;
        }

        $route = $attributes[0]->newInstance();

        // --- Improved Path Concatenation ---
        $slash = '/';
        $basePath = rtrim($classRoute->path, $slash); // Examples: '' (for '/'), '/admin'
        $methodPath = ltrim($route->path, $slash); // Examples: '', 'users', 'users/{id}'

        if ($methodPath === '') {
            $path = $basePath === '' ? '/' : $basePath;
        } elseif ($basePath === '') {
            $path = '/' . $methodPath;
        } else {
            $path = $basePath . '/' . $methodPath;
        }
        // --- End Improved Path Concatenation ---

        if ($this->isRouteRegistered($path, $routes)) {
            return null;
        }

        return new MatchedRoute(
            className: $file,
            method: $method->getName(),
            arguments: $this->extractParameters($method),
            path: $path,
            name: ($classRoute->name ?? 'default') . '_' . ($route->name ?? 'default'),
        );
    }

    /**
     * @return array<string, mixed|string|null>
     */
    private function extractParameters(ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            if (!$param->getType() instanceof ReflectionNamedType) {
                continue;
            }

            $paramType = $param->getType();
            $params[$param->getName()] = $paramType?->getName();
        }
        return $params;
    }

    /**
     * @param list<MatchedRoute> $routes
     */
    private function isRouteRegistered(string $path, array $routes): bool
    {
        foreach ($routes as $route) {
            if ($route->path === $path) {
                return true;
            }
        }
        return false;
    }
}
