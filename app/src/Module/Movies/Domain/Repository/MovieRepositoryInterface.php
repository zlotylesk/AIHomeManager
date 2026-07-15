<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\Repository;

use App\Module\Movies\Domain\Entity\Movie;

interface MovieRepositoryInterface
{
    public function save(Movie $movie): void;

    public function findById(string $id): ?Movie;

    /** @return Movie[] */
    public function findAll(): array;

    public function remove(Movie $movie): void;
}
