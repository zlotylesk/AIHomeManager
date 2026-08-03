<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * One line of a recipe's ingredient list.
 *
 * The unit travels as its canonical identifier (`g`, `ml`, `tablespoon`), not a
 * Polish label — the same value the shopping list groups on (HMAI-391), so the
 * API stays a stable contract and the labels stay a presentation concern.
 */
final readonly class RecipeIngredientDTO
{
    public function __construct(
        public string $name,
        public float $quantity,
        public string $unit,
    ) {
    }
}
