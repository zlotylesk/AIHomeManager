<?php

declare(strict_types=1);

namespace App\Module\Series\Application\DTO;

final readonly class SeasonDTO
{
    /**
     * @param list<EpisodeDTO> $episodes
     *
     * `averageRating`/`watchedCount`/`episodeCount` are read-model fields computed
     * in the read layer (SeriesRowHydrator), NOT at serialization time (HMAI-242)
     */
    public function __construct(
        public string $id,
        public int $number,
        public array $episodes,
        public ?int $rating = null,
        public ?float $averageRating = null,
        public int $watchedCount = 0,
        public int $episodeCount = 0,
    ) {
    }
}
