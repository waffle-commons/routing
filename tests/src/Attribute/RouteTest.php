<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Attribute;

use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Routing\Attribute\Argument;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;

final class RouteTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        // --- Test Condition ---
        $path = '/test-path';
        $name = 'test_name';
        $arguments = [new Argument(classType: 'string', paramName: 'id')];

        // --- Execution ---
        $route = new Route($path, ['GET', 'POST'], $name, $arguments, 10);

        // --- Assertions ---
        static::assertSame(['GET', 'POST'], $route->methods);
        static::assertSame($path, $route->path);
        static::assertSame($name, $route->name);
        static::assertSame($arguments, $route->arguments);
        static::assertSame(10, $route->priority);
    }

    public function testConstructorWithDefaultParameters(): void
    {
        // --- Test Condition ---
        $path = '/another-path';

        // --- Execution ---
        $route = new Route(path: $path);

        // --- Assertions ---
        static::assertSame(['GET'], $route->methods);
        static::assertSame($path, $route->path);
        static::assertNull($route->name);
        static::assertNull($route->arguments);
        static::assertSame(0, $route->priority);
    }
}
