<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class ApiUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return new ApiUser($identifier);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ApiUser) {
            throw new UnsupportedUserException(\sprintf('Invalid user class "%s".', $user::class));
        }

        return new ApiUser($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return ApiUser::class === $class || is_subclass_of($class, ApiUser::class);
    }
}
