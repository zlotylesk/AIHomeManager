<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Command;

/**
 * Synchronise the local watchlist (videos table) with a YouTube playlist.
 *
 * Dispatched synchronously on the command bus from a controller (T13) or CLI.
 * The handler upserts every playlist video, refreshing metadata on known videos
 * while preserving their started/watched state — re-sync never pulls an already
 * started or watched video back into the split pool (YouTubeProgress epic invariant).
 */
final readonly class SyncWatchlist
{
    public function __construct(
        public string $playlistId,
    ) {
    }
}
