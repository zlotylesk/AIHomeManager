<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Command;

final readonly class UpdateArticle
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $category = null,
        public ?int $estimatedReadTime = null,
    ) {
    }
}
