<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\DTO;

final readonly class ArticleDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public ?string $category,
        public ?int $estimatedReadTime,
        public string $addedAt,
        public ?string $readAt,
        public bool $isRead,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            title: $row['title'],
            url: $row['url'],
            category: $row['category'] ?? null,
            estimatedReadTime: isset($row['estimated_read_time']) && null !== $row['estimated_read_time']
                ? (int) $row['estimated_read_time']
                : null,
            addedAt: $row['added_at'],
            readAt: $row['read_at'] ?? null,
            isRead: (bool) $row['is_read'],
        );
    }
}
