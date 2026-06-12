<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Entity;

use App\Module\Series\Domain\ValueObject\Rating;
use DateTimeImmutable;

final class Episode
{
    private ?Rating $rating = null;

    /**
     * Whether the user has watched this episode (HMAI-188) — the core tracker
     * flag. Also the target the Trakt import writes to (HMAI-183).
     */
    private bool $watched = false;

    private ?DateTimeImmutable $watchedAt = null;

    public function __construct(
        private readonly string $id,
        private readonly string $seasonId,
        private string $title,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function seasonId(): string
    {
        return $this->seasonId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function rating(): ?Rating
    {
        return $this->rating;
    }

    public function rate(Rating $rating): void
    {
        $this->rating = $rating;
    }

    public function isWatched(): bool
    {
        return $this->watched;
    }

    public function watchedAt(): ?DateTimeImmutable
    {
        return $this->watchedAt;
    }

    /**
     * Mark this episode watched. The timestamp defaults to now for a manual
     * toggle; the Trakt import passes the actual watched-at date it received.
     */
    public function markWatched(?DateTimeImmutable $watchedAt = null): void
    {
        $this->watched = true;
        $this->watchedAt = $watchedAt ?? new DateTimeImmutable();
    }

    public function unmarkWatched(): void
    {
        $this->watched = false;
        $this->watchedAt = null;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }
}
