<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\Persistence;

interface DiscogsTokenRepositoryInterface
{
    public function get(): ?array;

    public function save(string $oauthToken, string $oauthTokenSecret): void;
}
