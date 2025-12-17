<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Helper\Controller;

use Waffle\Commons\Routing\Attribute\Route;

#[Route('/complex')]
final class ComplexParametersController
{
    #[Route('/untyped/{param}', name: 'untyped')]
    public function untyped($_param): void
    {
    }

    #[Route('/union/{param}', name: 'union')]
    public function union(int|string $_param): void
    {
    }
}
