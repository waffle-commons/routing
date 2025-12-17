<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use Waffle\Commons\Routing\Cache\RouteCache;
use Waffle\Commons\Routing\Trait\RequestTrait;
use Waffle\Commons\Utils\Trait\ReflectionTrait;

final class Router implements RouterInterface
{
    use ReflectionTrait;
    use RequestTrait;

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
     * @var array<array-key, array{
     *      classname: class-string,
     *      method: string,
     *      arguments: array<string, mixed>,
     *      path: string,
     *      name: non-falsy-string
     * }>
     */
    public array $routes {
        set => $this->routes = $value;
    }

    private readonly RouteCache $cache;

    private readonly RouteDiscoverer $discoverer;

    public function __construct(string|false $directory)
    {
        $this->routes = [];
        $this->files = false;
        $this->cache = new RouteCache();
        $this->discoverer = new RouteDiscoverer(directory: $directory);
    }

    #[\Override]
    public function boot(ContainerInterface $container): self
    {
        $cachedRoutes = $this->cache->load();
        if (null !== $cachedRoutes) {
            /**
             * @var array<array-key, array{
             *      classname: class-string,
             *      method: string,
             *      arguments: array<string, mixed>,
             *      path: string,
             *      name: non-falsy-string
             *  }> $routesArray
             */
            $routesArray = $cachedRoutes;
            $this->routes = $routesArray;

            return $this;
        }

        $this->routes = $this->discoverer->discover($container);
        $this->cache->save($this->routes);

        return $this;
    }

    /**
     * Matches the current request against registered routes.
     *
     * @param ServerRequestInterface $request
     * @return array{
     *        classname: class-string,
     *        method: string,
     *        arguments: array<string, mixed>,
     *        path: string,
     *        name: non-falsy-string
     *   }|null Returns the route array if matched, null otherwise.
     */
    #[\Override]
    public function matchRequest(ServerRequestInterface $request): null|array
    {
        foreach ($this->routes as $route) {
            $params = $this->match($request, $route);
            if ($params !== false) {
                $route['params'] = $params;
                return $route;
            }
        }

        return null;
    }

    /**
     * Internal match logic.
     *
     * @param ServerRequestInterface $req
     * @param array{
     *        classname: class-string,
     *        method: string,
     *        arguments: array<string, mixed>,
     *        path: string,
     *        name: non-falsy-string
     *   } $route
     * @return array<string, mixed>|false Returns params array if matched, false otherwise.
     */
    private function match(ServerRequestInterface $req, array $route): array|false
    {
        // PSR-7 URI Handling
        $uriPath = $req->getUri()->getPath();
        $pathSegments = $this->getPathUri($route[Constant::PATH]);
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
     * @return array<array-key, array{
     *       classname: class-string,
     *       method: string,
     *       arguments: array<string, mixed>,
     *       path: string,
     *       name: non-falsy-string
     *  }>
     */
    #[\Override]
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
