<?php

declare(strict_types=1);

namespace App\Module\Series\Application\DTO;

final readonly class SeasonDTO
{
    /** @param EpisodeDTO[] $episodes */
    public function __construct(
        public string $id,
        public int $number,
        public array $episodes,
    ) {}
}