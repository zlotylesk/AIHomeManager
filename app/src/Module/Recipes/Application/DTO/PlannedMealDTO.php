<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * One planned meal as the calendar renders it.
 *
 * `recipeTitle` is nullable even though a recipe on the plan cannot normally be
 * deleted (`DeleteRecipeHandler` refuses). The read is a LEFT JOIN rather than
 * an INNER JOIN because the two failure modes are not equally bad: a row whose
 * recipe went missing anyway — a hand-edited database, a future import — would
 * be dropped entirely by an inner join, so the card would vanish from the
 * calendar while still occupying its slot, and re-planning the same recipe
 * would then be refused as a conflict with something invisible. A null title
 * renders as a placeholder and can be removed; a silently absent row cannot.
 */
final readonly class PlannedMealDTO
{
    public function __construct(
        public string $id,
        public string $recipeId,
        public ?string $recipeTitle,
        public int $servings,
    ) {
    }
}
