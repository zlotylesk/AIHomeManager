<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Persistence;

interface GoogleTokenRepositoryInterface
{
    public function get(): ?array;
    public function save(array $token): void;
}
