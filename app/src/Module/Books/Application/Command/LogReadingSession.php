<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Command;

final readonly class LogReadingSession
{
    public function __construct(
        public string $bookId,
        public int $pagesRead,
        public string $date,
        public ?string $notes = null,
    ) {
    }
}
