<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Helper\Controller;

use Waffle\Commons\Contracts\Routing\Attribute\Route;

/**
 * Helper controller exercising:
 * - Class-level Route('/') (basePath rtrim to empty) + non-empty method path → RouteParser '/' + methodPath branch.
 * - A method WITHOUT a Route attribute → RouteParser early-null branch.
 * - A typed (ReflectionNamedType) parameter → RouteParser extractParameters happy path.
 */
#[Route('/', name: 'root')]
final class TypedRootController
{
    #[Route('items/{id}', name: 'item')]
    public function show(int $id): void {}

    // Intentionally no #[Route] — exercises the early-null branch.
    public function notARoute(): void {}
}
