<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Contracts\Routing\RouterInterface;

final class Router implements RouterInterface
{
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

    /** @var array<string, array{0: non-empty-string, 1: list<string>}> Compiled PCRE keyed by route path (compile-once cache). */
    private array $compiledPatterns = [];

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
     * Internal match logic. Compiles each route path to a PCRE (memoised) and
     * matches it against the full request path, so multi-segment catch-all
     * routes (e.g. `/{path:.*}`) match alongside single-segment parameters.
     *
     * @return array<string, mixed>|false Returns params array if matched, false otherwise.
     */
    private function match(ServerRequestInterface $req, MatchedRoute $route): array|false
    {
        $uriPath = $req->getUri()->getPath();
        [$pattern, $names] = $this->compiledPatterns[$route->path] ??= $this->compilePattern($route->path);

        $matches = [];
        if (preg_match($pattern, $uriPath, $matches) !== 1) {
            return false;
        }

        $params = [];
        foreach ($names as $name) {
            $params[$name] = $matches[$name] ?? '';
        }

        return $params;
    }

    /**
     * Compiles a route path into a PCRE with named capture groups:
     *
     *   /users/{id}      → #^/users/(?P<id>[^/]+)$#     single segment (default)
     *   /files/{id:\d+}  → #^/files/(?P<id>\d+)$#        custom constraint
     *   /{path:.*}       → #^/(?P<path>.*)$#             spans '/', catch-all
     *
     * Static text is `preg_quote`d so literal dots/dashes stay literal. A
     * placeholder constraint containing a literal `}` is unsupported (rare).
     *
     * @return array{0: non-empty-string, 1: list<string>} [pattern, capture names]
     */
    private function compilePattern(string $path): array
    {
        $names = [];
        $regex = '';

        $tokens = preg_split('/(\{[a-zA-Z_]\w*(?::[^}]+)?\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($tokens === false) {
            return ['#^' . preg_quote($path, '#') . '$#', []];
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $parts = [];
            if (preg_match('/^\{([a-zA-Z_]\w*)(?::([^}]+))?\}$/', $token, $parts) === 1) {
                $name = $parts[1] ?? '';
                $names[] = $name;
                $constraint = $parts[2] ?? '';
                if ($constraint === '') {
                    $constraint = '[^/]+';
                }
                $regex .= '(?P<' . $name . '>' . $constraint . ')';
                continue;
            }

            $regex .= preg_quote($token, '#');
        }

        return ['#^' . $regex . '$#', $names];
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
