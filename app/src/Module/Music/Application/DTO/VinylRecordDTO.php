<?php

declare(strict_types=1);

namespace App\Module\Music\Application\DTO;

final readonly class VinylRecordDTO
{
    public function __construct(
        public string $artist,
        public string $title,
        public ?int $year,
        public string $format,
        public int $discogsId,
    ) {
    }
}
