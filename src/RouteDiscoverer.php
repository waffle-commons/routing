<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Routing\MatchedRoute;

final readonly class RouteDiscoverer
{
    private RouteParser $parser;

    /**
     * @var array<array-key, string>|false
     */
    private array|false $files;

    public function __construct(string|false $directory)
    {
        $this->parser = new RouteParser();
        $this->files = new ControllerFinder()->find(directory: $directory);
    }

    /**
     * @return list<MatchedRoute>
     */
    public function discover(ContainerInterface $_container): array
    {
        if (!$this->files) {
            return [];
        }

        $routes = [];
        foreach ($this->files as $file) {
            $discoveredRoutes = $this->parser->parse($file);
            if ($discoveredRoutes !== []) {
                $routes = array_merge($routes, $discoveredRoutes);
            }
        }

        return $routes;
    }
}
