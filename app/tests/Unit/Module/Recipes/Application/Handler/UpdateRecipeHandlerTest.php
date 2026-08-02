<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\UpdateRecipe;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Application\Handler\UpdateRecipeHandler;
use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UpdateRecipeHandlerTest extends TestCase
{
    public function testReplacesEveryFieldIncludingTheWholeIngredientAndStepList(): void
    {
        $recipe = $this->existingRecipe();

        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn($recipe);
        $repo->expects(self::once())->method('save')->with($recipe);

        $handler = new UpdateRecipeHandler($repo);
        $handler(new UpdateRecipe(
            'r-1',
            'Zupa jarzynowa',
            [
                ['name' => 'Marchew', 'quantity' => 3.0, 'unit' => 'piece'],
                ['name' => 'Bulion', 'quantity' => 1.5, 'unit' => 'l'],
            ],
            ['Obierz warzywa', 'Gotuj 20 minut'],
            6,
            45,
            ['obiad'],
        ));

        self::assertSame('Zupa jarzynowa', $recipe->title());
        self::assertCount(2, $recipe->ingredients());
        self::assertSame('Marchew', $recipe->ingredients()[0]->name());
        self::assertSame('Bulion', $recipe->ingredients()[1]->name());
        self::assertSame(MeasurementUnit::LITRE, $recipe->ingredients()[1]->unit());
        self::assertSame(['Obierz warzywa', 'Gotuj 20 minut'], $recipe->steps());
        self::assertSame(6, $recipe->servings());
        self::assertSame(45, $recipe->prepTimeMinutes());
        self::assertSame(['obiad'], $recipe->tags());
    }

    public function testClearsOptionalFieldsWhenTheReplacementOmitsThem(): void
    {
        $recipe = $this->existingRecipe();

        $repo = $this->createStub(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn($recipe);

        $handler = new UpdateRecipeHandler($repo);
        $handler(new UpdateRecipe(
            'r-1',
            'Zupa',
            [['name' => 'Woda', 'quantity' => 1.0, 'unit' => 'l']],
            [],
            1,
            null,
            [],
        ));

        self::assertSame([], $recipe->steps());
        self::assertSame([], $recipe->tags());
        self::assertNull($recipe->prepTimeMinutes());
    }

    public function testThrowsWhenRecipeNotFoundAndDoesNotSave(): void
    {
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateRecipeHandler($repo);

        $this->expectException(RecipeNotFoundException::class);
        $handler(new UpdateRecipe('missing', 'Cokolwiek', [['name' => 'X', 'quantity' => 1.0, 'unit' => 'g']], [], 1, null, []));
    }

    /**
     * A rejected update must leave the recipe exactly as it was — the caller
     * still holds the object after the 422, and a half-rewritten aggregate
     * would be persisted by the next unrelated save.
     */
    public function testRejectedUpdateLeavesTheRecipeUntouched(): void
    {
        $recipe = $this->existingRecipe();

        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn($recipe);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateRecipeHandler($repo);

        try {
            $handler(new UpdateRecipe(
                'r-1',
                'Nowy tytuł',
                [
                    ['name' => 'Marchew', 'quantity' => 3.0, 'unit' => 'piece'],
                    ['name' => 'marchew', 'quantity' => 1.0, 'unit' => 'piece'],
                ],
                ['Nowy krok'],
                9,
                99,
                ['nowy'],
            ));
            self::fail('Expected the duplicate ingredient to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame('Zupa', $recipe->title());
        self::assertCount(1, $recipe->ingredients());
        self::assertSame('Ziemniak', $recipe->ingredients()[0]->name());
        self::assertSame(['Obierz'], $recipe->steps());
        self::assertSame(2, $recipe->servings());
        self::assertSame(20, $recipe->prepTimeMinutes());
        self::assertSame(['zupa'], $recipe->tags());
    }

    public function testRejectsUnknownMeasurementUnitWithoutTouchingTheRecipe(): void
    {
        $recipe = $this->existingRecipe();

        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn($recipe);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateRecipeHandler($repo);

        try {
            $handler(new UpdateRecipe('r-1', 'Nowy tytuł', [['name' => 'Sól', 'quantity' => 1.0, 'unit' => 'garść']], [], 1, null, []));
            self::fail('Expected the unknown unit to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame('Zupa', $recipe->title());
        self::assertSame('Ziemniak', $recipe->ingredients()[0]->name());
    }

    public function testRejectsAnUpdateThatWouldLeaveTheRecipeWithoutIngredients(): void
    {
        $recipe = $this->existingRecipe();

        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->method('findById')->willReturn($recipe);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateRecipeHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new UpdateRecipe('r-1', 'Zupa', [], [], 1, null, []));
    }

    private function existingRecipe(): Recipe
    {
        return new Recipe(
            'r-1',
            'Zupa',
            [new Ingredient('Ziemniak', 4.0, MeasurementUnit::PIECE)],
            ['Obierz'],
            2,
            20,
            ['zupa'],
        );
    }
}
