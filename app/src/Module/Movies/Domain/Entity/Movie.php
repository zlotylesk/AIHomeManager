<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\Entity;

use App\Module\Movies\Domain\Enum\MovieStatus;
use App\Module\Movies\Domain\ValueObject\Rating;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single film in the collection. Unlike a series a movie has no season/episode
 * hierarchy, so the aggregate is flat: it owns its identity, its title, when it
 * entered the collection, whether it has been watched (and when), the user's own
 * 1–10 rating and optional catalog metadata (cover/year/status/description).
 *
 * The metadata is stored as already-validated primitives — the cover URL is
 * validated through the shared CoverUrl VO, the year range, the status enum and
 * the description length at the Application boundary (MovieMetadata), so this
 * only stores (the Series updateMetadata precedent).
 */
final class Movie
{
    private bool $watched = false;

    private ?DateTimeImmutable $watchedAt = null;

    private ?Rating $userRating = null;

    private ?string $coverUrl = null;

    private ?int $year = null;

    private ?MovieStatus $status = null;

    private ?string $description = null;

    private ?string $traktId = null;

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

    public function coverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function year(): ?int
    {
        return $this->year;
    }

    public function status(): ?MovieStatus
    {
        return $this->status;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * Replace the catalog metadata (full replace — a null field clears it). The
     * values are already validated by MovieMetadata at the Application boundary.
     */
    public function updateMetadata(?string $coverUrl, ?int $year, ?MovieStatus $status, ?string $description): void
    {
        $this->coverUrl = $coverUrl;
        $this->year = $year;
        $this->status = $status;
        $this->description = $description;
    }

    public function traktId(): ?string
    {
        return $this->traktId;
    }

    /**
     * Link this movie to its Trakt film. Idempotent re-imports dedupe on this id
     * (the Series linkTrakt precedent).
     */
    public function linkTrakt(string $traktId): void
    {
        if ('' === trim($traktId)) {
            throw new InvalidArgumentException('Trakt id cannot be empty.');
        }

        $this->traktId = $traktId;
    }
}
