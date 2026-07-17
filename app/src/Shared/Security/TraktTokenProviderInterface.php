<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Shared-kernel contract for reading the stored Trakt.tv OAuth token.
 *
 * One Trakt token backs two bounded contexts: Series (watched shows + ratings
 * import) and Movies (watched movies + ratings import). This read-only port lets
 * the Movies Trakt adapter depend on a Shared abstraction instead of reaching
 * into the Series Infrastructure, keeping the hexagonal boundaries airtight
 * (Infrastructure → Shared, never cross-module) — the same shape as
 * {@see GoogleTokenProviderInterface}. Series extends it with write access via
 * {@see \App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface}.
 */
interface TraktTokenProviderInterface
{
    /**
     * @return array<string, mixed>|null the decrypted token payload
     *                                   (access_token, refresh_token, expires_in, …),
     *                                   or null when none is stored
     */
    public function get(): ?array;
}
