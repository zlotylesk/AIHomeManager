<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Repository;

use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;

interface CategoryRepositoryInterface
{
    public function save(Category $category): void;

    public function findById(string $id): ?Category;

    /** @return Category[] */
    public function findAll(): array;

    /**
     * Find an existing category by its name within a type (name uniqueness
     * is scoped to the type, not global — an "Ubezpieczenie" income and an
     * "Ubezpieczenie" expense category can coexist).
     */
    public function findByNameAndType(string $name, TransactionType $type): ?Category;

    public function remove(Category $category): void;
}
