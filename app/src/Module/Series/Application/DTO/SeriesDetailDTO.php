<?php

declare(strict_types=1);

namespace App\Module\Series\Application\DTO;

final readonly class SeriesDetailDTO
{
    /** @param SeasonDTO[] $seasons */
    public function __construct(
        public string $id,
        public string $title,
        public string $createdAt,
        public array $seasons,
    ) {}
}