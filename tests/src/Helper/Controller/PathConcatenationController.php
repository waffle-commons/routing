<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Helper\Controller;

use Waffle\Commons\Routing\Attribute\Route;

#[Route('/base', name: 'base')]
final class PathConcatenationController
{
    #[Route('/absolute', name: 'absolute')]
    public function absolute(): void
    {
    }

    #[Route('relative', name: 'relative')]
    public function relative(): void
    {
    }

    #[Route('', name: 'empty')]
    public function empty(): void
    {
    }
}
