<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\Port;

use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;

interface YouTubePlaylistWriterInterface
{
    /**
     * Create a new YouTube playlist and return its playlist ID. Private by
     * default so session playlists don't flood the user's public channel.
     */
    public function createPlaylist(string $name, bool $private = true): string;

    /**
     * Append videos to a playlist, one request per video, in the given order so
     * the playlist preserves the session's video sequence.
     *
     * @param list<YoutubeVideoId> $videoIds
     */
    public function addVideosToPlaylist(string $playlistId, array $videoIds): void;
}
