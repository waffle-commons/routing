<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\MatchedRoute;

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
    /**
     * Creates a matching route from the reflection of a controller method.
     *
     * @param class-string $file
     * @param Route $classRoute
     * @param ReflectionMethod $method
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

        // Extract the allowed HTTP methods for this route
        $methods = $route->methods;

        // Validate duplicates, taking into account overloaded HTTP methods
        if ($this->isRouteRegistered($path, $methods, $routes)) {
            return null;
        }

        // Method-level priority always wins. The class-level value is the default for
        // methods that don't override it (consistent with how `path` concatenation
        // already inherits from the class-level Route). Defaults to 0 when neither
        // declares it explicitly.
        $priority = $route->priority !== 0 ? $route->priority : $classRoute->priority;

        return new MatchedRoute(
            className: $file,
            method: $method->getName(),
            arguments: $this->extractParameters($method),
            path: $path,
            name: ($classRoute->name ?? 'default') . '_' . ($route->name ?? 'default'),
            priority: $priority,
            methods: $methods,
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
     * Checks if a route with the same path and overlapping HTTP methods is already registered.
     *
     * @param string $path The route path.
     * @param array<string> $methods HTTP methods of the route currently being parsed.
     * @param list<MatchedRoute> $routes List of already registered routes.
     */
    private function isRouteRegistered(string $path, array $methods, array $routes): bool
    {
        foreach ($routes as $route) {
            if ($route->path !== $path) {
                continue;
            }

            // If either accepts all methods (empty array), it is a duplicate conflict
            if ($route->methods === [] || $methods === []) {
                return true;
            }

            // If there is an intersection between allowed methods, it is a duplicate
            $intersect = array_intersect(array_map('strtoupper', $route->methods), array_map('strtoupper', $methods));

            if ($intersect !== []) {
                return true;
            }
        }
        return false;
    }
}
