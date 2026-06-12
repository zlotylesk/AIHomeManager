<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

final readonly class SetEpisodeWatched
{
    public function __construct(
        public string $seriesId,
        public string $seasonId,
        public string $episodeId,
        public bool $watched,
    ) {
    }
}
