<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Router;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Routing\Trie\RouteTrie;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Routing\Helper\Controller\CatchAllController;
use WaffleTests\Commons\Routing\Helper\Controller\TempController;
use WaffleTests\Commons\Routing\Helper\MockContainer;

/**
 * Covers the AOT-02 trie wiring inside {@see Router}: a trie is built at boot,
 * a prebuilt trie artifact is preferred from cache, and a route-only cache entry
 * still yields a trie. The matching itself is identical to the sequential path
 * (proven exhaustively in {@see \WaffleTests\Commons\Routing\Trie\RouteTrieTest}).
 */
#[CoversClass(Router::class)]
final class RouterTrieIntegrationTest extends TestCase
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

    public function testBootBuildsTrieFromDiscovery(): void
    {
        $router = new Router(directory: 'tests/src/Helper/Controller');
        $router->boot(container: $this->container);

        static::assertInstanceOf(RouteTrie::class, $this->trieOf($router));
        // Matching through the trie resolves a known fixture route.
        static::assertSame('user_users_list', $this->matchPath($router, 'GET', '/users')?->name);
    }

    public function testBootPrefersPrebuiltTrieArtifactFromCache(): void
    {
        $routes = [
            new MatchedRoute(
                className: TempController::class,
                method: 'list',
                arguments: [],
                path: '/widgets',
                name: 'widgets_list',
                methods: ['GET'],
            ),
        ];
        $trieArtifact = RouteTrie::build($routes)->toArray();

        // Directory points nowhere — proves the prebuilt trie is used verbatim,
        // not rebuilt from discovery.
        $cache = $this->makeStubCache(seed: ['waffle.routes.trie' => $trieArtifact]);
        $router = new Router(directory: __DIR__ . '/NonExistentDirectory', cache: $cache);
        $router->boot(container: $this->container);

        static::assertSame('widgets_list', $this->matchPath($router, 'GET', '/widgets')?->name);
    }

    public function testBootBuildsTrieFromRouteOnlyCacheEntry(): void
    {
        $routes = [
            new MatchedRoute(
                className: TempController::class,
                method: 'list',
                arguments: [],
                path: '/gadgets',
                name: 'gadgets_list',
                methods: ['GET'],
            ),
        ];
        // Only the legacy route list is cached (no trie artifact) — boot must
        // synthesise the trie so matching stays O(depth).
        $cache = $this->makeStubCache(seed: ['waffle.routes.discovered' => $routes]);
        $router = new Router(directory: __DIR__ . '/NonExistentDirectory', cache: $cache);
        $router->boot(container: $this->container);

        static::assertInstanceOf(RouteTrie::class, $this->trieOf($router));
        static::assertSame('gadgets_list', $this->matchPath($router, 'GET', '/gadgets')?->name);
    }

    public function testRoutesSeededDirectlyUseSequentialFallback(): void
    {
        // No boot pass → trie stays null → matchRequest() runs the sequential loop.
        $router = new Router(directory: false);
        $router->routes = [
            new MatchedRoute(
                className: TempController::class,
                method: 'list',
                arguments: [],
                path: '/direct',
                name: 'direct_list',
                methods: ['GET'],
            ),
        ];

        static::assertNull($this->trieOf($router));
        static::assertSame('direct_list', $this->matchPath($router, 'GET', '/direct')?->name);
    }

    private function trieOf(Router $router): ?RouteTrie
    {
        $prop = new \ReflectionProperty(Router::class, 'trie');
        $value = $prop->getValue($router);

        return $value instanceof RouteTrie ? $value : null;
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
            public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
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
            public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
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
}
