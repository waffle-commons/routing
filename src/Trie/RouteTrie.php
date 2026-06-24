<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing\Trie;

use Waffle\Commons\Contracts\Routing\Exception\MethodNotAllowedException;
use Waffle\Commons\Contracts\Routing\MatchedRoute;

/**
 * Segment-keyed lookup tree for O(depth) route resolution (AOT-02).
 *
 * Instead of the sequential `foreach` scan + per-route PCRE that
 * {@see \Waffle\Commons\Routing\Router::resolve()} performs, the trie indexes
 * routes by their path segments:
 *
 *   - A *static* segment (e.g. `users`) is a literal key into {@see TrieNode::$static}.
 *   - A *dynamic* segment (e.g. `{id}` / `{id:\d+}`) is the single
 *     {@see TrieNode::$dynamicChild}, carrying the parameter name and an optional
 *     PCRE constraint that the captured value must satisfy.
 *   - A *catch-all* segment (e.g. `{path:.*}`) is a wildcard leaf
 *     ({@see TrieNode::$catchAllRoutes}) that consumes every remaining path
 *     segment into one parameter.
 *
 * ## Priority parity with the sequential matcher (AOT-02)
 *
 * The sequential {@see \Waffle\Commons\Routing\Router} walks an already
 * priority-sorted route list and returns the FIRST route whose path (and method)
 * matches — so a higher-priority dynamic route beats a lower-priority static one.
 * The trie must reproduce that exact ordering, NOT a hardcoded
 * "static-beats-dynamic". To do so, {@see self::build()} tags every route with the
 * index it held in that priority-sorted list; lookup collects *all* path-matching
 * candidates from every branch (static, dynamic, catch-all) and then sorts them by
 * that build-time order ascending — making the trie's winner identical to the
 * sequential matcher's. Method-mismatch (405) and HEAD/OPTIONS handling are ported
 * verbatim from {@see \Waffle\Commons\Routing\Router}.
 *
 * ## Root catch-all parity (AOT-03)
 *
 * A root-mounted catch-all (e.g. `/{path:.*}`) lives on the root node. The
 * sequential matcher's PCRE `#^/(?P<path>.*)$#` matches the root path `/` (the
 * `path` param captures the empty string), so the trie evaluates the catch-all at
 * EVERY node — including when the request path is exhausted — instead of only on
 * the non-terminal branch, which used to miss `/`.
 *
 * The tree is a pure value object: {@see self::toArray()} flattens it to a plain
 * nested array (no node objects, routes kept as {@see MatchedRoute} instances)
 * that an AOT build step caches via `serialize()` + base64 (see
 * `Waffle\Commons\Console\Command\RouteCompileCommand`), and
 * {@see self::fromArray()} rehydrates it. Once built it is never mutated, so it
 * is safe to hold for the worker lifetime.
 */
final class RouteTrie
{
    private function __construct(
        private readonly TrieNode $root,
    ) {}

    /**
     * Builds a trie from the discovered (priority-sorted) route list. Each route
     * is tagged with its index in that list so resolution can reproduce the
     * sequential matcher's "first in priority order wins" ordering.
     *
     * @param list<MatchedRoute> $routes
     */
    public static function build(array $routes): self
    {
        $root = new TrieNode();
        $trie = new self($root);
        foreach ($routes as $order => $route) {
            $trie->insert($route, $order);
        }

        return $trie;
    }

    /**
     * Rehydrates a trie from its {@see self::toArray()} representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(TrieNode::fromArray($data));
    }

    /**
     * Flattens the trie to a plain nested array (routes kept as
     * {@see MatchedRoute} instances, each paired with its build-time order) that
     * the AOT build step caches via `serialize()` + base64 — the immutable DTOs
     * round-trip exactly without a `__set_state()` hook, which a `var_export()`
     * artifact would have required.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->root->toArray();
    }

    /**
     * Resolves the matching route for a method + path (or null on a path miss),
     * raising a 405 when the path matches but the HTTP method does not.
     *
     * @throws MethodNotAllowedException
     */
    public function match(string $method, string $path): ?MatchedRoute
    {
        $rawMethod = $method;
        $requestMethod = $rawMethod === '' ? 'GET' : strtoupper($rawMethod);

        $segments = $this->splitSegments($path);

        /** @var list<array{order: int, route: MatchedRoute}> $candidates */
        $candidates = [];
        $this->collect($this->root, $segments, 0, [], $candidates);

        if ($candidates === []) {
            return null;
        }

        // Reproduce the sequential matcher's ordering: the route with the smallest
        // build-time order (highest priority, then earliest declaration) wins.
        // usort is stable on PHP 8.5, so equal orders keep collection order.
        usort($candidates, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        /** @var list<MatchedRoute> $methodMismatched */
        $methodMismatched = [];
        foreach ($candidates as $candidate) {
            $route = $candidate['route'];
            $allowedMethods = array_map('strtoupper', $route->methods);
            $methodMatches =
                $allowedMethods === []
                || in_array($requestMethod, $allowedMethods, true)
                || $requestMethod === 'HEAD' && in_array('GET', $allowedMethods, true);

            if ($methodMatches) {
                return $route;
            }

            $methodMismatched[] = $route;
        }

        $allowed = $this->buildAllowedMethods($methodMismatched);
        $message = sprintf(
            'The requested HTTP method %s is not allowed for this route. Allowed methods: %s.',
            $rawMethod === '' ? 'GET' : $rawMethod,
            implode(', ', $allowed),
        );

        throw new MethodNotAllowedException($allowed, $message);
    }

    /**
     * Inserts a single route into the trie, tagging it with its build-time order.
     */
    private function insert(MatchedRoute $route, int $order): void
    {
        $segments = $this->splitSegments($route->path);
        $node = $this->root;

        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            $segment = $segments[$i] ?? '';
            $param = $this->parseParam($segment);

            if ($param === null) {
                $node = $node->staticChild($segment);
                continue;
            }

            [$name, $constraint, $isCatchAll] = $param;

            if ($isCatchAll) {
                // Catch-all consumes the remainder; it is necessarily a leaf.
                $node->catchAllName = $name;
                $node->catchAllRoutes[] = ['order' => $order, 'route' => $route];

                return;
            }

            $node = $node->dynamicChild($name, $constraint);
        }

        $node->routes[] = ['order' => $order, 'route' => $route];
    }

    /**
     * Walks the trie collecting every route whose path matches, attaching the
     * captured params and the route's build-time order. EVERY branch is explored —
     * static, dynamic, and catch-all — so the caller can pick the priority winner
     * exactly as the sequential matcher would (rather than the trie hardcoding a
     * static-beats-dynamic preference). The catch-all is evaluated even when the
     * path is exhausted so a root catch-all matches `/` (AOT-03).
     *
     * @param list<string>                                  $segments
     * @param array<string, string>                         $params
     * @param list<array{order: int, route: MatchedRoute}>  $out
     */
    private function collect(TrieNode $node, array $segments, int $index, array $params, array &$out): void
    {
        if ($index === count($segments)) {
            foreach ($node->routes as $entry) {
                $route = $entry['route'];
                $out[] = [
                    'order' => $entry['order'],
                    'route' => $params === [] ? $route : $route->withParams($params),
                ];
            }

            // A catch-all on this node matches an empty remainder too: this is how
            // a root-mounted catch-all matches the root path `/` (AOT-03).
            $this->collectCatchAll($node, $segments, $index, $params, $out);

            return;
        }

        $segment = $segments[$index] ?? '';

        // Static segment child.
        $staticChild = $node->static[$segment] ?? null;
        if ($staticChild instanceof TrieNode) {
            $this->collect($staticChild, $segments, $index + 1, $params, $out);
        }

        // Dynamic single-segment child.
        if ($node->dynamicChild instanceof TrieNode && $node->dynamicName !== null) {
            if ($this->satisfiesConstraint($segment, $node->dynamicConstraint)) {
                $childParams = $params;
                $childParams[$node->dynamicName] = $segment;
                $this->collect($node->dynamicChild, $segments, $index + 1, $childParams, $out);
            }
        }

        // Catch-all wildcard leaf (consumes the remainder).
        $this->collectCatchAll($node, $segments, $index, $params, $out);
    }

    /**
     * Adds this node's catch-all routes (if any) to the candidate set, capturing
     * the remaining path segments — an empty string when the path is exhausted.
     *
     * @param list<string>                                  $segments
     * @param array<string, string>                         $params
     * @param list<array{order: int, route: MatchedRoute}>  $out
     */
    private function collectCatchAll(TrieNode $node, array $segments, int $index, array $params, array &$out): void
    {
        if ($node->catchAllName === null || $node->catchAllRoutes === []) {
            return;
        }

        $remainder = implode('/', array_slice($segments, $index));
        $childParams = $params;
        $childParams[$node->catchAllName] = $remainder;
        foreach ($node->catchAllRoutes as $entry) {
            $out[] = ['order' => $entry['order'], 'route' => $entry['route']->withParams($childParams)];
        }
    }

    /**
     * Splits a path into its non-empty segments. A leading/trailing slash yields
     * no empty segments, and the root path `/` yields an empty list.
     *
     * @return list<string>
     */
    private function splitSegments(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        return explode('/', $trimmed);
    }

    /**
     * Parses a `{name}` / `{name:constraint}` segment.
     *
     * @return array{0: string, 1: ?string, 2: bool}|null [name, constraint, isCatchAll] or null for static
     */
    private function parseParam(string $segment): ?array
    {
        $matches = [];
        if (preg_match('/^\{([a-zA-Z_]\w*)(?::([^}]+))?\}$/', $segment, $matches) !== 1) {
            return null;
        }

        $name = $matches[1] ?? '';
        $rawConstraint = $matches[2] ?? '';
        $constraint = $rawConstraint !== '' ? $rawConstraint : null;

        // A constraint that can span the '/' separator (e.g. `.*`, `.+`) is a
        // multi-segment catch-all: it consumes the entire path remainder.
        $isCatchAll = $constraint !== null && $this->constraintSpansSegments($constraint);

        return [$name, $constraint, $isCatchAll];
    }

    /**
     * Whether a captured value satisfies the (optional) per-param PCRE constraint.
     */
    private function satisfiesConstraint(string $value, ?string $constraint): bool
    {
        if ($constraint === null) {
            return true;
        }

        return preg_match('#^(?:' . $constraint . ')$#', $value) === 1;
    }

    /**
     * Whether a constraint regex can match a string containing a '/', marking the
     * parameter as a multi-segment catch-all (mirrors the full-path PCRE the
     * sequential matcher compiles for `{path:.*}`-style routes).
     */
    private function constraintSpansSegments(string $constraint): bool
    {
        return preg_match('#^(?:' . $constraint . ')$#', 'a/b') === 1;
    }

    /**
     * Builds the deterministic, RFC 7231-compliant Allow set — ported verbatim
     * from {@see \Waffle\Commons\Routing\Router::buildAllowedMethods()}.
     *
     * @param list<MatchedRoute> $pathMatchedRoutes
     * @return list<string>
     */
    private function buildAllowedMethods(array $pathMatchedRoutes): array
    {
        $methods = [];
        foreach ($pathMatchedRoutes as $route) {
            foreach ($route->methods as $httpMethod) {
                $methods[] = strtoupper($httpMethod);
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
}
