<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Repository;

use App\Module\Podcasts\Domain\Entity\Podcast;

interface PodcastRepositoryInterface
{
    public function save(Podcast $podcast): void;

    public function findById(string $id): ?Podcast;

    /** @return Podcast[] */
    public function findAll(): array;

    public function remove(Podcast $podcast): void;
}
