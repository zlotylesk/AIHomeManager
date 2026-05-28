<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Entity;

use App\Module\Music\Domain\Enum\ListeningSource;
use App\Module\Music\Domain\ValueObject\AlbumArtist;
use App\Module\Music\Domain\ValueObject\AlbumTitle;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Local, user-owned record of a single album play. Authoritative play history
 * that survives Last.fm/Discogs going away (HMAI-144) — unlike the Redis-cached
 * top-albums read path, which is purely a derived view of an external service.
 *
 * Immutable: it is only ever constructed and persisted. Reads go through DBAL
 * (GetListeningHistoryHandler), never ORM hydration, so `readonly` is safe.
 */
final readonly class ListeningSession
{
    private string $dedupHash;
    private DateTimeImmutable $createdAt;

    public function __construct(
        private string $id,
        private AlbumArtist $artist,
        private AlbumTitle $title,
        private DateTimeImmutable $playedAt,
        private ListeningSource $source,
        private ?int $playCount = null,
    ) {
        $this->dedupHash = self::computeDedupHash($artist, $title, $playedAt, $source);
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function artist(): AlbumArtist
    {
        return $this->artist;
    }

    public function title(): AlbumTitle
    {
        return $this->title;
    }

    public function playedAt(): DateTimeImmutable
    {
        return $this->playedAt;
    }

    public function source(): ListeningSource
    {
        return $this->source;
    }

    public function playCount(): ?int
    {
        return $this->playCount;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Stable identity for duplicate detection across repeated Last.fm polls.
     * Same album played at the same second from the same source is the same
     * scrobble — second resolution (not minute) avoids merging two genuine
     * plays of one album within a minute (HMAI-144 pitfall 2).
     */
    public function dedupHash(): string
    {
        return $this->dedupHash;
    }

    public static function computeDedupHash(
        AlbumArtist $artist,
        AlbumTitle $title,
        DateTimeImmutable $playedAt,
        ListeningSource $source,
    ): string {
        return hash('sha256', implode('|', [
            mb_strtolower($artist->value(), 'UTF-8'),
            mb_strtolower($title->value(), 'UTF-8'),
            $playedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $source->value,
        ]));
    }

    public function equals(self $other): bool
    {
        return $this->dedupHash === $other->dedupHash;
    }
}
