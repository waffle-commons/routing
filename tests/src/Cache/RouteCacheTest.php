<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Routing\Cache\RouteCache;
use Waffle\Commons\Routing\Exception\RouteCacheException;

#[CoversClass(RouteCache::class)]
final class RouteCacheTest extends TestCase
{
    private string $cacheDir;
    private string $cacheFilePath;
    private mixed $originalAppEnv;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/waffle_cache_test_' . uniqid();
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0o755, true);
        }
        $this->cacheFilePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'waffle_routes_cache.php';

        $this->originalAppEnv = getenv(Constant::APP_ENV);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->cacheFilePath)) {
            unlink($this->cacheFilePath);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
        if ($this->originalAppEnv === false) {
            putenv(Constant::APP_ENV);
        } else {
            putenv(Constant::APP_ENV . '=' . $this->originalAppEnv);
        }
        parent::tearDown();
    }

    public function testConstructorThrowsOnNonWritableDirectory(): void
    {
        $readOnlyDir = sys_get_temp_dir() . '/waffle_readonly_test_' . uniqid();
        mkdir($readOnlyDir, 0o444, true);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('is not writable');

        try {
            new RouteCache($readOnlyDir);
        } finally {
            chmod($readOnlyDir, 0755);
            rmdir($readOnlyDir);
        }
    }

    public function testLoadReturnsNullWhenNotInProduction(): void
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_DEV);
        $routeCache = new RouteCache($this->cacheDir);

        file_put_contents($this->cacheFilePath, '<?php return ["dummy_route"];');

        static::assertNull($routeCache->load());
    }

    public function testLoadReturnsNullWhenInProductionButCacheFileDoesNotExist(): void
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_PROD);
        $routeCache = new RouteCache($this->cacheDir);

        static::assertNull($routeCache->load());
    }

    public function testLoadReturnsRoutesWhenInProductionAndCacheExists(): void
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_PROD);
        $routeCache = new RouteCache($this->cacheDir);

        $expectedRoutes = [
            ['path' => '/', 'controller' => 'HomeController', 'name' => 'home'],
            ['path' => '/about', 'controller' => 'AboutController', 'name' => 'about'],
        ];
        $content = '<?php return ' . var_export($expectedRoutes, true) . ';';
        file_put_contents($this->cacheFilePath, $content);

        static::assertSame($expectedRoutes, $routeCache->load());
    }

    public function testSaveDoesNothingWhenNotInProduction(): void
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_DEV);
        $routeCache = new RouteCache($this->cacheDir);
        $routesToSave = [['path' => '/test', 'name' => 'test']];

        $routeCache->save($routesToSave);

        static::assertFileDoesNotExist($this->cacheFilePath);
    }

    public function testSaveWritesCacheFileWhenInProduction(): void
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_PROD);
        $routeCache = new RouteCache($this->cacheDir);
        $routesToSave = [
            ['path' => '/save-test', 'controller' => 'SaveController', 'name' => 'save_test'],
        ];

        $routeCache->save($routesToSave);

        static::assertFileExists($this->cacheFilePath);
        /** @var array<array<string, string>> $loadedRoutes */
        $loadedRoutes = require $this->cacheFilePath;
        static::assertSame($routesToSave, $loadedRoutes);
    }

    public function testReadOnlyFilesystemPreventsCacheSave(): void
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_PROD);

        $readOnlyDir = sys_get_temp_dir() . '/waffle_readonly_save_test_' . uniqid('tmp', true);
        mkdir($readOnlyDir, 0o755, true);
        chmod($readOnlyDir, 0444);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('is not writable');

        try {
            new RouteCache($readOnlyDir);
        } finally {
            chmod($readOnlyDir, 0755);
            rmdir($readOnlyDir);
        }
    }

    public function testIsProductionHelper(): void
    {
        $routeCache = new RouteCache($this->cacheDir);

        $reflector = new \ReflectionClass(RouteCache::class);
        $method = $reflector->getMethod('isProduction');

        putenv(Constant::APP_ENV . '=' . Constant::ENV_PROD);
        static::assertTrue($method->invoke($routeCache), 'isProduction should be true for prod env');

        putenv(Constant::APP_ENV . '=' . Constant::ENV_DEV);
        static::assertFalse($method->invoke($routeCache), 'isProduction should be false for dev env');

        putenv(Constant::APP_ENV . '=' . Constant::ENV_TEST);
        static::assertFalse($method->invoke($routeCache), 'isProduction should be false for test env');

        putenv(Constant::APP_ENV);
        static::assertTrue($method->invoke($routeCache), 'isProduction should default to true if env var is not set');
    }
}
