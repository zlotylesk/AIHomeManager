<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\MoveMeal;
use App\Module\Recipes\Application\Exception\MealAlreadyPlannedException;
use App\Module\Recipes\Application\Exception\PlannedMealNotFoundException;
use App\Module\Recipes\Application\MealPlacementInput;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class MoveMealHandler
{
    public function __construct(private MealPlanRepositoryInterface $mealPlan)
    {
    }

    public function __invoke(MoveMeal $command): void
    {
        // Parsed before anything is loaded, matching PlanMealHandler: a
        // malformed destination is answerable without touching the database,
        // and both handlers rejecting bad input at the same point keeps the
        // status a caller gets from one predictable from the other.
        $placement = MealPlacementInput::fromRaw($command->date, $command->slot);

        $meal = $this->mealPlan->findById($command->id);

        if (null === $meal) {
            throw new PlannedMealNotFoundException(sprintf('Planned meal "%s" not found.', $command->id));
        }

        // The meal being moved is excluded from its own conflict check.
        // Without that, dropping a card back where it came from — or onto the
        // same day in the same slot after a drag that went nowhere — would
        // report the meal as conflicting with itself, and the user would be
        // told a move they can see on screen is impossible.
        $taken = $this->mealPlan->existsFor(
            $placement->date,
            $placement->slot,
            $meal->recipeId(),
            excludingId: $meal->id(),
        );

        if ($taken) {
            throw new MealAlreadyPlannedException(sprintf('Recipe "%s" is already planned for %s (%s).', $meal->recipeId(), $placement->date->format('Y-m-d'), $placement->slot->value));
        }

        $meal->moveTo($placement->date, $placement->slot);

        $this->mealPlan->save($meal);
    }
}
