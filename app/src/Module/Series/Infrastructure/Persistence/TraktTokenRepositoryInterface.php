<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Persistence;

/**
 * Stores the single Trakt.tv OAuth2 token (single-user system).
 *
 * The token is the full payload returned by Trakt's token endpoint:
 * access_token, refresh_token, token_type, scope, expires_in, created_at.
 * It is persisted encrypted at rest — see {@see TraktOAuthTokenRepository}.
 */
interface TraktTokenRepositoryInterface
{
    /**
     * @return array<string, mixed>|null the decrypted token payload, or null when none is stored
     */
    public function get(): ?array;

    /**
     * @param array<string, mixed> $token the raw token payload from Trakt
     */
    public function save(array $token): void;
}
