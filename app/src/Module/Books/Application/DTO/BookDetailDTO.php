<?php

declare(strict_types=1);

namespace App\Module\Books\Application\DTO;

final readonly class BookDetailDTO
{
    /**
     * @param ReadingSessionDTO[] $sessions
     */
    public function __construct(
        public BookDTO $book,
        public array $sessions,
    ) {
    }
}
