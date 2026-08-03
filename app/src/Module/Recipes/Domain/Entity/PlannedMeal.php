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
 * `moveTo()` is the aggregate's only mutator, so `date` and `slot` are the only
 * fields that stopped being readonly — which is exactly the shape of a move.
 * The recipe and the servings are deliberately not editable here: changing
 * either would make the entry a different plan rather than the same plan on a
 * different day, and there is no history to preserve, so re-planning says that
 * more clearly than a general-purpose update would.
 */
final class PlannedMeal
{
    private DateTimeImmutable $date;

    public function __construct(
        private readonly string $id,
        DateTimeImmutable $date,
        private MealSlot $slot,
        private readonly string $recipeId,
        private readonly int $servings,
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

    /**
     * Relocate this meal to another day and/or slot.
     *
     * The date is normalised here exactly as the constructor normalises it —
     * not by delegating, because a caller handing over "next Tuesday" straight
     * from a clock carries a time of day, and a moved meal that compared
     * unequal to a freshly planned one on the same day would be the same bug
     * the constructor already guards against, reintroduced through the back
     * door.
     *
     * Whether the destination is already taken is not checked here: the answer
     * lives in other rows, which an aggregate cannot see. The write handler
     * asks the repository first (HMAI-390) and the unique index is the
     * backstop.
     */
    public function moveTo(DateTimeImmutable $date, MealSlot $slot): void
    {
        $this->date = $date->setTime(0, 0);
        $this->slot = $slot;
    }
}
