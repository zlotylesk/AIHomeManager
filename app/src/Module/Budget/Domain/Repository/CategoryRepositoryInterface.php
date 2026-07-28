<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Repository;

use App\Module\Budget\Domain\Entity\Category;

interface CategoryRepositoryInterface
{
    public function save(Category $category): void;

    public function findById(string $id): ?Category;

    /** @return Category[] */
    public function findAll(): array;

    public function remove(Category $category): void;
}
