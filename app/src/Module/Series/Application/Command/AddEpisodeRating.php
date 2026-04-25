<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

final readonly class AddEpisodeRating
{
    public function __construct(
        public string $seriesId,
        public string $seasonId,
        public string $episodeId,
        public int $rating,
    ) {}
}