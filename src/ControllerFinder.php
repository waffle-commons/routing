<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing;

use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Utils\Service\ClassParser;

final readonly class ControllerFinder
{
    public function __construct(
        private ClassParser $parser = new ClassParser(),
    ) {}

    /**
     * Finds all potential controller class names in a given directory.
     *
     * @param string|false $directory The directory to scan.
     * @return array<array-key, string>|false A list of class names or false on failure.
     */
    public function find(string|false $directory): array|false
    {
        if (!$directory || !is_dir($directory)) {
            return false;
        }

        return $this->scan($directory);
    }

    /**
     * Recursively scans a directory for PHP files.
     *
     * @param string $directory The directory to scan.
     * @return list<string> A list of fully-qualified class names.
     */
    private function scan(string $directory): array
    {
        /** @var list<string> $files */
        $files = [];
        $paths = scandir(directory: $directory);

        if (!$paths) {
            return [];
        }

        foreach ($paths as $path) {
            // Ignore dot files and common system directories
            if ($path === Constant::CURRENT_DIR || $path === Constant::PREVIOUS_DIR) {
                continue;
            }

            // FIX: Explicitly ignore 'vendor' directory to prevent scanning dependencies
            if ($path === 'vendor' || $path === 'var' || str_starts_with($path, '.')) {
                continue;
            }

            $file = $directory . DIRECTORY_SEPARATOR . $path;

            if (is_dir(filename: $file)) {
                foreach ($this->scan(directory: $file) as $nested) {
                    if (!is_string($nested)) {
                        continue;
                    }
                    $files[] = $nested;
                }
                continue;
            }

            if (is_string($path) && str_contains($path, Constant::PHPEXT)) {
                $className = $this->parser->className(path: $file);
                // Only add if a valid class name was found
                if ($className !== Constant::EMPTY_STRING && is_string($className)) {
                    $files[] = $className;
                }
            }
        }

        return $files;
    }
}
