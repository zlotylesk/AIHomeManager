<?php

declare(strict_types=1);

namespace App\Module\Music\Application\DTO;

use DateTimeImmutable;

final readonly class RecentTrackDTO
{
    public function __construct(
        public string $artist,
        public string $album,
        public DateTimeImmutable $playedAt,
        public ?string $mbid = null,
    ) {
    }
}
