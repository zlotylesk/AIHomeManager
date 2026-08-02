<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\CreateRecipe;
use App\Module\Recipes\Application\IngredientInput;
use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateRecipeHandler
{
    public function __construct(private RecipeRepositoryInterface $recipes)
    {
    }

    public function __invoke(CreateRecipe $command): string
    {
        $recipe = new Recipe(
            Uuid::v4()->toRfc4122(),
            $command->title,
            IngredientInput::fromRaw($command->ingredients)->ingredients,
            $command->steps,
            $command->servings,
            $command->prepTimeMinutes,
            $command->tags,
        );

        $this->recipes->save($recipe);

        return $recipe->id();
    }
}
