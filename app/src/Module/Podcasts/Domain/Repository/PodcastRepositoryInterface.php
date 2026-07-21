<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Repository;

use App\Module\Podcasts\Domain\Entity\Podcast;

interface PodcastRepositoryInterface
{
    public function save(Podcast $podcast): void;

    public function findById(string $id): ?Podcast;

    /**
     * Look a show up by its id at the source. This is what makes the import
     * idempotent: the same show observed on a later poll is recognized instead
     * of being minted a second time under a fresh UUID.
     */
    public function findByExternalId(string $externalId): ?Podcast;

    /** @return Podcast[] */
    public function findAll(): array;

    public function remove(Podcast $podcast): void;
}
