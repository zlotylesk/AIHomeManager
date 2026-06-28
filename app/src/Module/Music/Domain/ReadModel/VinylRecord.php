<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\ReadModel;

/**
 * An owned vinyl record, read by the VinylCollectionInterface port from an
 * external collection source (Discogs). A Domain read model owned by the Music
 * context, mapped to JSON by the Glue serializer.
 */
final readonly class VinylRecord
{
    public function __construct(
        public string $artist,
        public string $title,
        public ?int $year,
        public string $format,
        public int $discogsId,
    ) {
    }
}
