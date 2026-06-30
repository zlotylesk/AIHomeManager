<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Persistence;

use App\Shared\Security\GoogleTokenProviderInterface;

interface GoogleTokenRepositoryInterface extends GoogleTokenProviderInterface
{
    public function save(array $token): void;
}
