<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\DeleteRecipe;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Application\Handler\DeleteRecipeHandler;
use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use PHPUnit\Framework\TestCase;

final class DeleteRecipeHandlerTest extends TestCase
{
    public function testRemovesRecipe(): void
    {
        $recipe = new Recipe('r-1', 'Zupa', [new Ingredient('Woda', 1.0, MeasurementUnit::LITRE)]);

        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn($recipe);
        $repo->expects(self::once())->method('remove')->with($recipe);

        $handler = new DeleteRecipeHandler($repo);
        $handler(new DeleteRecipe('r-1'));
    }

    /**
     * A repeat delete is a 404, not a quiet success — it means the user is
     * acting on a stale list and should be told.
     */
    public function testThrowsWhenRecipeNotFound(): void
    {
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('remove');

        $handler = new DeleteRecipeHandler($repo);

        $this->expectException(RecipeNotFoundException::class);
        $handler(new DeleteRecipe('missing'));
    }
}
