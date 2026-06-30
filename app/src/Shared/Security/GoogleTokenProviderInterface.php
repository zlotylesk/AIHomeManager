<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Shared-kernel contract for reading the stored Google OAuth token.
 *
 * One Google token backs two bounded contexts: Tasks (Calendar) and
 * YouTubeProgress (YouTube Data API). This read-only port lets the YouTube
 * adapter depend on a Shared abstraction instead of reaching into the Tasks
 * Infrastructure, keeping the hexagonal boundaries airtight
 * (Infrastructure → Shared, never cross-module). Tasks extends it with write
 * access via {@see \App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface}.
 */
interface GoogleTokenProviderInterface
{
    /**
     * @return array<string, mixed>|null Decoded Google OAuth token payload
     *                                   (access_token, refresh_token, expires_in, …),
     *                                   or null when none is stored
     */
    public function get(): ?array;
}
