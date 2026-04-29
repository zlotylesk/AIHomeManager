<?php

declare(strict_types=1);

namespace App\Module\Music\Application\DTO;

final readonly class AlbumDTO
{
    public function __construct(
        public string $artist,
        public string $title,
        public int $playCount,
        public ?string $imageUrl,
    ) {}
}
