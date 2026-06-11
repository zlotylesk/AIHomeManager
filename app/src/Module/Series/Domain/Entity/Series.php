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

    public function rating(): ?Rating
    {
        return $this->rating;
    }

    public function rate(Rating $rating): void
    {
        $this->rating = $rating;
    }

    public function rateSeason(string $seasonId, Rating $rating): void
    {
        if (!isset($this->seasons[$seasonId])) {
            throw new DomainException(sprintf('Season "%s" not found in series "%s".', $seasonId, $this->id));
        }

        $this->seasons[$seasonId]->rate($rating);
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

    /** @return object[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
