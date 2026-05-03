<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing\Cache;

use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Routing\Exception\RouteCacheException;

class RouteCache
{
    private const string CACHE_FILE = 'waffle_routes_cache.php';

    public function __construct(
        private(set) string $cacheDir,
    ) {
        if (!is_writable($this->cacheDir)) {
            throw new RouteCacheException(sprintf(
                'Cache directory "%s" is not writable. Route caching cannot operate securely.',
                $this->cacheDir,
            ));
        }
    }

    /**
     * Attempts to load routes from the cache file.
     *
     * @return array<mixed>|null Returns the array of routes if cache is hit, null otherwise.
     */
    public function load(): ?array
    {
        if ($this->isProduction()) {
            $cacheFile = $this->getCacheFilePath();
            if (file_exists(filename: $cacheFile)) {
                /**
                 * @var array<mixed> $routesArray
                 */
                $routesArray = require $cacheFile;
                return $routesArray;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $routes
     */
    public function save(array $routes): void
    {
        if ($this->isProduction()) {
            $cacheFile = $this->getCacheFilePath();
            $content = '<?php return ' . var_export($routes, true) . ';';
            file_put_contents($cacheFile, $content, LOCK_EX);
        }
    }

    private function isProduction(): bool
    {
        $env = getenv(Constant::APP_ENV);
        if (!$env) {
            return true;
        }

        return $env === Constant::ENV_DEFAULT;
    }

    private function getCacheFilePath(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }
}
