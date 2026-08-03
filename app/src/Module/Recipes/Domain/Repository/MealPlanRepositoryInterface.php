<?php

declare(strict_types=1);

namespace App\Module\Recipes\Domain\Repository;

use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Enum\MealSlot;
use DateTimeImmutable;

/**
 * The meal plan's write-side port.
 *
 * There is deliberately no `findAll()` or date-range finder here: reading the
 * calendar is a query, and queries in this project go through DBAL (HMAI-390's
 * `GetMealPlan`), not through the repository. What the write side needs is the
 * ability to load one meal, store it, drop it, and answer the one question the
 * aggregate structurally cannot — whether this recipe is already planned for
 * this slot.
 *
 * Open point for HMAI-390: nothing yet stops a recipe from being deleted while
 * meals still point at it, which would leave the calendar showing an entry it
 * cannot name. The two honest options are to block the delete (the Budget
 * category precedent) or to drop the plan entries with it; either way it is a
 * decision for the ticket that owns the write handlers, not an accident to
 * discover later.
 */
interface MealPlanRepositoryInterface
{
    public function save(PlannedMeal $meal): void;

    public function findById(string $id): ?PlannedMeal;

    /**
     * Whether this recipe is already planned for this date and slot.
     *
     * `$excludingId` exists for the move: relocating a meal onto its own
     * current position must be a no-op success rather than a conflict with
     * itself — the same self-exclusion Budget's category rename needs.
     */
    public function existsFor(
        DateTimeImmutable $date,
        MealSlot $slot,
        string $recipeId,
        ?string $excludingId = null,
    ): bool;

    public function remove(PlannedMeal $meal): void;
}
