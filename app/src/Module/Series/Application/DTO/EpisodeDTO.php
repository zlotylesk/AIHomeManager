<?php

declare(strict_types=1);

namespace App\Module\Series\Application\DTO;

final readonly class EpisodeDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public ?int $rating,
    ) {}
}