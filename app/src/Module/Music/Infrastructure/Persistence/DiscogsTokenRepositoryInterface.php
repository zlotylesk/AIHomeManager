<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\Persistence;

interface DiscogsTokenRepositoryInterface
{
    /**
     * @return array{oauth_token: string, oauth_token_secret: string}|null
     */
    public function get(): ?array;

    public function save(string $oauthToken, string $oauthTokenSecret): void;
}
