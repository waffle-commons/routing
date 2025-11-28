<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Trait;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Routing\Trait\RequestTrait;

final class RequestTraitTest extends TestCase
{
    use RequestTrait;

    public static function pathUriProvider(): array
    {
        return [
            'Root path' => ['/', ['', '']],
            'Simple path' => ['/users/list', ['', 'users', 'list']],
            'Path with parameters' => ['/users/{id}/edit', ['', 'users', '{id}', 'edit']],
        ];
    }

    /**
     * @param string $path
     * @param array{string, string[]} $expected
     * @return void
     */
    #[DataProvider('pathUriProvider')]
    public function testGetPathUri(string $path, array $expected): void
    {
        static::assertSame($expected, $this->getPathUri($path));
    }
}
