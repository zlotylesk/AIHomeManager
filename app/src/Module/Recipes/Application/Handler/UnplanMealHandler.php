<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\UnplanMeal;
use App\Module\Recipes\Application\Exception\PlannedMealNotFoundException;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Unplanning something that is not on the calendar is an error, not a quiet
 * success — the same rule `DeleteRecipeHandler` follows. The caller clicked a
 * card it could see, so a miss means the plan moved under them and they should
 * be told rather than shown a confirmation for a removal that never happened.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class UnplanMealHandler
{
    public function __construct(private MealPlanRepositoryInterface $mealPlan)
    {
    }

    public function __invoke(UnplanMeal $command): void
    {
        $meal = $this->mealPlan->findById($command->id);

        if (null === $meal) {
            throw new PlannedMealNotFoundException(sprintf('Planned meal "%s" not found.', $command->id));
        }

        $this->mealPlan->remove($meal);
    }
}
