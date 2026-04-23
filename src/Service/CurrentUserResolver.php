<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CurrentUserResolver
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function resolveUserId(Request $request): int
    {
        $header = $request->headers->get('X-User-Id');
        if ($header !== null && $header !== '') {
            return max(1, (int) $header);
        }

        return 1;
    }

    public function resolveUser(Request $request): User
    {
        $id = $this->resolveUserId($request);
        $user = $this->users->find($id);
        if (!$user instanceof User) {
            throw new NotFoundHttpException(sprintf('User %d not found.', $id));
        }

        return $user;
    }
}
