<?php

declare(strict_types=1);

namespace App\Module\Recipes\Domain\Entity;

use App\Module\Recipes\Domain\Enum\MealSlot;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One recipe planned for one slot of one day.
 *
 * A slot deliberately holds a LIST of these rather than a single recipe. The
 * ticket offered both, but they are not symmetric in cost: a Polish "obiad" is
 * routinely soup plus a main course, so one-recipe-per-slot would refuse an
 * entirely ordinary plan and leave the user with no move except dropping a dish
 * or inventing a fake slot.
 *
 * Dropping the duplicate rule altogether was not the alternative either — a
 * double-clicked "add to plan" would then produce two identical entries, and
 * the shopping list (HMAI-391) would quietly buy every ingredient twice while
 * the calendar still looked plausible. So the rule is kept and re-aimed at what
 * is actually a duplicate: the same recipe, twice, in the same slot on the same
 * day. Two different recipes in one slot are a menu, not a duplicate.
 *
 * Enforcing that spans rows, so it cannot live on the aggregate: the guard is
 * `MealPlanRepositoryInterface::existsFor()` plus a unique index on
 * (date, slot, recipe_id), and the readable error is the write handler's job
 * (HMAI-390) — the Budget `CategoryNameAlreadyTaken` split.
 *
 * `servings` is how many portions the user plans to cook, which is NOT
 * necessarily what the recipe yields: the shopping list scales each ingredient
 * by plannedServings / recipe.servings, which is the whole reason this field
 * exists on the plan rather than being read off the recipe.
 *
 * The aggregate carries no mutator yet, so Rector collapses it to a readonly
 * class; HMAI-390's `MoveMeal` reopens it, the same way HMAI-387's `update()`
 * reopened `Recipe`.
 */
final readonly class PlannedMeal
{
    private DateTimeImmutable $date;

    public function __construct(
        private string $id,
        DateTimeImmutable $date,
        private MealSlot $slot,
        private string $recipeId,
        private int $servings,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Planned meal id cannot be empty.');
        }

        if ('' === trim($recipeId)) {
            throw new InvalidArgumentException('A planned meal must reference a recipe.');
        }

        if ($servings < 1) {
            throw new InvalidArgumentException('Planned servings must be at least 1.');
        }

        // Normalised to midnight so two meals planned for the same day compare
        // equal in PHP. The database is not the reason — a DATE column drops
        // the time either way — but everything above it (grouping a week into
        // days, moving a meal to "the same day") would otherwise be comparing
        // whatever time of day the caller happened to construct the date at.
        $this->date = $date->setTime(0, 0);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function slot(): MealSlot
    {
        return $this->slot;
    }

    public function recipeId(): string
    {
        return $this->recipeId;
    }

    public function servings(): int
    {
        return $this->servings;
    }
}
