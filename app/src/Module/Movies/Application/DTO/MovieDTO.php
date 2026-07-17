<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\DTO;

/**
 * Read model for a film. A movie is a flat aggregate (no season/episode
 * hierarchy), so the list item and the detail share the same shape. Datetimes
 * are ISO-8601 strings; the optional metadata mirrors the aggregate's nullable
 * fields.
 */
final readonly class MovieDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public bool $watched,
        public ?string $watchedAt,
        public ?int $rating,
        public ?string $coverUrl,
        public ?int $year,
        public ?string $status,
        public ?string $description,
        public string $createdAt,
    ) {
    }
}
