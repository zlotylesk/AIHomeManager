<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\ReadModel;

/**
 * A listened-to album, read by the MusicListeningHistoryInterface port from an
 * external scrobbling source (Last.fm). A Domain read model owned by the Music
 * context, mapped to JSON by the Glue serializer.
 */
final readonly class Album
{
    public function __construct(
        public string $artist,
        public string $title,
        public int $playCount,
        public ?string $imageUrl,
    ) {
    }
}
