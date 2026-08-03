<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\PlanMeal;
use App\Module\Recipes\Application\Exception\MealAlreadyPlannedException;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Application\MealPlacementInput;
use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Both checks here span two aggregates, which is why they sit in the handler
 * rather than on `PlannedMeal`: whether the recipe exists and whether the slot
 * already holds it are questions about other rows, and a Domain entity must not
 * reach for a repository to answer them (the Budget
 * `TransactionCategoryMatch` reasoning).
 *
 * They also run in this order on purpose. A plan entry pointing at a recipe
 * that was never there is the more fundamental problem — it would render as a
 * nameless card and contribute nothing to the shopping list — so it is reported
 * as a missing recipe (404) rather than being allowed to reach a conflict check
 * that could only ever answer "no".
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class PlanMealHandler
{
    public function __construct(
        private MealPlanRepositoryInterface $mealPlan,
        private RecipeRepositoryInterface $recipes,
    ) {
    }

    public function __invoke(PlanMeal $command): string
    {
        $placement = MealPlacementInput::fromRaw($command->date, $command->slot);

        if (null === $this->recipes->findById($command->recipeId)) {
            throw new RecipeNotFoundException(sprintf('Recipe "%s" not found.', $command->recipeId));
        }

        if ($this->mealPlan->existsFor($placement->date, $placement->slot, $command->recipeId)) {
            throw new MealAlreadyPlannedException(sprintf('Recipe "%s" is already planned for %s (%s).', $command->recipeId, $placement->date->format('Y-m-d'), $placement->slot->value));
        }

        $meal = new PlannedMeal(
            Uuid::v4()->toRfc4122(),
            $placement->date,
            $placement->slot,
            $command->recipeId,
            $command->servings,
        );

        $this->mealPlan->save($meal);

        return $meal->id();
    }
}
