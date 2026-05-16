[![PHP Version Require](http://poser.pugx.org/waffle-commons/routing/require/php)](https://packagist.org/packages/waffle-commons/routing)
[![PHP CI](https://github.com/waffle-commons/routing/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/routing/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/routing/graph/badge.svg?token=d74ac62a-7872-4035-8b8b-bcc3af1991e0)](https://codecov.io/gh/waffle-commons/routing)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/routing/v)](https://packagist.org/packages/waffle-commons/routing)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/routing/v/unstable)](https://packagist.org/packages/waffle-commons/routing)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/routing.svg)](https://packagist.org/packages/waffle-commons/routing)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/routing)](https://github.com/waffle-commons/routing/blob/main/LICENSE.md)

Waffle Routing Component
========================

> **Release:** `v0.1.0-beta0`

Attribute-driven router. No YAML, no XML — routes live next to the controller code via the `#[Route]` attribute and are discovered by scanning the configured controller directory at boot time. The compiled route table is then cached.

## 📦 Installation

```bash
composer require waffle-commons/routing
```

## 🧱 Surface

| Class | Role |
| :--- | :--- |
| `Waffle\Commons\Routing\Router` | `RouterInterface` implementation. Boots from a `ContainerInterface`, returns matched route arrays from `matchRequest()`. |
| `Waffle\Commons\Routing\RouteDiscoverer` | Walks the controller directory, opens each PHP file via `ReflectionTrait`, and reads `#[Route]` attributes. |
| `Waffle\Commons\Routing\ControllerFinder` | Filesystem traversal of `*.php` controller files. |
| `Waffle\Commons\Routing\RouteParser` | Builds the canonical route array from a `Route` attribute + reflection metadata. |
| `Waffle\Commons\Routing\Attribute\Route` | The `#[Route(path, name, arguments)]` attribute. |
| `Waffle\Commons\Routing\Attribute\Argument` | The `#[Argument(classType, paramName, required)]` attribute for routes that auto-resolve container services. |
| `Waffle\Commons\Routing\Trait\RequestTrait` | Helpers for extracting routing data from a PSR-7 request (`_controller`, `_route_params`). |

## 🚀 `#[Route]` attribute — exact signature

From `src/Attribute/Route.php`:

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Route
{
    /**
     * @param array<Argument>|null $arguments
     */
    public function __construct(
        public string $path,
        public ?string $name = null,
        public ?array $arguments = null,
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
use Waffle\Commons\Routing\Attribute\Route;
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
/* @var array{
 *   classname:  class-string,
 *   method:     string,
 *   arguments:  array<string, mixed>,
 *   path:       string,
 *   name:       non-falsy-string,
 *   params?:    array<string, mixed>,
 * }|null $match
 */
```

`null` means no route matched — the kernel converts that into `RouteNotFoundException` (which the error handler renders as RFC 7807 `404`).

## 🐘 PHP 8.5 features used

- `#[Route]`, `#[Argument]` — PHP 8 attribute syntax with PHP 8.5 typed properties.
- `final` classes throughout.
- Strict-typed route-match arrays, typed via PHPDoc `array{…}` shapes on `RouterInterface::matchRequest()`.
- Reflection via `Waffle\Commons\Utils\Trait\ReflectionTrait` — tokenizer-based, no regex.

## 🧪 Testing

```bash
docker exec -w /waffle-commons/routing waffle-dev composer tests
```

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
