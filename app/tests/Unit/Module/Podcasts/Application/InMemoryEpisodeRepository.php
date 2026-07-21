<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Application;

use App\Module\Podcasts\Domain\Entity\Episode;
use App\Module\Podcasts\Domain\Repository\EpisodeRepositoryInterface;
use LogicException;

final class InMemoryEpisodeRepository implements EpisodeRepositoryInterface
{
    /** @var array<string, Episode> */
    public array $saved = [];

    /** Counts writes, not rows — an unchanged episode must not be re-saved. */
    public int $writes = 0;

    public function save(Episode $episode): void
    {
        $this->saved[$episode->id()] = $episode;
        ++$this->writes;
    }

    public function findById(string $id): ?Episode
    {
        return $this->saved[$id] ?? null;
    }

    public function findByExternalId(string $externalId): ?Episode
    {
        foreach ($this->saved as $episode) {
            if ($episode->externalId() === $externalId) {
                return $episode;
            }
        }

        return null;
    }

    /** @return Episode[] */
    public function findByPodcastId(string $podcastId): array
    {
        return array_values(array_filter(
            $this->saved,
            static fn (Episode $episode): bool => $episode->podcastId() === $podcastId,
        ));
    }

    public function remove(Episode $episode): void
    {
        unset($this->saved[$episode->id()]);
    }

    public function only(): Episode
    {
        return array_first($this->saved) ?? throw new LogicException('No episode was saved.');
    }
}
