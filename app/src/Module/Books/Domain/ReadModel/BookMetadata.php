<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\ReadModel;

/**
 * Catalog metadata for a book, fetched by the BookMetadataProviderInterface port
 * from an external bibliographic source (National Library). A Domain read model:
 * the port contract belongs to the Domain, so its return type does too.
 */
final readonly class BookMetadata
{
    public function __construct(
        public string $title,
        public ?string $author,
        public ?string $publisher,
        public ?int $year,
        public ?int $totalPages,
        public ?string $coverUrl,
    ) {
    }
}
