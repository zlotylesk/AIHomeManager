<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Port;

use App\Module\Music\Domain\ReadModel\Album;
use App\Module\Music\Domain\ReadModel\RecentTrack;

interface MusicListeningHistoryInterface
{
    /** @return Album[] */
    public function getTopAlbums(string $username, string $period, int $limit): array;

    /** @return RecentTrack[] */
    public function getRecentTracks(string $username, int $limit): array;
}
