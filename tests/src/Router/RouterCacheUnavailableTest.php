<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Routing\Trie\RouteTrie;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Routing\Helper\CacheBackendDownException;
use WaffleTests\Commons\Routing\Helper\Controller\CatchAllController;
use WaffleTests\Commons\Routing\Helper\Controller\TempController;
use WaffleTests\Commons\Routing\Helper\MockContainer;

/**
 * RFC-013 §4 graceful degradation in {@see Router::boot()}: when the cache backend is
 * unreachable (Redis down while a worker boots or recycles), the router discovers its
 * routes instead of failing the boot, and keeps nothing it read before the outage.
 */
#[CoversClass(Router::class)]
final class RouterCacheUnavailableTest extends TestCase
{
    private MockContainer $container;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new MockContainer();
        $this->container->set(TempController::class, new TempController());
        $this->container->set(CatchAllController::class, new CatchAllController());
    }

    public function testBootDiscoversTheRoutesWhenEveryCacheCallFails(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        // The trie read fails first, so the route read is never attempted.
        $cache->expects(self::once())->method('get')->willThrowException(new CacheBackendDownException());
        $cache->expects(self::once())->method('set')->willThrowException(new CacheBackendDownException());

        $router = new Router(directory: 'tests/src/Helper/Controller', cache: $cache);
        $router->boot(container: $this->container);

        static::assertNotEmpty($router->routes);
        static::assertContainsOnlyInstancesOf(MatchedRoute::class, $router->routes);
        static::assertSame('user_users_list', $this->matchPath($router, 'GET', '/users')?->name);
    }

    public function testBootKeepsTheDiscoveredTableWhenOnlyTheWriteFails(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::exactly(2))->method('get')->willReturn(null);
        $cache->expects(self::once())->method('set')->willThrowException(new CacheBackendDownException());

        $router = new Router(directory: 'tests/src/Helper/Controller', cache: $cache);
        $router->boot(container: $this->container);

        static::assertSame('user_users_list', $this->matchPath($router, 'GET', '/users')?->name);
    }

    public function testBootDropsAPrebuiltTrieWhenTheRouteReadFails(): void
    {
        $artifact = RouteTrie::build([
            new MatchedRoute(
                className: TempController::class,
                method: 'list',
                arguments: [],
                path: '/ghost',
                name: 'ghost_list',
                methods: ['GET'],
            ),
        ])->toArray();

        $cache = $this->createMock(CacheInterface::class);
        // The trie artifact is read, then the backend goes away before the route read.
        $cache
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(static fn(string $key): array => $key === 'waffle.routes.trie'
                ? $artifact
                : throw new CacheBackendDownException());
        $cache->expects(self::once())->method('set')->willReturn(true);

        $router = new Router(directory: 'tests/src/Helper/Controller', cache: $cache);
        $router->boot(container: $this->container);

        // Nothing read before the outage is trusted: the trie is rebuilt from discovery,
        // where /ghost only reaches the catch-all (the stale artifact would say ghost_list).
        static::assertSame('catchall_fallback', $this->matchPath($router, 'GET', '/ghost')?->name);
        static::assertSame('user_users_list', $this->matchPath($router, 'GET', '/users')?->name);
    }

    private function matchPath(Router $router, string $method, string $path): ?MatchedRoute
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);

        return $router->matchRequest($request);
    }
}
