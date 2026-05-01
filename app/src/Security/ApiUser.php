<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final class ApiUser implements UserInterface
{
    public function __construct(private readonly string $identifier = 'api')
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_API'];
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function eraseCredentials(): void
    {
    }
}
