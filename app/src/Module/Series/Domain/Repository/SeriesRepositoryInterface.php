<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Repository;

use App\Module\Series\Domain\Entity\Series;

interface SeriesRepositoryInterface
{
    public function save(Series $series): void;

    public function findById(string $id): ?Series;

    /**
     * Looks up a series by its Trakt dedup key (HMAI-182). Used by the import to
     * decide create-vs-update; returns null for unknown ids and never matches
     * manually-added series (whose trakt_id is NULL).
     */
    public function findByTraktId(string $traktId): ?Series;

    /** @return Series[] */
    public function findAll(): array;
}
