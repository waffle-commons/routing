<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Helper\Controller;

use Exception;
use Waffle\Commons\Routing\Attribute\Route;

/**
 * This is a fake controller used exclusively for testing purposes.
 * It helps validate the Router and Response logic without depending on real application code.
 */
#[Route(path: '/', name: 'user')]
final class TempController
{
    #[Route(path: 'users', name: 'users_list')]
    public function list(): void
    {
    }

    #[Route(path: 'users/{id}', name: 'users_show')]
    public function show(int $id): void
    {
    }

    #[Route(path: 'users/{id}/{slug}', name: 'users_details')]
    public function details(int $id, string $slug): void
    {
    }

    #[Route(path: 'users/profile/view', name: 'users_profile_view')]
    public function profile(): void
    {
    }

    /**
     * @throws Exception
     */
    #[Route(path: 'trigger-error', name: 'trigger_error')]
    public function throwError(): void
    {
        throw new Exception('Something went wrong');
    }
}
