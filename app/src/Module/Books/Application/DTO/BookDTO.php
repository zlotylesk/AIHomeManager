<?php

declare(strict_types=1);

namespace App\Module\Books\Application\DTO;

final readonly class BookDTO
{
    public function __construct(
        public string $id,
        public string $isbn,
        public string $title,
        public string $author,
        public string $publisher,
        public int $year,
        public ?string $coverUrl,
        public int $totalPages,
        public int $currentPage,
        public float $percentage,
        public string $status,
    ) {
    }
}
