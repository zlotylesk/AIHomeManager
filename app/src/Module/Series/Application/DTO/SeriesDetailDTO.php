<?php

declare(strict_types=1);

namespace App\Module\Series\Application\DTO;

final readonly class SeriesDetailDTO
{
    /**
     * @param list<SeasonDTO> $seasons
     *
     * `averageRating`/`watchedCount`/`episodeCount` are read-model fields computed
     * in the read layer (SeriesRowHydrator), NOT at serialization time (HMAI-242)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $createdAt,
        public array $seasons,
        public ?int $rating = null,
        public ?string $coverUrl = null,
        public ?int $year = null,
        public ?string $status = null,
        public ?string $description = null,
        public ?float $averageRating = null,
        public int $watchedCount = 0,
        public int $episodeCount = 0,
    ) {
    }
}
