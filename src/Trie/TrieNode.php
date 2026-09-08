<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing\Trie;

use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Waffle\Commons\Contracts\Routing\MatchedRoute;

/**
 * A single node of the {@see RouteTrie}.
 *
 * Keeping the tree as typed objects (rather than a nested `array<string, mixed>`)
 * lets the analyzer follow every traversal without `mixed` leaking into the
 * matcher. Nodes are mutable only during {@see RouteTrie::build()}; once the trie
 * is constructed they are never written to again, so a built trie is safe to hold
 * for the worker lifetime.
 *
 * Every terminating route is stored alongside its build-time `order` — the index
 * it held in the already priority-sorted route list {@see RouteTrie::build()}
 * received. Resolution sorts collected candidates by that order ascending, which
 * reproduces the sequential `Router`'s "first match in priority order wins"
 * exactly (AOT-02), instead of the trie hardcoding static-beats-dynamic and
 * ignoring priority.
 *
 * @internal
 */
final class TrieNode
{
    /** @var array<string, TrieNode> Static segment children, keyed by literal segment. */
    #[WorkerSafe(
        scope: 'boot-time',
        reason: 'mutable only during RouteTrie::build(); a built trie is never written to again and is safe to hold for the worker lifetime',
    )]
    public array $static = [];

    /** Parameter name of the single dynamic ({param}) child, if any. */
    #[WorkerSafe(
        scope: 'boot-time',
        reason: 'mutable only during RouteTrie::build(); a built trie is never written to again and is safe to hold for the worker lifetime',
    )]
    public ?string $dynamicName = null;

    /** Optional PCRE constraint a dynamic-segment value must satisfy. */
    #[WorkerSafe(
        scope: 'boot-time',
        reason: 'mutable only during RouteTrie::build(); a built trie is never written to again and is safe to hold for the worker lifetime',
    )]
    public ?string $dynamicConstraint = null;

    /** The dynamic child node, if any. */
    #[WorkerSafe(
        scope: 'boot-time',
        reason: 'mutable only during RouteTrie::build(); a built trie is never written to again and is safe to hold for the worker lifetime',
    )]
    public ?TrieNode $dynamicChild = null;

    /** Parameter name of the catch-all wildcard leaf, if any. */
    #[WorkerSafe(
        scope: 'boot-time',
        reason: 'mutable only during RouteTrie::build(); a built trie is never written to again and is safe to hold for the worker lifetime',
    )]
    public ?string $catchAllName = null;

    /** @var list<array{order: int, route: MatchedRoute}> Routes terminating at the catch-all leaf, tagged with build-time order. */
    #[WorkerSafe(
        scope: 'boot-time',
        reason: 'mutable only during RouteTrie::build(); a built trie is never written to again and is safe to hold for the worker lifetime',
    )]
    public array $catchAllRoutes = [];

    /** @var list<array{order: int, route: MatchedRoute}> Routes terminating at this node, tagged with build-time order. */
    #[WorkerSafe(
        scope: 'boot-time',
        reason: 'mutable only during RouteTrie::build(); a built trie is never written to again and is safe to hold for the worker lifetime',
    )]
    public array $routes = [];

    /**
     * Returns (creating if absent) the static child for a literal segment.
     */
    public function staticChild(string $segment): self
    {
        return $this->static[$segment] ??= new self();
    }

    /**
     * Returns (creating if absent) the dynamic child, recording its param name
     * and constraint.
     */
    public function dynamicChild(string $name, ?string $constraint): self
    {
        if ($this->dynamicChild === null) {
            $this->dynamicChild = new self();
            $this->dynamicName = $name;
            $this->dynamicConstraint = $constraint;
        }

        return $this->dynamicChild;
    }

    /**
     * Flattens this node (recursively) to a plain nested array. Routes stay as
     * {@see MatchedRoute} instances (each paired with its build-time `order`) so
     * the AOT build step's `serialize()` + base64 dump round-trips exactly (see
     * `Waffle\Commons\Console\Command\RouteCompileCommand`).
     *
     * @return array{
     *     routes: list<array{order: int, route: MatchedRoute}>,
     *     static: array<string, mixed>,
     *     dynamic: array{name: string, constraint: ?string, node: array<string, mixed>}|null,
     *     catchall: array{name: string, routes: list<array{order: int, route: MatchedRoute}>}|null
     * }
     */
    public function toArray(): array
    {
        $static = [];
        foreach ($this->static as $segment => $child) {
            $static[$segment] = $child->toArray();
        }

        $dynamic = null;
        if ($this->dynamicChild !== null && $this->dynamicName !== null) {
            $dynamic = [
                'name' => $this->dynamicName,
                'constraint' => $this->dynamicConstraint,
                'node' => $this->dynamicChild->toArray(),
            ];
        }

        $catchall = null;
        if ($this->catchAllName !== null) {
            $catchall = ['name' => $this->catchAllName, 'routes' => $this->catchAllRoutes];
        }

        return [
            'routes' => $this->routes,
            'static' => $static,
            'dynamic' => $dynamic,
            'catchall' => $catchall,
        ];
    }

    /**
     * Rehydrates a node (recursively) from its {@see self::toArray()} shape,
     * defensively narrowing every field so a stale artifact cannot smuggle an
     * unexpected type into the matcher.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $node = new self();

        $routes = $data['routes'] ?? null;
        if (is_array($routes)) {
            $node->routes = self::narrowRoutes($routes);
        }

        $static = $data['static'] ?? null;
        if (is_array($static)) {
            foreach ($static as $segment => $child) {
                if (!is_string($segment) || !is_array($child)) {
                    continue;
                }

                /** @var array<string, mixed> $child */
                $node->static[$segment] = self::fromArray($child);
            }
        }

        $dynamic = $data['dynamic'] ?? null;
        if (is_array($dynamic)) {
            $name = $dynamic['name'] ?? null;
            $childData = $dynamic['node'] ?? null;
            $constraint = $dynamic['constraint'] ?? null;
            if (is_string($name) && is_array($childData)) {
                /** @var array<string, mixed> $childData */
                $node->dynamicName = $name;
                $node->dynamicConstraint = is_string($constraint) ? $constraint : null;
                $node->dynamicChild = self::fromArray($childData);
            }
        }

        $catchall = $data['catchall'] ?? null;
        if (is_array($catchall)) {
            $name = $catchall['name'] ?? null;
            $catchRoutes = $catchall['routes'] ?? null;
            if (is_string($name)) {
                $node->catchAllName = $name;
                $node->catchAllRoutes = is_array($catchRoutes) ? self::narrowRoutes($catchRoutes) : [];
            }
        }

        return $node;
    }

    /**
     * Narrows a freshly-thawed array to the strict `list<array{order: int,
     * route: MatchedRoute}>` shape the matcher requires, dropping any entry that
     * does not carry both a valid order and a {@see MatchedRoute}.
     *
     * @param array<array-key, mixed> $candidate
     * @return list<array{order: int, route: MatchedRoute}>
     */
    private static function narrowRoutes(array $candidate): array
    {
        $routes = [];
        foreach ($candidate as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $order = $entry['order'] ?? null;
            $route = $entry['route'] ?? null;
            if (!is_int($order) || !$route instanceof MatchedRoute) {
                continue;
            }

            $routes[] = ['order' => $order, 'route' => $route];
        }

        return $routes;
    }
}
