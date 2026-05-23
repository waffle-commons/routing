<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Routing\RouteParser;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Routing\Helper\Controller\AbstractUninstantiable;
use WaffleTests\Commons\Routing\Helper\Controller\ComplexParametersController;
use WaffleTests\Commons\Routing\Helper\Controller\DuplicateRouteController;
use WaffleTests\Commons\Routing\Helper\Controller\NoRouteController;
use WaffleTests\Commons\Routing\Helper\Controller\PathConcatenationController;

#[CoversClass(RouteParser::class)]
final class RouteParserTest extends TestCase
{
    public function testParseReturnsEmptyIfClassDoesNotExist(): void
    {
        $parser = new RouteParser();

        $routes = $parser->parse('NonExistent\Class');

        static::assertEmpty($routes);
    }

    public function testParseReturnsEmptyIfClassIsAbstract(): void
    {
        $parser = new RouteParser();

        $routes = $parser->parse(AbstractUninstantiable::class);

        static::assertEmpty($routes);
    }

    public function testParseIgnoresDuplicateRoutes(): void
    {
        $parser = new RouteParser();
        $routes = $parser->parse(DuplicateRouteController::class);

        // Assert
        // DuplicateRouteController has 2 methods pointing to '/duplicate',
        // so only the first one should be registered.
        static::assertCount(1, $routes, 'Duplicate routes should be filtered out.');
        $first = $routes[0] ?? null;
        static::assertInstanceOf(MatchedRoute::class, $first);
        static::assertSame('default_first_method', $first->name);
    }

    public function testParseReturnsEmptyIfClassHasNoRouteAttribute(): void
    {
        $parser = new RouteParser();

        $routes = $parser->parse(NoRouteController::class);

        static::assertEmpty($routes);
    }

    public function testParseHandlesPathConcatenationCorrectly(): void
    {
        $parser = new RouteParser();

        $routes = $parser->parse(PathConcatenationController::class);

        static::assertCount(3, $routes);

        // array_column works on arrays of objects via public properties since PHP 7.0.
        $paths = array_column($routes, 'path', 'name');

        // Expected: /base + /absolute -> /base/absolute
        static::assertArrayHasKey('base_absolute', $paths);
        static::assertSame('/base/absolute', $paths['base_absolute']);

        // Expected: /base + relative -> /base/relative
        static::assertArrayHasKey('base_relative', $paths);
        static::assertSame('/base/relative', $paths['base_relative']);

        // Expected: /base + '' -> /base
        static::assertArrayHasKey('base_empty', $paths);
        static::assertSame('/base', $paths['base_empty']);
    }

    public function testParseHandlesComplexParameters(): void
    {
        $parser = new RouteParser();

        $routes = $parser->parse(ComplexParametersController::class);

        static::assertCount(2, $routes);
        $args = array_column($routes, 'arguments', 'name');

        // Untyped parameter should be skipped (or handled if logic changes)
        // Current logic: if not NamedType, skip.
        static::assertArrayHasKey('default_untyped', $args);
        static::assertEmpty($args['default_untyped']);

        // Union type parameter should be skipped
        static::assertArrayHasKey('default_union', $args);
        static::assertEmpty($args['default_union']);
    }

    public function testParseHandlesEmptyBasePathAndTypedParameter(): void
    {
        $parser = new RouteParser();

        $routes = $parser->parse(\WaffleTests\Commons\Routing\Helper\Controller\TypedRootController::class);

        // Only `show` carries a Route attribute; `notARoute` is intentionally skipped.
        static::assertCount(1, $routes);

        // Narrow $routes[0] for the analyzer; PHPUnit's assertCount above already proves presence at runtime.
        $route = $routes[0] ?? null;
        static::assertInstanceOf(MatchedRoute::class, $route);

        // Class-level Route('/') trims to empty basePath; method path 'items/{id}' triggers the '/' + methodPath branch.
        static::assertSame('/items/{id}', $route->path);
        // ReflectionNamedType extraction populates the typed-parameter map.
        static::assertSame(['id' => 'int'], $route->arguments);
        static::assertSame('root_item', $route->name);
    }
}
