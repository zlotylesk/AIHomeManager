<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

final readonly class AddEpisode
{
    public function __construct(
        public string $seriesId,
        public string $seasonId,
        public string $title,
        public ?int $rating = null,
    ) {
    }
}
