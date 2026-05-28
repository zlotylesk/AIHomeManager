<?php

declare(strict_types=1);

namespace App\Module\Music\Application\DTO;

final readonly class ListeningSessionDTO
{
    public function __construct(
        public string $id,
        public string $artist,
        public string $title,
        public string $playedAt,
        public string $source,
        public ?int $playCount,
    ) {
    }
}
