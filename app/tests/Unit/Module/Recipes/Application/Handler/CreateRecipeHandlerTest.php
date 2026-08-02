<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\CreateRecipe;
use App\Module\Recipes\Application\Handler\CreateRecipeHandler;
use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreateRecipeHandlerTest extends TestCase
{
    public function testCreatesRecipeWithFullIngredientAndStepListAndReturnsItsId(): void
    {
        $saved = null;
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (Recipe $recipe) use (&$saved): void {
                $saved = $recipe;
            });

        $handler = new CreateRecipeHandler($repo);

        $id = $handler(new CreateRecipe(
            'Naleśniki',
            [
                ['name' => 'Mąka', 'quantity' => 200.0, 'unit' => 'g'],
                ['name' => 'Mleko', 'quantity' => 0.5, 'unit' => 'l'],
            ],
            ['Wymieszaj', 'Smaż'],
            4,
            30,
            ['Śniadanie', 'śniadanie'],
        ));

        self::assertNotSame('', $id);
        self::assertInstanceOf(Recipe::class, $saved);
        self::assertSame($id, $saved->id());
        self::assertSame('Naleśniki', $saved->title());
        self::assertCount(2, $saved->ingredients());
        self::assertSame('Mąka', $saved->ingredients()[0]->name());
        self::assertSame(MeasurementUnit::GRAM, $saved->ingredients()[0]->unit());
        self::assertSame(MeasurementUnit::LITRE, $saved->ingredients()[1]->unit());
        self::assertSame(['Wymieszaj', 'Smaż'], $saved->steps());
        self::assertSame(4, $saved->servings());
        self::assertSame(30, $saved->prepTimeMinutes());
        self::assertSame(['śniadanie'], $saved->tags());
    }

    public function testDefaultsServingsPrepTimeStepsAndTagsWhenNotSupplied(): void
    {
        $saved = null;
        $repo = $this->createStub(RecipeRepositoryInterface::class);
        $repo->method('save')->willReturnCallback(static function (Recipe $recipe) use (&$saved): void {
            $saved = $recipe;
        });

        $handler = new CreateRecipeHandler($repo);
        $handler(new CreateRecipe('Kanapka', [['name' => 'Chleb', 'quantity' => 2.0, 'unit' => 'piece']]));

        self::assertInstanceOf(Recipe::class, $saved);
        self::assertSame(1, $saved->servings());
        self::assertNull($saved->prepTimeMinutes());
        self::assertSame([], $saved->steps());
        self::assertSame([], $saved->tags());
    }

    public function testRejectsUnknownMeasurementUnitWithoutSaving(): void
    {
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateRecipeHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateRecipe('Zupa', [['name' => 'Sól', 'quantity' => 1.0, 'unit' => 'szczypta']]));
    }

    public function testRejectsRecipeWithoutIngredientsWithoutSaving(): void
    {
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateRecipeHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateRecipe('Pusty przepis', []));
    }

    public function testRejectsEmptyTitleWithoutSaving(): void
    {
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateRecipeHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateRecipe('   ', [['name' => 'Chleb', 'quantity' => 1.0, 'unit' => 'piece']]));
    }

    public function testRejectsServingsBelowOneWithoutSaving(): void
    {
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateRecipeHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateRecipe('Zupa', [['name' => 'Woda', 'quantity' => 1.0, 'unit' => 'l']], [], 0));
    }

    public function testRejectsTheSameIngredientListedTwiceInOneUnit(): void
    {
        $repo = $this->createMock(RecipeRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateRecipeHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateRecipe('Chleb', [
            ['name' => 'Mąka', 'quantity' => 200.0, 'unit' => 'g'],
            ['name' => 'mąka', 'quantity' => 50.0, 'unit' => 'g'],
        ]));
    }

    public function testMintsADistinctIdForEachRecipe(): void
    {
        $repo = $this->createStub(RecipeRepositoryInterface::class);
        $handler = new CreateRecipeHandler($repo);

        $command = new CreateRecipe('Herbata', [['name' => 'Woda', 'quantity' => 0.25, 'unit' => 'l']]);

        self::assertNotSame($handler($command), $handler($command));
    }
}
