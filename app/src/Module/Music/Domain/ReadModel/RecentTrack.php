<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\ReadModel;

use DateTimeImmutable;

/**
 * A recently played track, read by the MusicListeningHistoryInterface port from
 * an external scrobbling source (Last.fm). Consumed when turning scrobbles into
 * local listening sessions.
 */
final readonly class RecentTrack
{
    public function __construct(
        public string $artist,
        public string $album,
        public DateTimeImmutable $playedAt,
        public ?string $mbid = null,
    ) {
    }
}
