<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Domain\ReadModel;

/**
 * The article-of-the-day fragment, normalized from the Articles module's daily
 * pick. `isRead` lets the cockpit show whether today's article was already read.
 */
final readonly class DailyArticle
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $category,
        public ?int $estimatedReadTime,
        public bool $isRead,
    ) {
    }
}
