<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Command;

/**
 * Push a single WatchSession to YouTube as a new playlist.
 *
 * Dispatched synchronously on the command bus from a per-session button in the
 * UI (T13). Idempotent at the aggregate level — a session already pushed is a
 * no-op, so a double-click cannot create a duplicate playlist.
 */
final readonly class PushSessionToYouTube
{
    public function __construct(
        public string $sessionId,
    ) {
    }
}
