<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing;

use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class AbstractTestCase extends BaseTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $dirs = [
            'var/cache/prod',
        ];

        foreach ($dirs as $dir) {
            $path = APP_ROOT . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
                // Try to set permissions for Docker compatibility (if supported)
                @chmod($path, 0777);
            }
        }

        parent::setUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $fileinfo) {
            $action = $fileinfo->isDir() && !$fileinfo->isLink() ? 'rmdir' : 'unlink';
            $action($fileinfo->getRealPath());
        }
        unset($files);

        rmdir($dir);
    }
}
