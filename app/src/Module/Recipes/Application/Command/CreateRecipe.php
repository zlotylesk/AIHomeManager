<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Command;

/**
 * Create a recipe. Primitives only — the handler resolves each ingredient's
 * measurement unit and the aggregate validates the rest (non-empty title,
 * at least one ingredient, servings ≥ 1, step and tag lengths).
 */
final readonly class CreateRecipe
{
    /**
     * @param list<array{name: string, quantity: float, unit: string}> $ingredients
     * @param list<string>                                             $steps
     * @param list<string>                                             $tags
     */
    public function __construct(
        public string $title,
        public array $ingredients,
        public array $steps = [],
        public int $servings = 1,
        public ?int $prepTimeMinutes = null,
        public array $tags = [],
    ) {
    }
}
