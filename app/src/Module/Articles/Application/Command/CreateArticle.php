<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Command;

final readonly class CreateArticle
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $category = null,
        public ?int $estimatedReadTime = null,
    ) {
    }
}
