<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\UpdateRecipe;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Application\IngredientInput;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateRecipeHandler
{
    public function __construct(private RecipeRepositoryInterface $recipes)
    {
    }

    public function __invoke(UpdateRecipe $command): void
    {
        $recipe = $this->recipes->findById($command->id);

        if (null === $recipe) {
            throw new RecipeNotFoundException(sprintf('Recipe "%s" not found.', $command->id));
        }

        // The units are resolved before the aggregate is touched, so an unknown
        // one is refused without the recipe having been changed — the same
        // all-or-nothing property Recipe::update() guarantees for the rest.
        $ingredients = IngredientInput::fromRaw($command->ingredients)->ingredients;

        $recipe->update(
            $command->title,
            $ingredients,
            $command->steps,
            $command->servings,
            $command->prepTimeMinutes,
            $command->tags,
        );

        $this->recipes->save($recipe);
    }
}
