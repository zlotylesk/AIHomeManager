<?php

declare(strict_types=1);

namespace App\Module\Recipes\Domain\Repository;

use App\Module\Recipes\Domain\Entity\Recipe;

interface RecipeRepositoryInterface
{
    public function save(Recipe $recipe): void;

    public function findById(string $id): ?Recipe;

    /** @return Recipe[] */
    public function findAll(): array;

    public function remove(Recipe $recipe): void;
}
