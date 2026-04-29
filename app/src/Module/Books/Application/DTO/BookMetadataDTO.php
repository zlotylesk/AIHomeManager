<?php

declare(strict_types=1);

namespace App\Module\Books\Application\DTO;

final readonly class BookMetadataDTO
{
    public function __construct(
        public string $title,
        public ?string $author,
        public ?string $publisher,
        public ?int $year,
        public ?int $totalPages,
        public ?string $coverUrl,
    ) {}
}
