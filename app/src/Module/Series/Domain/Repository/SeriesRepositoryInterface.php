<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Repository;

use App\Module\Series\Domain\Entity\Series;

interface SeriesRepositoryInterface
{
    public function save(Series $series): void;

    public function findById(string $id): ?Series;

    /** @return Series[] */
    public function findAll(): array;
}
