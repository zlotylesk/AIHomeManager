<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Application;

use App\Module\Podcasts\Domain\Entity\Podcast;
use App\Module\Podcasts\Domain\Repository\PodcastRepositoryInterface;
use LogicException;

/**
 * Hand-written rather than an anonymous class so PHPStan can see the stored
 * shows behind the interface type hint (the InMemorySpotifyTokenRepository
 * precedent).
 */
final class InMemoryPodcastRepository implements PodcastRepositoryInterface
{
    /** @var array<string, Podcast> */
    public array $saved = [];

    /** Counts writes, not rows — an unchanged show must not be re-saved. */
    public int $writes = 0;

    public function save(Podcast $podcast): void
    {
        $this->saved[$podcast->id()] = $podcast;
        ++$this->writes;
    }

    public function findById(string $id): ?Podcast
    {
        return $this->saved[$id] ?? null;
    }

    public function findByExternalId(string $externalId): ?Podcast
    {
        foreach ($this->saved as $podcast) {
            if ($podcast->externalId() === $externalId) {
                return $podcast;
            }
        }

        return null;
    }

    /** @return Podcast[] */
    public function findAll(): array
    {
        return array_values($this->saved);
    }

    public function remove(Podcast $podcast): void
    {
        unset($this->saved[$podcast->id()]);
    }

    public function only(): Podcast
    {
        return array_first($this->saved) ?? throw new LogicException('No podcast was saved.');
    }
}
