<?php

declare(strict_types=1);

namespace App\Module\Books\Application\DTO;

final readonly class ReadingSessionDTO
{
    public function __construct(
        public string $id,
        public string $date,
        public int $pagesRead,
        public ?string $notes,
    ) {
    }
}
