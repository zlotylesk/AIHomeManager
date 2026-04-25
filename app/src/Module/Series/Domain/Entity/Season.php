<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Entity;

final class Season
{
    /** @var array<string, Episode> */
    private array $episodes = [];

    public function __construct(
        private readonly string $id,
        private readonly string $seriesId,
        private readonly int $number,
    ) {}

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