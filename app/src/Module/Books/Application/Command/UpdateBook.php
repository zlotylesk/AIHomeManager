<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Command;

use App\Module\Books\Domain\ValueObject\CoverUrl;

final readonly class UpdateBook
{
    public function __construct(
        public string $id,
        public string $title,
        public string $author,
        public string $publisher,
        public int $year,
        public ?CoverUrl $coverUrl,
    ) {
    }
}
