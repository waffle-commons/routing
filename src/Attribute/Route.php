<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Route
{
    /**
     * @param string               $path
     * @param string|null          $name
     * @param array<Argument>|null $arguments
     * @param int                  $priority Higher matches first. Use negative values
     *                                       (e.g. `-1000`) for catch-all routes that
     *                                       must be evaluated last. Defaults to `0`.
     */
    public function __construct(
        public string $path,
        public ?string $name = null,
        public ?array $arguments = null,
        public int $priority = 0,
    ) {}
}
