<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\Port;

use App\Module\YouTubeProgress\Application\DTO\VideoMetadata;

interface YouTubePlaylistReaderInterface
{
    /**
     * Fetch every video of a YouTube playlist with its metadata, in playlist order.
     *
     * @return list<VideoMetadata>
     */
    public function fetchPlaylistVideos(string $playlistId): array;
}
