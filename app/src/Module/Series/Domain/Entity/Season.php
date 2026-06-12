<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Entity;

use App\Module\Series\Domain\ValueObject\Rating;

final class Season
{
    /** @var array<string, Episode> */
    private array $episodes = [];

    /**
     * The user's own, subjective season score — independent of (and never
     * overwritten by) the average derived from episode ratings (HMAI-179).
     */
    private ?Rating $rating = null;

    public function __construct(
        private readonly string $id,
        private readonly string $seriesId,
        private readonly int $number,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function seriesId(): string
    {
        return $this->seriesId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function rating(): ?Rating
    {
        return $this->rating;
    }

    public function rate(Rating $rating): void
    {
        $this->rating = $rating;
    }

    public function clearRating(): void
    {
        $this->rating = null;
    }

    /** @return array<string, Episode> */
    public function episodes(): array
    {
        return $this->episodes;
    }

    public function addEpisode(Episode $episode): void
    {
        $this->episodes[$episode->id()] = $episode;
    }

    public function findEpisode(string $episodeId): ?Episode
    {
        return $this->episodes[$episodeId] ?? null;
    }
}
