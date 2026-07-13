<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Domain\ReadModel;

use DateTimeImmutable;

/**
 * The most recent listening activity, normalized from the Music module's
 * listening sessions. `source` carries the Music module's stable serialized
 * source value as a plain string (no coupling to the Music enum).
 */
final readonly class RecentTrack
{
    public function __construct(
        public string $artist,
        public string $title,
        public DateTimeImmutable $playedAt,
        public string $source,
    ) {
    }
}
