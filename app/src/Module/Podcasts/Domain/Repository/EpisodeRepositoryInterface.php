<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Repository;

use App\Module\Podcasts\Domain\Entity\Episode;

interface EpisodeRepositoryInterface
{
    public function save(Episode $episode): void;

    public function findById(string $id): ?Episode;

    /** Recognize an episode already imported from the source — see PodcastRepositoryInterface::findByExternalId(). */
    public function findByExternalId(string $externalId): ?Episode;

    /** @return Episode[] */
    public function findByPodcastId(string $podcastId): array;

    public function remove(Episode $episode): void;
}
