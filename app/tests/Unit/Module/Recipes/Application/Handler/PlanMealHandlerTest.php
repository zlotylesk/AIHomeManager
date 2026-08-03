<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\PlanMeal;
use App\Module\Recipes\Application\Exception\MealAlreadyPlannedException;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Application\Handler\PlanMealHandler;
use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Enum\MealSlot;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PlanMealHandlerTest extends TestCase
{
    public function testPlansAMealAndReturnsItsId(): void
    {
        $saved = null;

        $mealPlan = $this->mealPlan(alreadyPlanned: false);
        $mealPlan->method('save')->willReturnCallback(static function (PlannedMeal $meal) use (&$saved): void {
            $saved = $meal;
        });

        $handler = new PlanMealHandler($mealPlan, $this->recipes(self::recipe()));

        $id = $handler(new PlanMeal('2026-08-05', 'lunch', 'r-1', 4));

        self::assertNotNull($saved);
        self::assertSame($id, $saved->id());
        self::assertSame('2026-08-05', $saved->date()->format('Y-m-d'));
        self::assertSame(MealSlot::LUNCH, $saved->slot());
        self::assertSame('r-1', $saved->recipeId());
        self::assertSame(4, $saved->servings());
    }

    public function testMintsADistinctIdPerPlannedMeal(): void
    {
        $handler = new PlanMealHandler($this->mealPlan(alreadyPlanned: false), $this->recipes(self::recipe()));

        $first = $handler(new PlanMeal('2026-08-05', 'lunch', 'r-1'));
        $second = $handler(new PlanMeal('2026-08-06', 'lunch', 'r-1'));

        self::assertNotSame($first, $second);
    }

    /**
     * A plan entry pointing at a recipe that was never there would render as a
     * nameless card and contribute nothing to the shopping list, so it is
     * refused before anything is written.
     */
    public function testRefusesToPlanARecipeThatDoesNotExist(): void
    {
        $mealPlan = $this->mealPlan(alreadyPlanned: false);
        $mealPlan->expects(self::never())->method('save');

        $handler = new PlanMealHandler($mealPlan, $this->recipes(null));

        $this->expectException(RecipeNotFoundException::class);
        $handler(new PlanMeal('2026-08-05', 'lunch', 'missing'));
    }

    public function testRefusesTheSameRecipeTwiceInOneSlot(): void
    {
        $mealPlan = $this->mealPlan(alreadyPlanned: true);
        $mealPlan->expects(self::never())->method('save');

        $handler = new PlanMealHandler($mealPlan, $this->recipes(self::recipe()));

        $this->expectException(MealAlreadyPlannedException::class);
        $handler(new PlanMeal('2026-08-05', 'lunch', 'r-1'));
    }

    public function testRejectsAnImpossibleDateWithoutTouchingEitherRepository(): void
    {
        $mealPlan = $this->createMock(MealPlanRepositoryInterface::class);
        $mealPlan->expects(self::never())->method('existsFor');
        $mealPlan->expects(self::never())->method('save');

        $recipes = $this->createMock(RecipeRepositoryInterface::class);
        $recipes->expects(self::never())->method('findById');

        $handler = new PlanMealHandler($mealPlan, $recipes);

        $this->expectException(InvalidArgumentException::class);
        $handler(new PlanMeal('2026-02-31', 'lunch', 'r-1'));
    }

    public function testRejectsAnUnknownSlot(): void
    {
        $mealPlan = $this->mealPlan(alreadyPlanned: false);
        $mealPlan->expects(self::never())->method('save');

        $handler = new PlanMealHandler($mealPlan, $this->recipes(self::recipe()));

        $this->expectException(InvalidArgumentException::class);
        $handler(new PlanMeal('2026-08-05', 'brunch', 'r-1'));
    }

    public function testRejectsServingsBelowOne(): void
    {
        $mealPlan = $this->mealPlan(alreadyPlanned: false);
        $mealPlan->expects(self::never())->method('save');

        $handler = new PlanMealHandler($mealPlan, $this->recipes(self::recipe()));

        $this->expectException(InvalidArgumentException::class);
        $handler(new PlanMeal('2026-08-05', 'lunch', 'r-1', 0));
    }

    private static function recipe(): Recipe
    {
        return new Recipe('r-1', 'Zupa', [new Ingredient('Woda', 1.0, MeasurementUnit::LITRE)]);
    }

    private function mealPlan(bool $alreadyPlanned): MealPlanRepositoryInterface&MockObject
    {
        $mealPlan = $this->createMock(MealPlanRepositoryInterface::class);
        $mealPlan->method('existsFor')->willReturn($alreadyPlanned);

        return $mealPlan;
    }

    private function recipes(?Recipe $recipe): RecipeRepositoryInterface&MockObject
    {
        $recipes = $this->createMock(RecipeRepositoryInterface::class);
        $recipes->method('findById')->willReturn($recipe);

        return $recipes;
    }
}
