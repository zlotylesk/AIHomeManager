<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\DTO;

/**
 * Read model for a watch session and its ordered videos.
 */
final readonly class WatchSessionDTO
{
    /**
     * @param list<VideoDTO> $videos
     */
    public function __construct(
        public string $id,
        public string $createdAt,
        public int $totalDurationSeconds,
        public ?string $youtubePlaylistId,
        public array $videos,
    ) {
    }
}
