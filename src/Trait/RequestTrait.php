<?php

declare(strict_types=1);

namespace Waffle\Commons\Routing\Trait;

trait RequestTrait
{
    /**
     * @return string[]
     */
    public function getPathUri(string $path): array
    {
        return explode(
            separator: DIRECTORY_SEPARATOR,
            string: $path,
        );
    }
}
