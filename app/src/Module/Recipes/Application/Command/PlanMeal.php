<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Command;

/**
 * Put a recipe on the calendar for one slot of one day.
 *
 * Primitives only: the handler resolves the date and slot through
 * `MealPlacementInput` and the aggregate validates the servings.
 */
final readonly class PlanMeal
{
    public function __construct(
        public string $date,
        public string $slot,
        public string $recipeId,
        public int $servings = 1,
    ) {
    }
}
