<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\Entity;

use App\Module\Movies\Domain\ValueObject\Rating;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single film in the collection. Unlike a series a movie has no season/episode
 * hierarchy, so the aggregate is flat: it owns its identity, its title, when it
 * entered the collection, whether it has been watched (and when) and the user's
 * own 1–10 rating.
 *
 * Catalog metadata (cover/year/status/description) is still deliberately absent —
 * it arrives with HMAI-289.
 */
final class Movie
{
    private bool $watched = false;

    private ?DateTimeImmutable $watchedAt = null;

    private ?Rating $userRating = null;

    public function __construct(
        private readonly string $id,
        private Title $title,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Movie id cannot be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): Title
    {
        return $this->title;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(Title $title): void
    {
        $this->title = $title;
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
     * Mark the film as watched. When no timestamp is supplied "now" is stamped
     * (a manual mark); the Trakt import passes the real watched date.
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

    public function userRating(): ?Rating
    {
        return $this->userRating;
    }

    /**
     * Set the user's own rating, or clear it when given null.
     */
    public function rate(?Rating $rating): void
    {
        $this->userRating = $rating;
    }
}
