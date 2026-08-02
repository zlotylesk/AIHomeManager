<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\DeleteRecipe;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deleting a recipe that is not there is an error, not a quiet success: this
 * is a user's own explicit delete click, so a second one means they are
 * looking at a stale list and should be told (404) rather than shown a
 * confirmation for something that did not happen. The "succeed either way"
 * shape is reserved for endpoints that legitimately double-fire, such as a
 * browser re-offering a push subscription.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteRecipeHandler
{
    public function __construct(private RecipeRepositoryInterface $recipes)
    {
    }

    public function __invoke(DeleteRecipe $command): void
    {
        $recipe = $this->recipes->findById($command->id);

        if (null === $recipe) {
            throw new RecipeNotFoundException(sprintf('Recipe "%s" not found.', $command->id));
        }

        $this->recipes->remove($recipe);
    }
}
