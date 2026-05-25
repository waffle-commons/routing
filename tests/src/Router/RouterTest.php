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
use WaffleTests\Commons\Routing\Helper\Controller\CatchAllController;
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
        $this->container->set(CatchAllController::class, new CatchAllController());

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
        static::assertCount(13, $this->router->routes);

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

        // Multi-segment URL — every fixture route (including the single-segment
        // catch-all in CatchAllController) has fewer segments, so this must miss.
        $uriStub = $this->createStub(UriInterface::class);
        $uriStub->method('getPath')->willReturn('/no/match/here');

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

    public function testRoutesAreSortedByDescendingPriority(): void
    {
        $this->router->boot(container: $this->container);

        // Copy the route list into a local variable first — `$router->routes` is
        // backed by a property hook that rejects indirect modification (and end()
        // mutates the internal array pointer).
        $routes = $this->router->routes;

        // The catch-all route declared by CatchAllController (priority: -1000) MUST
        // sit at the tail of the compiled table — every other discovered route uses
        // the default priority of 0 and therefore sorts ahead of it.
        $priorities = array_map(static fn(MatchedRoute $r): int => $r->priority, $routes);
        $sorted = $priorities;
        rsort($sorted);
        static::assertSame($sorted, $priorities, 'routes must be ordered by descending priority');

        $last = end($routes);
        static::assertInstanceOf(MatchedRoute::class, $last);
        static::assertSame(-1000, $last->priority);
        static::assertSame('catchall_fallback', $last->name);
    }

    public function testCatchAllRouteMatchesOnlyWhenNoHigherPriorityRouteClaimsTheUri(): void
    {
        $this->router->boot(container: $this->container);

        // /users matches the priority-0 TempController::list — NOT the catch-all.
        $uriStub = $this->createStub(UriInterface::class);
        $uriStub->method('getPath')->willReturn('/users');
        $requestMock = $this->createStub(ServerRequestInterface::class);
        $requestMock->method('getUri')->willReturn($uriStub);

        $matched = $this->router->matchRequest($requestMock);
        static::assertNotNull($matched);
        static::assertSame('user_users_list', $matched->name);

        // /something-unmatched falls through every priority-0 route and lands on
        // the priority-(-1000) catch-all.
        $uriStub2 = $this->createStub(UriInterface::class);
        $uriStub2->method('getPath')->willReturn('/something-unmatched');
        $requestMock2 = $this->createStub(ServerRequestInterface::class);
        $requestMock2->method('getUri')->willReturn($uriStub2);

        $catchAll = $this->router->matchRequest($requestMock2);
        static::assertNotNull($catchAll);
        static::assertSame('catchall_fallback', $catchAll->name);
        static::assertSame(-1000, $catchAll->priority);
        static::assertSame(['anything' => 'something-unmatched'], $catchAll->params);
    }

    public function testCachedRoutePayloadPreservesPrioritySortOrder(): void
    {
        // First boot: discovery + sort + cache write.
        $cache = $this->makeStubCache();
        $router = new Router(directory: 'tests/src/Helper/Controller', cache: $cache);
        $router->boot(container: $this->container);

        $writtenPriorities = array_map(static fn(MatchedRoute $r): int => $r->priority, $router->routes);

        // Second boot: cache hit short-circuits discovery; verify the rehydrated
        // collection is byte-for-byte identical (i.e. sorting was preserved).
        $router2 = new Router(directory: __DIR__ . '/NonExistentDirectory', cache: $cache);
        $router2->boot(container: $this->container);

        $hydratedPriorities = array_map(static fn(MatchedRoute $r): int => $r->priority, $router2->routes);
        static::assertSame($writtenPriorities, $hydratedPriorities);
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

    public function testCatchAllRouteMatchesAcrossMultipleSegments(): void
    {
        // Beta-1 gateway requirement: a `/{path:.*}` fallback must match ANY
        // depth of path so unmatched traffic can be proxied to the legacy backend.
        $routes = [
            new MatchedRoute(
                className: TempController::class,
                method: 'health',
                arguments: [],
                path: '/api/health',
                name: 'api_health',
                priority: 10,
            ),
            new MatchedRoute(
                className: CatchAllController::class,
                method: 'proxy',
                arguments: [],
                path: '/{path:.*}',
                name: 'gateway_proxy',
                priority: -1000,
            ),
        ];
        $cache = $this->makeStubCache(seed: ['waffle.routes.discovered' => $routes]);
        $router = new Router(directory: __DIR__ . '/NonExistentDirectory', cache: $cache);
        $router->boot(container: $this->container);

        // The specific high-priority route still wins on its exact path.
        static::assertSame('api_health', $this->matchPath($router, '/api/health')?->name);

        // Everything else falls through to the multi-segment catch-all.
        $matched = $this->matchPath($router, '/legacy/orders/42/items');
        static::assertNotNull($matched);
        static::assertSame('gateway_proxy', $matched->name);
        static::assertSame(['path' => 'legacy/orders/42/items'], $matched->params);
    }

    public function testConstrainedParameterRejectsNonMatchingValues(): void
    {
        $routes = [
            new MatchedRoute(
                className: TempController::class,
                method: 'file',
                arguments: [],
                path: '/files/{id:\d+}',
                name: 'files_show',
            ),
        ];
        $cache = $this->makeStubCache(seed: ['waffle.routes.discovered' => $routes]);
        $router = new Router(directory: __DIR__ . '/NonExistentDirectory', cache: $cache);
        $router->boot(container: $this->container);

        $matched = $this->matchPath($router, '/files/123');
        static::assertNotNull($matched);
        static::assertSame(['id' => '123'], $matched->params);

        // A non-numeric id must not satisfy the `\d+` constraint.
        static::assertNull($this->matchPath($router, '/files/abc'));
    }

    public function testStaticSegmentDotsAreMatchedLiterally(): void
    {
        $routes = [
            new MatchedRoute(
                className: TempController::class,
                method: 'manifest',
                arguments: [],
                path: '/health.json',
                name: 'health_json',
            ),
        ];
        $cache = $this->makeStubCache(seed: ['waffle.routes.discovered' => $routes]);
        $router = new Router(directory: __DIR__ . '/NonExistentDirectory', cache: $cache);
        $router->boot(container: $this->container);

        static::assertSame('health_json', $this->matchPath($router, '/health.json')?->name);
        // The '.' must be a literal, not a regex wildcard.
        static::assertNull($this->matchPath($router, '/healthXjson'));
    }

    private function matchPath(Router $router, string $path): ?MatchedRoute
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        return $router->matchRequest($request);
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
