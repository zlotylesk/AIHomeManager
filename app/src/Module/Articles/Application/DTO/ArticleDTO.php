<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\DTO;

use RuntimeException;

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

    /**
     * @param array{
     *     id?: string,
     *     title?: string,
     *     url?: string,
     *     category?: string|null,
     *     estimated_read_time?: int|string|null,
     *     added_at?: string,
     *     read_at?: string|null,
     *     is_read?: int|bool
     * } $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::requireString($row, 'id'),
            title: self::requireString($row, 'title'),
            url: self::requireString($row, 'url'),
            category: isset($row['category']) ? (string) $row['category'] : null,
            estimatedReadTime: isset($row['estimated_read_time']) ? (int) $row['estimated_read_time'] : null,
            addedAt: self::requireString($row, 'added_at'),
            readAt: isset($row['read_at']) ? (string) $row['read_at'] : null,
            isRead: (bool) ($row['is_read'] ?? false),
        );
    }

    /** @param array<string, mixed> $row */
    private static function requireString(array $row, string $column): string
    {
        if (!isset($row[$column])) {
            throw new RuntimeException(sprintf('ArticleDTO::fromRow missing required column "%s".', $column));
        }

        return (string) $row[$column];
    }
}
