<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Entity;

use App\Module\Series\Domain\Event\EpisodeRated;
use App\Module\Series\Domain\ValueObject\Rating;
use DateTimeImmutable;
use DomainException;

final class Series
{
    /** @var array<string, Season> */
    private array $seasons = [];

    /** @var object[] */
    private array $recordedEvents = [];

    /**
     * The user's own, subjective whole-series score — independent of (and never
     * overwritten by) the average derived from episode ratings (HMAI-179).
     */
    private ?Rating $rating = null;

    /**
     * Stable dedup key from Trakt (the show's numeric id, stored as a string).
     * Null for manually-added series; set only by the Trakt import so a re-import
     * matches on this id rather than on fragile title comparison (HMAI-182).
     */
    private ?string $traktId = null;

    public function __construct(
        private readonly string $id,
        private readonly string $title,
        private readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function traktId(): ?string
    {
        return $this->traktId;
    }

    /**
     * Link this series to its Trakt show. Idempotent re-imports rely on this id;
     * an empty value would defeat dedup, so reject it.
     */
    public function linkTrakt(string $traktId): void
    {
        if ('' === trim($traktId)) {
            throw new DomainException('Trakt id cannot be empty.');
        }

        $this->traktId = $traktId;
    }

    public function rating(): ?Rating
    {
        return $this->rating;
    }

    public function rate(Rating $rating): void
    {
        $this->rating = $rating;
    }

    /**
     * Drop the user's own whole-series score, reverting to "no manual rating,
     * show only the episode-derived average" (HMAI-191). The average is
     * untouched — it lives on the episodes, not here.
     */
    public function clearRating(): void
    {
        $this->rating = null;
    }

    public function rateSeason(string $seasonId, Rating $rating): void
    {
        if (!isset($this->seasons[$seasonId])) {
            throw new DomainException(sprintf('Season "%s" not found in series "%s".', $seasonId, $this->id));
        }

        $this->seasons[$seasonId]->rate($rating);
    }

    public function clearSeasonRating(string $seasonId): void
    {
        if (!isset($this->seasons[$seasonId])) {
            throw new DomainException(sprintf('Season "%s" not found in series "%s".', $seasonId, $this->id));
        }

        $this->seasons[$seasonId]->clearRating();
    }

    /** @return array<string, Season> */
    public function seasons(): array
    {
        return $this->seasons;
    }

    public function addSeason(Season $season): void
    {
        $this->seasons[$season->id()] = $season;
    }

    public function addEpisode(string $seasonId, Episode $episode): void
    {
        if (!isset($this->seasons[$seasonId])) {
            throw new DomainException(sprintf('Season "%s" not found in series "%s".', $seasonId, $this->id));
        }

        $this->seasons[$seasonId]->addEpisode($episode);
    }

    public function rateEpisode(string $seasonId, string $episodeId, Rating $rating): void
    {
        if (!isset($this->seasons[$seasonId])) {
            throw new DomainException(sprintf('Season "%s" not found in series "%s".', $seasonId, $this->id));
        }

        $episode = $this->seasons[$seasonId]->findEpisode($episodeId);
        if (null === $episode) {
            throw new DomainException(sprintf('Episode "%s" not found in season "%s" of series "%s".', $episodeId, $seasonId, $this->id));
        }

        $episode->rate($rating);
        $this->recordedEvents[] = new EpisodeRated(
            seriesId: $this->id,
            seasonId: $seasonId,
            episodeId: $episodeId,
            rating: $rating->value(),
        );
    }

    /**
     * Toggle an episode's watched flag (HMAI-188). No domain event — nothing
     * subscribes to it yet (YAGNI). The optional timestamp lets the Trakt import
     * preserve the real watched-at date; a manual toggle defaults to now.
     */
    public function setEpisodeWatched(string $seasonId, string $episodeId, bool $watched, ?DateTimeImmutable $watchedAt = null): void
    {
        if (!isset($this->seasons[$seasonId])) {
            throw new DomainException(sprintf('Season "%s" not found in series "%s".', $seasonId, $this->id));
        }

        $episode = $this->seasons[$seasonId]->findEpisode($episodeId);
        if (null === $episode) {
            throw new DomainException(sprintf('Episode "%s" not found in season "%s" of series "%s".', $episodeId, $seasonId, $this->id));
        }

        if ($watched) {
            $episode->markWatched($watchedAt);
        } else {
            $episode->unmarkWatched();
        }
    }

    /** @return object[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
