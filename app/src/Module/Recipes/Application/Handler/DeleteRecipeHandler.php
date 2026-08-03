<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\DeleteRecipe;
use App\Module\Recipes\Application\Exception\RecipeIsPlannedException;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deleting a recipe that is not there is an error, not a quiet success: this
 * is a user's own explicit delete click, so a second one means they are
 * looking at a stale list and should be told (404) rather than shown a
 * confirmation for something that did not happen. The "succeed either way"
 * shape is reserved for endpoints that legitimately double-fire, such as a
 * browser re-offering a push subscription.
 *
 * A recipe still referenced by the meal plan is refused outright (409). The
 * alternative — deleting the plan entries along with it — would silently edit
 * a plan the user made, and they would notice only when a meal was missing
 * from their week. Blocking is the honest half of the same choice: it says
 * what is in the way, and removing that entry is one action they already know
 * how to perform. See `MealPlanRepositoryInterface` for the full reasoning,
 * including why the shopping list is what makes a dangling reference worse
 * than an inconvenient refusal.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteRecipeHandler
{
    public function __construct(
        private RecipeRepositoryInterface $recipes,
        private MealPlanRepositoryInterface $mealPlan,
    ) {
    }

    public function __invoke(DeleteRecipe $command): void
    {
        $recipe = $this->recipes->findById($command->id);

        if (null === $recipe) {
            throw new RecipeNotFoundException(sprintf('Recipe "%s" not found.', $command->id));
        }

        if ($this->mealPlan->existsForRecipe($command->id)) {
            throw new RecipeIsPlannedException(sprintf('Recipe "%s" cannot be deleted while it is still on the meal plan.', $command->id));
        }

        $this->recipes->remove($recipe);
    }
}
