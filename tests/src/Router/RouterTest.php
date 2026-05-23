<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Router;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
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

    public function testRegisterRoutesDiscoversAndBuildsRoutes(): void
    {
        // We need a container for boot
        $this->router->boot(container: $this->container);

        static::assertNotEmpty($this->router->routes);
        static::assertCount(12, $this->router->routes);

        $foundRoute = false;
        foreach ($this->router->routes as $route) {
            if ('user_users_list' !== $route->name) {
                continue;
            }

            $foundRoute = true;
            static::assertSame(TempController::class, $route->className);
            static::assertSame('/users', $route->path);
            static::assertSame('list', $route->method);
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
        static::assertSame('user_users_list', $matchingRoute->name);
        static::assertSame([], $matchingRoute->params, 'static routes hydrate with empty params');
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
        static::assertSame($expectedRouteName, $matchingRoute->name);
    }

    public static function dynamicRouteProvider(): array
    {
        return [
            'Single integer parameter' => ['/users/123', 'user_users_show'],
            'Multiple parameters (int and string)' => ['/users/42/john-doe-slug', 'user_users_details'],
        ];
    }

    public function testMatchHydratesParamsFromUri(): void
    {
        $this->router->boot(container: $this->container);

        $uriStub = $this->createStub(UriInterface::class);
        $uriStub->method('getPath')->willReturn('/users/42/john-doe-slug');

        $requestMock = $this->createStub(ServerRequestInterface::class);
        $requestMock->method('getUri')->willReturn($uriStub);

        $matched = $this->router->matchRequest($requestMock);

        static::assertNotNull($matched);
        static::assertSame(['id' => '42', 'slug' => 'john-doe-slug'], $matched->params);
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

    public function testBootDiscoversAndPersistsRoutesIntoCache(): void
    {
        $cache = $this->makeStubCache();
        $router = new Router(directory: 'tests/src/Helper/Controller', cache: $cache);

        $router->boot(container: $this->container);

        static::assertNotEmpty($router->routes);
        // Boot must have written the discovered table through the PSR-16 set() path.
        static::assertTrue($cache->has('waffle.routes.discovered'));
        static::assertSame($router->routes, $cache->get('waffle.routes.discovered'));
    }

    public function testBootHydratesFromCacheAndSkipsDiscovery(): void
    {
        $expected = $this->provideRoutesList();
        $cache = $this->makeStubCache(seed: ['waffle.routes.discovered' => $expected]);

        // Directory points nowhere — proves discovery is not run when the cache is warm.
        $router = new Router(directory: __DIR__ . '/NonExistentDirectory', cache: $cache);
        $router->boot(container: $this->container);

        static::assertSame($expected, $router->routes);
    }

    public function testBootRejectsLegacyArrayShapedCachePayloadAndRediscovers(): void
    {
        // A pre-DTO cache entry must NOT be used as-is — the router falls back to discovery.
        $legacy = [
            ['classname' => 'X', 'method' => 'y', 'arguments' => [], 'path' => '/x', 'name' => 'x'],
        ];
        $cache = $this->makeStubCache(seed: ['waffle.routes.discovered' => $legacy]);

        $router = new Router(directory: 'tests/src/Helper/Controller', cache: $cache);
        $router->boot(container: $this->container);

        static::assertNotEmpty($router->routes);
        static::assertContainsOnlyInstancesOf(MatchedRoute::class, $router->routes);
    }

    public function testRouterHandlesNonExistentDirectoryGracefully(): void
    {
        $badRouter = new Router(directory: __DIR__ . '/NonExistentDirectory');

        $badRouter->boot(container: $this->container);

        static::assertEmpty($badRouter->routes);
    }

    public function testGetRoutesReturnsTheDiscoveredRouteCollection(): void
    {
        $this->router->boot(container: $this->container);

        $routes = $this->router->getRoutes();

        static::assertNotEmpty($routes);
        // Public getter must return the same collection as the asymmetric-visibility property.
        static::assertSame($this->router->routes, $routes);
        static::assertContainsOnlyInstancesOf(MatchedRoute::class, $routes);
    }

    /**
     * @param array<string, mixed> $seed
     */
    private function makeStubCache(array $seed = []): CacheInterface
    {
        return new class($seed) implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store;

            /** @param array<string, mixed> $seed */
            public function __construct(array $seed)
            {
                $this->store = $seed;
            }

            #[\Override]
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            #[\Override]
            public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            #[\Override]
            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            #[\Override]
            public function clear(): bool
            {
                $this->store = [];
                return true;
            }

            #[\Override]
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $out = [];
                foreach ($keys as $k) {
                    $out[$k] = $this->store[$k] ?? $default;
                }
                return $out;
            }

            #[\Override]
            public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
            {
                foreach ($values as $k => $v) {
                    $this->store[(string) $k] = $v;
                }
                return true;
            }

            #[\Override]
            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $k) {
                    unset($this->store[$k]);
                }
                return true;
            }

            #[\Override]
            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }
        };
    }

    /**
     * @return list<MatchedRoute>
     */
    private function provideRoutesList(): array
    {
        return [
            new MatchedRoute(
                className: 'WaffleTests\\Commons\\Routing\\Helper\\Controller\\TempController',
                method: 'list',
                arguments: [],
                path: '/users',
                name: 'user_users_list',
            ),
            new MatchedRoute(
                className: 'WaffleTests\\Commons\\Routing\\Helper\\Controller\\TempController',
                method: 'show',
                arguments: ['id' => 'int'],
                path: '/users/{id}',
                name: 'user_users_show',
            ),
            new MatchedRoute(
                className: 'WaffleTests\\Commons\\Routing\\Helper\\Controller\\TempController',
                method: 'details',
                arguments: ['id' => 'int', 'slug' => 'string'],
                path: '/users/{id}/{slug}',
                name: 'user_users_details',
            ),
            new MatchedRoute(
                className: 'WaffleTests\\Commons\\Routing\\Helper\\Controller\\TempController',
                method: 'profile',
                arguments: [],
                path: '/users/profile/view',
                name: 'user_users_profile_view',
            ),
            new MatchedRoute(
                className: 'WaffleTests\\Commons\\Routing\\Helper\\Controller\\TempController',
                method: 'throwError',
                arguments: [],
                path: '/trigger-error',
                name: 'user_trigger_error',
            ),
        ];
    }
}
