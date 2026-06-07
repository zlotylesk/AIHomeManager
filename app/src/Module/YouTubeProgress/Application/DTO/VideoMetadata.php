<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\DTO;

use DateTimeImmutable;

/**
 * Read model for a single playlist video, assembled by the YouTube API adapter
 * from a playlistItems entry (youtubeId, publishedAt) enriched with the matching
 * videos.list record (title, channel, duration).
 *
 * `publishedAt` is the playlist-item timestamp — when the video was added to the
 * watchlist — not the video's own upload date.
 */
final readonly class VideoMetadata
{
    public function __construct(
        public string $youtubeId,
        public string $title,
        public string $channel,
        public int $durationSeconds,
        public DateTimeImmutable $publishedAt,
    ) {
    }
}
