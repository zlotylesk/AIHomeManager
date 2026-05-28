<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Port;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\RecentTrackDTO;

interface MusicListeningHistoryInterface
{
    /** @return AlbumDTO[] */
    public function getTopAlbums(string $username, string $period, int $limit): array;

    /** @return RecentTrackDTO[] */
    public function getRecentTracks(string $username, int $limit): array;
}
