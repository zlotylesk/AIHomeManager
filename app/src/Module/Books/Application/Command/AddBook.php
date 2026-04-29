<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Command;

final readonly class AddBook
{
    public function __construct(
        public string $isbn,
        public ?string $title = null,
        public ?string $author = null,
        public ?string $publisher = null,
        public ?int $year = null,
        public ?string $coverUrl = null,
        public ?int $totalPages = null,
    ) {}
}
