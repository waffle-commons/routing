<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Attribute;

use Waffle\Commons\Routing\Attribute\Argument;
use Waffle\Commons\Routing\Attribute\Route;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;

final class RouteTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        // --- Test Condition ---
        $path = '/test-path';
        $name = 'test_name';
        $arguments = [new Argument(
            classType: 'string',
            paramName: 'id',
        )];

        // --- Execution ---
        $route = new Route($path, $name, $arguments);

        // --- Assertions ---
        static::assertSame($path, $route->path);
        static::assertSame($name, $route->name);
        static::assertSame($arguments, $route->arguments);
    }

    public function testConstructorWithDefaultParameters(): void
    {
        // --- Test Condition ---
        $path = '/another-path';

        // --- Execution ---
        $route = new Route($path);

        // --- Assertions ---
        static::assertSame($path, $route->path);
        // Assert that the optional parameters are null by default.
        static::assertNull($route->name);
        static::assertNull($route->arguments);
    }
}
