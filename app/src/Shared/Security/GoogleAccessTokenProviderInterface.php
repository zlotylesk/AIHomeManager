<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Shared-kernel contract for a *valid*, ready-to-use Google OAuth access token.
 *
 * Unlike {@see GoogleTokenProviderInterface} (a raw read of whatever is
 * stored), this port hands back a token that is guaranteed usable right now —
 * refreshing it via the OAuth2 refresh_token grant when the stored one has
 * expired, and persisting the refreshed payload. Both Tasks (Calendar) and
 * YouTubeProgress consume it so neither has to duplicate refresh-on-expiry
 * logic, and freshness no longer depends on which module happened to touch
 * the shared token most recently (HMAI-399). Tasks Infrastructure implements
 * it (it already owns the Google\Client + the token repository); the
 * YouTubeProgress adapter depends on this Shared abstraction only, keeping
 * the hexagonal boundaries airtight (Infrastructure → Shared, never
 * cross-module) — the same shape as {@see TraktTokenProviderInterface}'s
 * `getValidAccessToken()`.
 */
interface GoogleAccessTokenProviderInterface
{
    /**
     * @return string|null a valid access token, silently refreshed if the
     *                      stored one had expired, or null when there is no
     *                      token / no refresh token / the refresh failed
     */
    public function getValidAccessToken(): ?string;
}
