<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Command;

final class CreateArticle
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $category = null,
        public readonly ?int $estimatedReadTime = null,
    ) {}
}
