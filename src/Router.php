<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use Waffle\Commons\Routing\Trait\RequestTrait;

final class Router implements RouterInterface
{
    use RequestTrait;

    private const string CACHE_KEY = 'waffle.routes.discovered';

    private(set) string|false $directory {
        set => $this->directory = $value;
    }

    /**
     * @var array<array-key, string>|false
     */
    private(set) array|false $files {
        set => $this->files = $value;
    }

    /**
     * @var list<MatchedRoute>
     */
    public array $routes {
        set => $this->routes = $value;
    }

    private readonly RouteDiscoverer $discoverer;

    public function __construct(
        string|false $directory,
        private readonly ?CacheInterface $cache = null,
    ) {
        $this->routes = [];
        $this->files = false;

        $this->discoverer = new RouteDiscoverer(directory: $directory);
    }

    #[\Override]
    public function boot(ContainerInterface $container): static
    {
        if ($this->cache !== null) {
            $cachedRoutes = $this->cache->get(self::CACHE_KEY);
            if (is_array($cachedRoutes) && $this->isMatchedRouteList($cachedRoutes)) {
                // The cached payload is already sorted by priority at write time —
                // hydrate it verbatim instead of paying for a redundant sort.
                $this->routes = $cachedRoutes;

                return $this;
            }
        }

        $discovered = $this->discoverer->discover($container);
        // Catch-all routes (priority: -1000, etc.) sit at the tail; high-priority
        // routes are evaluated first. usort() is stable enough on PHP 8.5 to keep
        // declaration order within a single priority bucket.
        usort($discovered, static fn(MatchedRoute $a, MatchedRoute $b): int => $b->priority <=> $a->priority);
        $this->routes = $discovered;
        $this->cache?->set(self::CACHE_KEY, $this->routes);

        return $this;
    }

    #[\Override]
    public function matchRequest(ServerRequestInterface $request): ?MatchedRoute
    {
        foreach ($this->routes as $route) {
            $params = $this->match($request, $route);
            if ($params !== false) {
                return $route->withParams($params);
            }
        }

        return null;
    }

    /**
     * Internal match logic.
     *
     * @return array<string, mixed>|false Returns params array if matched, false otherwise.
     */
    private function match(ServerRequestInterface $req, MatchedRoute $route): array|false
    {
        // PSR-7 URI Handling
        $uriPath = $req->getUri()->getPath();
        $pathSegments = $this->getPathUri($route->path);
        $urlSegments = $this->getPathUri($uriPath);

        if (count($pathSegments) !== count($urlSegments)) {
            return false;
        }

        $params = [];

        foreach ($pathSegments as $i => $pathSegment) {
            if (str_starts_with($pathSegment, '{') && str_ends_with($pathSegment, '}')) {
                // This is a dynamic parameter
                $paramName = trim($pathSegment, '{}');
                $params[$paramName] = $urlSegments[$i];
                continue;
            }

            if ($pathSegment !== $urlSegments[$i]) {
                return false;
            }
        }

        return $params;
    }

    /**
     * @return list<MatchedRoute>
     */
    #[\Override]
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Narrows a freshly-thawed cache payload to the strict `list<MatchedRoute>` shape
     * the property requires. Stale or hand-rolled cache entries (e.g., legacy arrays
     * surviving a deploy) are rejected so the router re-discovers from source.
     *
     * @param array<array-key, mixed> $candidate
     * @phpstan-assert-if-true list<MatchedRoute> $candidate
     */
    private function isMatchedRouteList(array $candidate): bool
    {
        foreach ($candidate as $entry) {
            if (!$entry instanceof MatchedRoute) {
                return false;
            }
        }
        return true;
    }
}
