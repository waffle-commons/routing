<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Security\SecurityInterface;
use Waffle\Commons\Routing\Router;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Routing\Helper\Controller\TempController;
use WaffleTests\Commons\Routing\Helper\MockContainer;

#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    private Router $router;
    private array $serverBackup;
    private ContainerInterface $container;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;

        $security = $this->createStub(SecurityInterface::class);

        // Use MockContainer for testing
        $innerContainer = new MockContainer();
        $this->container = $innerContainer;
        $this->container->set(SecurityInterface::class, $security);
        $this->container->set(TempController::class, new TempController());

        $this->router = new Router(directory: 'tests/src/Helper/Controller');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    // ... (testRegisterRoutesDiscoversAndBuildsRoutes remains unchanged) ...
    public function testRegisterRoutesDiscoversAndBuildsRoutes(): void
    {
        // We need a container for boot
        $this->router->boot(container: $this->container);

        static::assertNotEmpty($this->router->routes);
        static::assertCount(11, $this->router->routes);

        $foundRoute = false;
        foreach ($this->router->routes as $route) {
            if ('user_users_list' !== $route['name']) {
                continue;
            }

            $foundRoute = true;
            static::assertSame(TempController::class, $route['classname']);
            static::assertSame('/users', $route['path']);
            static::assertSame('list', $route['method']);
            break;
        }
        static::assertTrue($foundRoute);
    }

    public function testMatchWithStaticRoute(): void
    {
        $this->router->boot(container: $this->container);

        // Use Mocks for Request/Uri
        $uriStub = $this->createStub(UriInterface::class);
        $uriStub->method('getPath')->willReturn('/users');

        $requestMock = $this->createStub(ServerRequestInterface::class);
        $requestMock->method('getUri')->willReturn($uriStub);

        $matchingRoute = $this->router->matchRequest($requestMock);

        static::assertNotNull($matchingRoute);
        static::assertSame('user_users_list', $matchingRoute['name']);
    }

    #[DataProvider('dynamicRouteProvider')]
    public function testMatchWithDynamicParameterRoutes(string $url, string $expectedRouteName): void
    {
        $this->router->boot(container: $this->container);

        $uriStub = $this->createStub(UriInterface::class);
        $uriStub->method('getPath')->willReturn($url);

        $requestMock = $this->createStub(ServerRequestInterface::class);
        $requestMock->method('getUri')->willReturn($uriStub);

        $matchingRoute = $this->router->matchRequest($requestMock);

        static::assertNotNull($matchingRoute);
        static::assertSame($expectedRouteName, $matchingRoute['name']);
    }

    public static function dynamicRouteProvider(): array
    {
        return [
            'Single integer parameter' => ['/users/123', 'user_users_show'],
            'Multiple parameters (int and string)' => ['/users/42/john-doe-slug', 'user_users_details'],
        ];
    }

    public function testNoMatchForNonExistentRoute(): void
    {
        $this->router->boot(container: $this->container);

        $uriStub = $this->createStub(UriInterface::class);
        $uriStub->method('getPath')->willReturn('/non-existent-route');

        $requestMock = $this->createStub(ServerRequestInterface::class);
        $requestMock->method('getUri')->willReturn($uriStub);

        $matchingRoute = $this->router->matchRequest($requestMock);

        static::assertNull($matchingRoute);
    }

    // ... (Rest of tests unchanged) ...
    public function testRouteCachingInProductionEnvironment(): void
    {
        putenv('APP_ENV=prod');
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'waffle_routes_cache.php';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        $this->router->boot(container: $this->container);
        static::assertFileExists($cacheFile);

        $cachedRoutes = require $cacheFile;
        static::assertNotEmpty($cachedRoutes);
        static::assertCount(11, $cachedRoutes);

        unlink($cacheFile);
        putenv('APP_ENV=test');
    }

    public function testBootLoadsRoutesFromCacheInProduction(): void
    {
        putenv('APP_ENV=prod');
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'waffle_routes_cache.php';
        $routes = $this->provideRoutesArray();
        $content = '<?php return ' . var_export($routes, true) . ';';
        file_put_contents($cacheFile, $content, LOCK_EX);

        $this->router->boot(container: $this->container);
        static::assertFileExists($cacheFile);

        $cachedRoutes = $this->router->routes;
        static::assertSame($routes, $cachedRoutes);

        unlink($cacheFile);
        putenv('APP_ENV=test');
    }

    public function testRouterHandlesNonExistentDirectoryGracefully(): void
    {
        $badRouter = new Router(directory: __DIR__ . '/NonExistentDirectory');

        $badRouter->boot(container: $this->container);

        static::assertEmpty($badRouter->routes);
    }

    private function provideRoutesArray(): array
    {
        return [
            [
                'classname' => 'WaffleTests\Commons\Routing\Helper\Controller\TempController',
                'method' => 'list',
                'arguments' => [],
                'path' => '/users',
                'name' => 'user_users_list',
            ],
            [
                'classname' => 'WaffleTests\Commons\Routing\Helper\Controller\TempController',
                'method' => 'show',
                'arguments' => [
                    'id' => 'int',
                ],
                'path' => '/users/{id}',
                'name' => 'user_users_show',
            ],
            [
                'classname' => 'WaffleTests\Commons\Routing\Helper\Controller\TempController',
                'method' => 'details',
                'arguments' => [
                    'id' => 'int',
                    'slug' => 'string',
                ],
                'path' => '/users/{id}/{slug}',
                'name' => 'user_users_details',
            ],
            [
                'classname' => 'WaffleTests\Commons\Routing\Helper\Controller\TempController',
                'method' => 'profile',
                'arguments' => [],
                'path' => '/users/profile/view',
                'name' => 'user_users_profile_view',
            ],
            [
                'classname' => 'WaffleTests\Commons\Routing\Helper\Controller\TempController',
                'method' => 'throwError',
                'arguments' => [],
                'path' => '/trigger-error',
                'name' => 'user_trigger_error',
            ],
        ];
    }
}
