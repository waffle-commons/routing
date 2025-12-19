<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Routing\RouteDiscoverer;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Routing\Helper\Controller\TempController;
use WaffleTests\Commons\Routing\Helper\MockContainer;

#[CoversClass(RouteDiscoverer::class)]
final class RouteDiscovererTest extends TestCase
{
    private null|string $tempDir = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/waffle_discoverer_' . uniqid();
        mkdir($this->tempDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testDiscoverReturnsEmptyArrayIfDirectoryIsFalse(): void
    {
        $discoverer = new RouteDiscoverer(directory: false);

        // Mock container not needed since discover checks files first
        $container = $this->createStub(\Waffle\Commons\Contracts\Container\ContainerInterface::class);

        static::assertEmpty($discoverer->discover($container));
    }

    public function testDiscoverReturnsEmptyArrayIfDirectoryIsEmpty(): void
    {
        $discoverer = new RouteDiscoverer(directory: $this->tempDir);
        $container = $this->createStub(\Waffle\Commons\Contracts\Container\ContainerInterface::class);

        static::assertEmpty($discoverer->discover($container));
    }

    public function testDiscoverFindsRoutes(): void
    {
        // Point to the Helper/Controller directory where TempController resides
        $directory = __DIR__ . '/../Helper/Controller';
        $discoverer = new RouteDiscoverer(directory: $directory);

        $container = new MockContainer();
        // Register TempController so RouteParser can instantiate it
        $container->set(TempController::class, new TempController());

        $routes = $discoverer->discover($container);

        static::assertNotEmpty($routes);
        // TempController has 5 routes.
        // DuplicateRouteController has 1 route (after filtering).
        // NoRouteController has 0 routes.
        // AbstractUninstantiable has 0 routes.
        // PathConcatenationController has 3 routes.
        // ComplexParametersController has 2 routes.
        // Total should be 11.
        static::assertCount(11, $routes);
    }
}
