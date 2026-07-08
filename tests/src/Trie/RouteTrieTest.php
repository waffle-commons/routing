<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Trie;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Waffle\Commons\Contracts\Routing\Exception\MethodNotAllowedException;
use Waffle\Commons\Contracts\Routing\Exception\MethodNotAllowedExceptionInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Routing\Trie\RouteTrie;
use Waffle\Commons\Routing\Trie\TrieNode;
use WaffleTests\Commons\Routing\AbstractTestCase as TestCase;

#[CoversClass(RouteTrie::class)]
#[CoversClass(TrieNode::class)]
final class RouteTrieTest extends TestCase
{
    /**
     * Mirror of {@see \Waffle\Commons\Routing\RouterTest}'s fixture set, but
     * exercised through the trie so the equivalence assertions below have a
     * sequential baseline to compare against.
     *
     * @return list<MatchedRoute>
     */
    private function fixtureRoutes(): array
    {
        return [
            new MatchedRoute(
                className: 'App\\Controller\\Home',
                method: 'index',
                arguments: [],
                path: '/',
                name: 'home',
                priority: 0,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'list',
                arguments: [],
                path: '/users',
                name: 'users_list',
                priority: 0,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'create',
                arguments: [],
                path: '/users',
                name: 'users_create',
                priority: 0,
                methods: ['POST'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'profile',
                arguments: [],
                path: '/users/profile/view',
                name: 'users_profile',
                priority: 10,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'show',
                arguments: ['id' => 'int'],
                path: '/users/{id}',
                name: 'users_show',
                priority: 0,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'details',
                arguments: ['id' => 'int', 'slug' => 'string'],
                path: '/users/{id}/{slug}',
                name: 'users_details',
                priority: 0,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\Files',
                method: 'show',
                arguments: ['id' => 'int'],
                path: '/files/{id:\d+}',
                name: 'files_show',
                priority: 0,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\CatchAll',
                method: 'proxy',
                arguments: [],
                path: '/{path:.*}',
                name: 'catchall',
                priority: -1000,
                methods: [],
            ),
        ];
    }

    /**
     * The fixtures, priority-sorted exactly as Router::boot() would order them.
     *
     * @return list<MatchedRoute>
     */
    private function sortedFixtures(): array
    {
        $routes = $this->fixtureRoutes();
        usort($routes, static fn(MatchedRoute $a, MatchedRoute $b): int => $b->priority <=> $a->priority);

        return $routes;
    }

    private function trie(): RouteTrie
    {
        return RouteTrie::build($this->sortedFixtures());
    }

    public function testStaticMatch(): void
    {
        $matched = $this->trie()->match('GET', '/users');

        static::assertNotNull($matched);
        static::assertSame('users_list', $matched->name);
        static::assertSame([], $matched->params);
    }

    public function testRootPathMatchesStaticRoute(): void
    {
        $matched = $this->trie()->match('GET', '/');

        static::assertNotNull($matched);
        static::assertSame('home', $matched->name);
    }

    public function testDynamicParamMatch(): void
    {
        $matched = $this->trie()->match('GET', '/users/42');

        static::assertNotNull($matched);
        static::assertSame('users_show', $matched->name);
        static::assertSame(['id' => '42'], $matched->params);
    }

    public function testMultipleDynamicParams(): void
    {
        $matched = $this->trie()->match('GET', '/users/42/john-doe');

        static::assertNotNull($matched);
        static::assertSame('users_details', $matched->name);
        static::assertSame(['id' => '42', 'slug' => 'john-doe'], $matched->params);
    }

    public function testStaticSegmentBeatsDynamicParam(): void
    {
        // `/users/profile/view` is fully static and must win over `/users/{id}`.
        $matched = $this->trie()->match('GET', '/users/profile/view');

        static::assertNotNull($matched);
        static::assertSame('users_profile', $matched->name);
        static::assertSame([], $matched->params);
    }

    public function testConstrainedParamRejectsNonMatchingValue(): void
    {
        $trie = $this->trie();

        $matched = $trie->match('GET', '/files/123');
        static::assertNotNull($matched);
        static::assertSame('files_show', $matched->name);
        static::assertSame(['id' => '123'], $matched->params);

        // `abc` violates the `\d+` constraint; it then falls through to the
        // catch-all (which spans any path) rather than 404'ing.
        $fallback = $trie->match('GET', '/files/abc');
        static::assertNotNull($fallback);
        static::assertSame('catchall', $fallback->name);
        static::assertSame(['path' => 'files/abc'], $fallback->params);
    }

    public function testCatchAllSpansMultipleSegments(): void
    {
        $matched = $this->trie()->match('GET', '/legacy/orders/42/items');

        static::assertNotNull($matched);
        static::assertSame('catchall', $matched->name);
        static::assertSame(['path' => 'legacy/orders/42/items'], $matched->params);
    }

    public function testMethodMismatchRaises405(): void
    {
        // Isolated GET+POST overload on /items (no catch-all to absorb the miss).
        $trie = RouteTrie::build([
            new MatchedRoute(
                className: 'App\\Controller\\X',
                method: 'read',
                arguments: [],
                path: '/items',
                name: 'items_read',
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\X',
                method: 'create',
                arguments: [],
                path: '/items',
                name: 'items_create',
                methods: ['POST'],
            ),
        ]);

        try {
            $trie->match('PUT', '/items');
            static::fail('Expected MethodNotAllowedException for PUT on /items.');
        } catch (MethodNotAllowedExceptionInterface $e) {
            // GET + POST declared; HEAD implied by GET; OPTIONS always advertised.
            static::assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], $e->getAllowedMethods());
            static::assertSame(405, $e->getCode());
            static::assertStringContainsString(
                'The requested HTTP method PUT is not allowed for this route.',
                $e->getMessage(),
            );
        }
    }

    public function testHeadRequestMatchesGetRoute(): void
    {
        $matched = $this->trie()->match('HEAD', '/users');

        static::assertNotNull($matched);
        static::assertSame('users_list', $matched->name);
    }

    public function testHeadOnPostOnlyRouteRaises405(): void
    {
        $trie = RouteTrie::build([
            new MatchedRoute(
                className: 'App\\Controller\\X',
                method: 'create',
                arguments: [],
                path: '/items',
                name: 'items_create',
                methods: ['POST'],
            ),
        ]);

        try {
            $trie->match('HEAD', '/items');
            static::fail('Expected MethodNotAllowedException for HEAD on POST-only route.');
        } catch (MethodNotAllowedExceptionInterface $e) {
            static::assertSame(['OPTIONS', 'POST'], $e->getAllowedMethods());
        }
    }

    public function testEmptyMethodDefaultsToGet(): void
    {
        $matched = $this->trie()->match('', '/users');

        static::assertNotNull($matched);
        static::assertSame('users_list', $matched->name);
    }

    public function testEmptyMethodMismatchReportsGetInMessage(): void
    {
        $trie = RouteTrie::build([
            new MatchedRoute(
                className: 'App\\Controller\\X',
                method: 'create',
                arguments: [],
                path: '/items',
                name: 'items_create',
                methods: ['POST'],
            ),
        ]);

        $this->expectException(MethodNotAllowedException::class);
        $this->expectExceptionMessage('The requested HTTP method GET is not allowed');
        $trie->match('', '/items');
    }

    public function testMethodSelectionPicksTheRightOverload(): void
    {
        $trie = $this->trie();

        $get = $trie->match('GET', '/users');
        static::assertNotNull($get);
        static::assertSame('users_list', $get->name);

        $post = $trie->match('post', '/users');
        static::assertNotNull($post);
        static::assertSame('users_create', $post->name);
    }

    public function testNotFoundReturnsNullWhenNoCatchAll(): void
    {
        $trie = RouteTrie::build([
            new MatchedRoute(
                className: 'App\\Controller\\X',
                method: 'list',
                arguments: [],
                path: '/users',
                name: 'users_list',
                methods: ['GET'],
            ),
        ]);

        static::assertNull($trie->match('GET', '/missing/deep/path'));
    }

    public function testEmptyMethodlessRouteAcceptsAnyMethod(): void
    {
        $trie = RouteTrie::build([
            new MatchedRoute(
                className: 'App\\Controller\\X',
                method: 'any',
                arguments: [],
                path: '/any',
                name: 'any_route',
                methods: [],
            ),
        ]);

        $matched = $trie->match('DELETE', '/any');
        static::assertNotNull($matched);
        static::assertSame('any_route', $matched->name);
    }

    public function testRootCatchAllMatchesRootPath(): void
    {
        // A root-mounted catch-all must match the root path `/` (path captures the
        // empty string), exactly as the sequential matcher's `#^/(?P<path>.*)$#`
        // does — splitSegments('/') is [], so the catch-all is reached on the
        // exhausted-path branch (AOT-03).
        $routes = $this->rootCatchAllFixtures();

        $trieMatch = $this->safeMatch(RouteTrie::build($routes), 'GET', '/');
        $sequentialMatch = $this->sequentialMatchWith($routes, 'GET', '/');

        static::assertSame(['catchall', ['path' => '']], $trieMatch);
        static::assertSame($sequentialMatch, $trieMatch, 'root catch-all must resolve `/` like the sequential router');
    }

    /**
     * AOT-02 priority parity: a dynamic route declared with a HIGHER priority than
     * a competing static route at the same depth must win in the trie exactly as
     * it does in the sequential matcher — the trie must NOT hardcode
     * static-beats-dynamic and ignore priority.
     */
    public function testHigherPriorityDynamicBeatsLowerPriorityStatic(): void
    {
        $routes = $this->priorityFixtures();

        foreach ([['GET', '/users/me'], ['GET', '/users/42']] as [$method, $path]) {
            $trieMatch = $this->safeMatch(RouteTrie::build($routes), $method, $path);
            $sequentialMatch = $this->sequentialMatchWith($routes, $method, $path);
            static::assertSame(
                $sequentialMatch,
                $trieMatch,
                sprintf('priority parity diverged for %s %s', $method, $path),
            );
        }

        // Spell the expectation out: /users/me hits the higher-priority dynamic
        // {handle} route, NOT the static `me` route.
        static::assertSame(
            ['user_by_handle', ['handle' => 'me']],
            $this->safeMatch(RouteTrie::build($routes), 'GET', '/users/me'),
        );
    }

    /**
     * Routes where a dynamic single-segment param outranks a static sibling.
     * `users_me` is fully static yet declared with a LOWER priority than the
     * dynamic `{handle}` route, so first-in-priority-order makes the dynamic win.
     *
     * @return list<MatchedRoute>
     */
    private function priorityFixtures(): array
    {
        $routes = [
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'me',
                arguments: [],
                path: '/users/me',
                name: 'users_me',
                priority: 0,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\User',
                method: 'byHandle',
                arguments: ['handle' => 'string'],
                path: '/users/{handle}',
                name: 'user_by_handle',
                priority: 100,
                methods: ['GET'],
            ),
        ];
        usort($routes, static fn(MatchedRoute $a, MatchedRoute $b): int => $b->priority <=> $a->priority);

        return $routes;
    }

    /**
     * @return list<MatchedRoute>
     */
    private function rootCatchAllFixtures(): array
    {
        $routes = [
            new MatchedRoute(
                className: 'App\\Controller\\Home',
                method: 'index',
                arguments: [],
                path: '/dashboard',
                name: 'dashboard',
                priority: 0,
                methods: ['GET'],
            ),
            new MatchedRoute(
                className: 'App\\Controller\\CatchAll',
                method: 'proxy',
                arguments: [],
                path: '/{path:.*}',
                name: 'catchall',
                priority: -1000,
                methods: [],
            ),
        ];
        usort($routes, static fn(MatchedRoute $a, MatchedRoute $b): int => $b->priority <=> $a->priority);

        return $routes;
    }

    /**
     * Runs a probe through the sequential Router seeded with the given routes,
     * returning the same comparable shape as {@see self::safeMatch()}.
     *
     * @param list<MatchedRoute> $routes
     */
    private function sequentialMatchWith(array $routes, string $method, string $path): mixed
    {
        $router = new Router(directory: false);
        $router->routes = $routes;

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);

        try {
            $matched = $router->matchRequest($request);
        } catch (MethodNotAllowedExceptionInterface $e) {
            return '405:' . implode(',', $e->getAllowedMethods());
        }

        return $matched === null ? null : [$matched->name, $matched->params];
    }

    public function testSerializeRoundTripPreservesResolution(): void
    {
        $original = $this->trie();
        $rehydrated = RouteTrie::fromArray($original->toArray());

        foreach ($this->equivalenceProbes() as [$method, $path]) {
            $a = $this->safeMatch($original, $method, $path);
            $b = $this->safeMatch($rehydrated, $method, $path);
            static::assertSame($a, $b, sprintf('Round-trip diverged for %s %s', $method, $path));
        }
    }

    /**
     * A stale or hand-rolled artifact must not smuggle malformed entries into the
     * matcher: {@see TrieNode::fromArray()} drops any route entry that is not a
     * `{order: int, route: MatchedRoute}` pair, any non-array static child, and
     * an array-less node — leaving an empty trie that resolves to null.
     */
    public function testFromArrayDropsMalformedEntries(): void
    {
        $valid = new MatchedRoute(
            className: 'App\\Controller\\X',
            method: 'ok',
            arguments: [],
            path: '/ok',
            name: 'ok_route',
            methods: ['GET'],
        );

        $rehydrated = RouteTrie::fromArray([
            'routes' => [
                'not-an-array', // dropped: not an array entry
                ['order' => 'nope', 'route' => $valid], // dropped: order is not an int
                ['order' => 0, 'route' => 'not-a-route'], // dropped: route is not a MatchedRoute
            ],
            'static' => [
                'broken' => 'not-an-array', // dropped: child is not an array
                'ok' => ['routes' => [['order' => 0, 'route' => $valid]]],
            ],
            'dynamic' => null,
            'catchall' => null,
        ]);

        // Every root route entry was malformed → no root match.
        static::assertNull($rehydrated->match('GET', '/'));
        // The malformed static child was dropped; the well-formed one survives.
        static::assertSame('ok_route', $rehydrated->match('GET', '/ok')?->name);
    }

    /**
     * The trie MUST return the same resolution as the sequential Router matcher
     * for every probe — this is the AOT-02 equivalence gate.
     */
    #[DataProvider('equivalenceProbeProvider')]
    public function testTrieMatchesSequentialRouter(string $method, string $path): void
    {
        $trieResult = $this->safeMatch($this->trie(), $method, $path);
        $routerResult = $this->sequentialMatch($method, $path);

        static::assertSame($routerResult, $trieResult, sprintf('Mismatch for %s %s', $method, $path));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function equivalenceProbeProvider(): array
    {
        return self::probes();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function equivalenceProbes(): array
    {
        return self::probes();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function probes(): array
    {
        return [
            ['GET',  '/'],
            ['GET',  '/users'],
            ['POST', '/users'],
            ['GET',  '/users/42'],
            ['GET',  '/users/42/john-doe'],
            ['GET',  '/users/profile/view'],
            ['GET',  '/files/123'],
            ['GET',  '/files/abc'],
            ['GET',  '/legacy/orders/42/items'],
            ['HEAD', '/users'],
        ];
    }

    /**
     * Runs the probe through {@see RouteTrie}, returning a comparable shape:
     * the route name + params, the literal `405:<methods>`, or null.
     */
    private function safeMatch(RouteTrie $trie, string $method, string $path): mixed
    {
        try {
            $matched = $trie->match($method, $path);
        } catch (MethodNotAllowedExceptionInterface $e) {
            return '405:' . implode(',', $e->getAllowedMethods());
        }

        return $matched === null ? null : [$matched->name, $matched->params];
    }

    /**
     * Runs the same probe through the production sequential Router (routes seeded
     * directly so no trie is built — exercises the fallback path) and returns the
     * same comparable shape.
     */
    private function sequentialMatch(string $method, string $path): mixed
    {
        $router = new Router(directory: false);
        $router->routes = $this->sortedFixtures();

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);

        try {
            $matched = $router->matchRequest($request);
        } catch (MethodNotAllowedExceptionInterface $e) {
            return '405:' . implode(',', $e->getAllowedMethods());
        }

        return $matched === null ? null : [$matched->name, $matched->params];
    }
}
