<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Routing\Exception\MethodNotAllowedException;
use Waffle\Commons\Contracts\Routing\MatchedRoute;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanKind;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;

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
        private readonly TracerInterface $tracer = new NullTracer(),
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
        $span = $this->tracer->startSpan('waffle.routing', SpanKind::Internal);
        $span->setAttribute('http.request.method', $request->getMethod());

        try {
            $route = $this->resolve($request);
            if ($route !== null) {
                $span->setAttribute('http.route', $route->path);
            }

            return $route;
        } finally {
            $span->end();
        }
    }

    /**
     * Resolve the matching route (or null), raising a 405 when only the HTTP method mismatches.
     *
     * @throws MethodNotAllowedException
     */
    private function resolve(ServerRequestInterface $request): ?MatchedRoute
    {
        // List of routes that match the path but not the requested HTTP method
        /** @var list<MatchedRoute> $methodMismatchedRoutes */
        $methodMismatchedRoutes = [];
        $pathMatched = false;

        $rawMethod = $request->getMethod();
        $requestMethod = $rawMethod === '' ? 'GET' : strtoupper($rawMethod);

        foreach ($this->routes as $route) {
            $params = $this->match($request, $route);
            if ($params !== false) {
                $pathMatched = true;

                // A route with no declared methods (empty array) accepts everything; the
                // comparison is otherwise case-insensitive. Per RFC 7231 §4.3.2 a resource
                // that serves GET also serves HEAD, so a HEAD request matches a GET route
                // (the controller runs; stripping the body is the response emitter's concern).
                $allowedMethods = array_map('strtoupper', $route->methods);
                $methodMatches =
                    $allowedMethods === []
                    || in_array($requestMethod, $allowedMethods, true)
                    || $requestMethod === 'HEAD' && in_array('GET', $allowedMethods, true);

                if ($methodMatches) {
                    // Exact match on path and HTTP method (first one wins)
                    return $route->withParams($params);
                }

                // Path matches but method does not, keep track to throw 405 if needed
                $methodMismatchedRoutes[] = $route;
            }
        }

        if (!$pathMatched) {
            // No path matches: return null as per RouterInterface contract
            return null;
        }

        // Path matches but HTTP method does not: HTTP 405 Method Not Allowed. The Allow
        // list is merged across every path-matching candidate, augmented with the methods
        // the framework auto-serves (HEAD, OPTIONS) and sorted, for a deterministic,
        // RFC 7231-compliant response.
        $allowedMethods = $this->buildAllowedMethods($methodMismatchedRoutes);

        $message = sprintf(
            'The requested HTTP method %s is not allowed for this route. Allowed methods: %s.',
            $rawMethod === '' ? 'GET' : $rawMethod,
            implode(', ', $allowedMethods),
        );

        throw new MethodNotAllowedException($allowedMethods, $message);
    }

    /**
     * Builds the deterministic, RFC 7231-compliant set of HTTP methods allowed for a path,
     * given the routes whose path matched the request. Methods are upper-cased and
     * de-duplicated; HEAD is implied by GET (§4.3.2) and OPTIONS is always advertised
     * because the pipeline auto-answers it. The list is sorted alphabetically so the
     * response — and the `Allow` header derived from it — is stable across requests and
     * trivial to assert against.
     *
     * @param list<MatchedRoute> $pathMatchedRoutes
     * @return list<string>
     */
    private function buildAllowedMethods(array $pathMatchedRoutes): array
    {
        $methods = [];
        foreach ($pathMatchedRoutes as $route) {
            foreach ($route->methods as $method) {
                $methods[] = strtoupper($method);
            }
        }

        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }
        $methods[] = 'OPTIONS';

        $methods = array_values(array_unique($methods));
        sort($methods);

        return $methods;
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

        // Compile-once memoisation: PCRE compilation is pure with respect to the route
        // path, so the result is cached on the resident Router instance and reused across
        // every request in FrankenPHP worker mode. The cache is bounded by the number of
        // distinct route paths and holds no request-specific state, so it is worker-safe.
        $compiled = $this->compiledPatterns[$route->path] ?? null;
        if ($compiled === null) {
            $compiled = $this->compilePattern($route->path);
            $this->compiledPatterns[$route->path] = $compiled;
        }
        [$pattern, $names] = $compiled;

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
