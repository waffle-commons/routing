[![PHP Version Require](http://poser.pugx.org/waffle-commons/routing/require/php)](https://packagist.org/packages/waffle-commons/routing)
[![PHP CI](https://github.com/waffle-commons/routing/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/routing/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/routing/graph/badge.svg?token=d74ac62a-7872-4035-8b8b-bcc3af1991e0)](https://codecov.io/gh/waffle-commons/routing)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/routing/v)](https://packagist.org/packages/waffle-commons/routing)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/routing/v/unstable)](https://packagist.org/packages/waffle-commons/routing)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/routing.svg)](https://packagist.org/packages/waffle-commons/routing)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/routing)](https://github.com/waffle-commons/routing/blob/main/LICENSE.md)

Waffle Routing Component
========================

> **Release:** `v0.1.0-beta1`

Attribute-driven router. No YAML, no XML — routes live next to the controller code via the `#[Route]` attribute and are discovered by scanning the configured controller directory at boot time. The compiled route table is then cached.

## 🆕 Beta-1 highlights

- **Priority routing & catch-all.** `#[Route]` now takes an `int $priority = 0`. At boot the router sorts the compiled table by **descending** priority, so high-priority specific routes are tried before low-priority ones. Negative priorities (e.g. `-1000`) flag catch-all routes like `/{path:.*}` that should only match once every specific route has failed — the foundation for Strangler-Fig / API-gateway proxying.
- **HTTP method filtering & overloading.** `#[Route(methods: ['GET', 'POST'])]` constrains a route to specific verbs, and several controllers can share one path across different methods. Matching is case-insensitive; a `GET` route also serves `HEAD` (RFC 7231 §4.3.2); `OPTIONS` is auto-answered (`204` + `Allow`) when the routing middleware has a PSR-17 response factory; and a method mismatch yields a `405` whose `Allow` header is merged across candidates, `HEAD`/`OPTIONS`-augmented, de-duplicated, and alphabetically sorted.
- **`MatchedRoute` DTO.** `matchRequest()` now returns a typed `Waffle\Commons\Contracts\Routing\MatchedRoute` (or `null`) instead of a loose array.
- **Reflection cleanup.** Discovery now reads `#[Route]` via native `ReflectionClass`/`ReflectionMethod::getAttributes()` — the old `ReflectionTrait` has been removed (Beta-1 Phase 1 architectural pass).

## 📦 Installation

```bash
composer require waffle-commons/routing
```

## 🧱 Surface

| Class | Role |
| :--- | :--- |
| `Waffle\Commons\Routing\Router` | `RouterInterface` implementation. Boots from a `ContainerInterface`, sorts the table by descending `priority`, and returns a `?MatchedRoute` from `matchRequest()`. |
| `Waffle\Commons\Routing\RouteDiscoverer` | Walks the controller directory and builds a `list<MatchedRoute>` from each controller's `#[Route]` attributes. |
| `Waffle\Commons\Routing\ControllerFinder` | Filesystem traversal of `*.php` controller files. |
| `Waffle\Commons\Routing\RouteParser` | Builds a `MatchedRoute` from a `Route` attribute + native reflection metadata (`ReflectionClass`/`ReflectionMethod`). A method-level `priority` overrides the class-level default. |
| `Waffle\Commons\Contracts\Routing\Attribute\Route` | The `#[Route(path, methods, name, arguments, priority)]` attribute (defined in `contracts`). |
| `Waffle\Commons\Routing\Attribute\Argument` | The `#[Argument(classType, paramName, required)]` attribute for routes that auto-resolve container services. |
| `Waffle\Commons\Routing\Trait\RequestTrait` | Helpers for extracting routing data from a PSR-7 request (`_controller`, `_route_params`). |

## 🚀 `#[Route]` attribute — exact signature

From `Waffle\Commons\Contracts\Routing\Attribute\Route` (the attribute lives in `contracts`, so every component reads the same definition):

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Route
{
    /**
     * @param array<string>        $methods   Allowed HTTP methods. Default ['GET']; empty [] accepts any.
     * @param array<Argument>|null $arguments
     * @param int                  $priority Higher matches first. Use negative
     *                                       values (e.g. -1000) for catch-all routes.
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public ?array $arguments = null,
        public int $priority = 0,
    ) {}
}
```

`Argument` (from `src/Attribute/Argument.php`):

```php
#[Attribute]
final class Argument
{
    public function __construct(
        public string $classType,
        public string $paramName,
        public bool $required = true,
    ) {}
}
```

## 🚀 Declaring routes on a controller

```php
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Routing\Attribute\Argument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UserController
{
    #[Route(path: '/users/{id}', name: 'users.show')]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        // The {id} placeholder is exposed via $request->getAttribute('_route_params')['id']
    }

    #[Route(
        path: '/users',
        name: 'users.create',
        arguments: [
            new Argument(classType: UserRepository::class, paramName: 'repo'),
        ],
    )]
    public function create(ServerRequestInterface $request, UserRepository $repo): ResponseInterface
    {
        // $repo is auto-injected via the container.
    }
}
```

## 🔎 Matching a request

```php
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Container\Container;

$router = new Router(/* …discoverer, cache… */);
$router->boot($container);

$match = $router->matchRequest($psr7Request);
/* @var \Waffle\Commons\Contracts\Routing\MatchedRoute|null $match
 *
 * MatchedRoute exposes: className, method, arguments, path, name, params, priority.
 * Use $match->withParams([...]) to attach the captured path parameters immutably.
 */
```

`null` means no route matched — the kernel converts that into `RouteNotFoundException` (which the error handler renders as RFC 7807 `404`). Routes are evaluated in descending `priority` order, so a catch-all (`priority: -1000`) is only reached after every specific route has failed.

## 🔀 HTTP method filtering

```php
use Waffle\Commons\Contracts\Routing\Attribute\Route;

final class ArticleController
{
    #[Route(path: '/articles', methods: ['GET'], name: 'articles.list')]
    public function list(): ResponseInterface { /* … */ }

    #[Route(path: '/articles', methods: ['POST'], name: 'articles.create')]
    public function create(): ResponseInterface { /* … */ }
}
```

- Matching is **case-insensitive**; methods are upper-cased + de-duplicated at discovery.
- A `GET` route also answers `HEAD` (RFC 7231 §4.3.2).
- `OPTIONS` to a known path is auto-answered `204` + `Allow` when `CoreRoutingMiddleware` is wired with a PSR-17 response factory.
- A method mismatch throws `MethodNotAllowedException` (`405`); its `Allow` header is merged across candidates, augmented (`HEAD` if `GET`, always `OPTIONS`), de-duplicated, and **sorted** — e.g. `Allow: GET, HEAD, OPTIONS, POST`.

## 🐘 PHP 8.5 features used

- `#[Route]`, `#[Argument]` — PHP 8 attribute syntax with PHP 8.5 typed properties.
- `final` classes throughout.
- Strongly-typed `MatchedRoute` DTO return from `RouterInterface::matchRequest()` (no loose arrays).
- Native reflection (`ReflectionClass` / `ReflectionMethod::getAttributes()`) to read `#[Route]` — the old `ReflectionTrait` was removed in Beta-1.

## 🧪 Testing

```bash
docker exec -w /waffle-commons/routing waffle-dev composer tests
```

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
