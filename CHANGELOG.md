# Changelog — waffle-commons/routing

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta5] — 2026-06-26

**Theme: AOT route preheat & tracing.**

### Added
- **AOT route preheat — `RouteTrie` (AOT-02 / RFC-019).** New `Waffle\Commons\Routing\Trie\RouteTrie` and `TrieNode` index the discovered route list into a segment-keyed lookup tree, so `Router::matchRequest()` resolves in **O(depth)** instead of the sequential `foreach` + per-route PCRE scan. The fast path is reflection-free and behaves identically to the legacy matcher: static / dynamic (`{id}` / `{id:\d+}`) / catch-all (`{path:.*}`) segments, priority parity (candidates from every branch are sorted by their build-time priority index, not a hardcoded static-beats-dynamic rule), and verbatim `405` / `HEAD ⇒ GET` / `OPTIONS` semantics.
- **Root catch-all parity (AOT-03).** A root-mounted catch-all (`/{path:.*}`) is now evaluated at every node — including when the path is exhausted — so it matches the root path `/` exactly as the sequential PCRE matcher did.
- **Trie cache artifact.** `Router::boot()` prefers a prebuilt trie cached under `waffle.routes.trie` (rehydrated via `RouteTrie::fromArray()` without re-walking the route list); when absent it builds the trie from the hydrated/discovered route list. `RouteTrie::toArray()` / `fromArray()` flatten and rehydrate the tree as a plain nested array for the AOT build step to cache. The trie is frozen for the worker lifetime (built once, never mutated).
- **Telemetry spans on route resolution (OBS-01).** `Router` accepts an optional `Waffle\Commons\Contracts\Telemetry\TracerInterface` (defaulting to `NullTracer`). `matchRequest()` opens an internal `waffle.routing` span carrying the `http.request.method` attribute and, on a hit, `http.route`. Route resolution moved into a private `resolve()` so the span wraps the full match — zero overhead when no tracer is wired.

### Changed
- **Cyclomatic-complexity gate enabled.** `mago.toml` now enforces `cyclomatic-complexity` at `threshold = 50` (previously disabled).

### Dependencies
- Lockstep version bump with the Beta-5 wave; `composer.lock` refreshed alongside the umbrella tag.

## [0.1.0-beta4] — 2026-06-13

### Changed
- Lockstep version bump with the Beta-4 wave (security hardening, worker-mode diagnostics, and DX tooling landed in sibling components). No behavioural changes in this component since `0.1.0-beta3`.

## [0.1.0-beta3] — 2026-06-07

**Theme: identity federation & stateless persistence (ecosystem wave).**

### Changed
- Lockstep version bump; `composer.lock` refreshed with the beta-3 dependency wave.

## [0.1.0-beta2.1] — 2026-05-30

### Changed
- Lockstep re-tag of `0.1.0-beta2` (umbrella housekeeping patch) — no source changes in this component.

## [0.1.0-beta2] — 2026-05-29

**Theme: HTTP method correctness — RFC 7231 alignment, route overloading, compiled-pattern cache.**

### Added
- **Route overloading**: `RouteParser` now allows multiple `#[Route]` declarations to share the same path provided their `methods` arrays do not intersect. Enables dispatching `GET /resource` and `POST /resource` to different controller actions.
- **HTTP method filtering**: `Router::matchRequest()` filters matched routes by the request's HTTP method. When the path matches but the method does not, it raises `Waffle\Commons\Contracts\Routing\Exception\MethodNotAllowedException` carrying the allowed-methods list.
- **HEAD ⇒ GET fallback** (RFC 7231 §4.3.2): a request with method `HEAD` matches a `GET` route automatically. The controller still produces a body; the framework strips it at response time (handled upstream).
- **Deterministic `Allow` header source**: when raising 405, `Router` merges declared methods, auto-augments with `HEAD` (if `GET` is allowed) and `OPTIONS`, deduplicates, and alphabetically sorts the resulting list. The downstream renderer copies this verbatim into the `Allow` header.
- **Worker-safe compiled-pattern cache**: `Router` memoises PCRE patterns derived from route templates. The cache lives in the resident worker's memory and survives across requests for the worker's lifetime.

### Changed
- HTTP method names are now canonicalised (upper-case) and deduplicated at discovery time, not at match time. Catches typos like `#[Route(methods: ['get'])]` at boot rather than silently miss-routing at runtime.
- `src/Attribute/Route.php` **removed**. The canonical `#[Route]` attribute now lives at `Waffle\Commons\Contracts\Routing\Attribute\Route` (in the `contracts` package). Internal test fixtures migrated; all external consumers should `use Waffle\Commons\Contracts\Routing\Attribute\Route;`.
- README refreshed to document HTTP method filtering, overloading, and the attribute relocation.

### Tests
- `testMatchRequestWithHttpMethodFilteringAndOverloading` — method matching, case-insensitivity, 405 semantics.
- `testHeadRequestMatchesGetRoute` — HEAD/GET fallback.
- `testAllowHeaderIsDeterministicAndAlphabeticallySorted` — `Allow` header content rule.
- `testPcrePatternCacheReusesCompiledPattern` — verifies the compile-once memoization.
- 175 lines added to `RouterTest.php`.

### Dependencies
- `composer.lock` refreshed (PHPUnit, `sebastian/environment`, Symfony polyfills).

## [0.1.0-beta1]

See the umbrella [CHANGELOG](../CHANGELOG.md#010-beta1) for the full Beta-1 narrative — `priority` support, `MatchedRoute` typed return value, and the original `#[Route]` attribute (then in this package) all landed in Beta-1.
