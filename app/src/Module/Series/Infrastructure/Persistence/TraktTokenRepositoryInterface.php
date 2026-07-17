<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Persistence;

use App\Shared\Security\TraktTokenProviderInterface;

/**
 * Stores the single Trakt.tv OAuth2 token (single-user system).
 *
 * The token is the full payload returned by Trakt's token endpoint:
 * access_token, refresh_token, token_type, scope, expires_in, created_at.
 * It is persisted encrypted at rest — see {@see TraktOAuthTokenRepository}.
 *
 * Extends the Shared-kernel read port {@see TraktTokenProviderInterface} with
 * write access, so the Movies Trakt adapter can read the same token through the
 * Shared abstraction without reaching into the Series Infrastructure (the Tasks
 * GoogleTokenRepositoryInterface / Google precedent).
 */
interface TraktTokenRepositoryInterface extends TraktTokenProviderInterface
{
    /**
     * @param array<string, mixed> $token the raw token payload from Trakt
     */
    public function save(array $token): void;
}
