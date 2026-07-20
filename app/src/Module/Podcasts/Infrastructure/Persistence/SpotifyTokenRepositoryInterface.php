<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Infrastructure\Persistence;

/**
 * Stores the single Spotify OAuth2 token (single-user system).
 *
 * The payload is what Spotify's token endpoint returns — access_token,
 * refresh_token, token_type, scope, expires_in — plus a `created_at` unix
 * timestamp this application stamps itself: unlike Trakt, Spotify does NOT
 * report when the token was issued, and without an issue time there is nothing
 * to add expires_in to. It is persisted encrypted at rest, see
 * {@see SpotifyOAuthTokenRepository}.
 *
 * Deliberately NOT promoted to the Shared kernel (unlike the Trakt/Google token
 * ports): exactly one bounded context reads this token today. The shared kernel
 * is for contracts that genuinely span contexts — promoting on speculation
 * would put a class there that nobody else references.
 */
interface SpotifyTokenRepositoryInterface
{
    /**
     * @return array<string, mixed>|null the decrypted token payload, or null when none is stored
     */
    public function get(): ?array;

    /**
     * @param array<string, mixed> $token the raw token payload from Spotify
     */
    public function save(array $token): void;
}
