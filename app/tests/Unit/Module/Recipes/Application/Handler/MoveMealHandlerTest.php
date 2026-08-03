<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\MoveMeal;
use App\Module\Recipes\Application\Exception\MealAlreadyPlannedException;
use App\Module\Recipes\Application\Exception\PlannedMealNotFoundException;
use App\Module\Recipes\Application\Handler\MoveMealHandler;
use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Enum\MealSlot;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MoveMealHandlerTest extends TestCase
{
    public function testMovesTheMealToTheNewDayAndSlot(): void
    {
        $meal = self::meal();

        $mealPlan = $this->mealPlan($meal, destinationTaken: false);
        $mealPlan->expects(self::once())->method('save')->with($meal);

        $handler = new MoveMealHandler($mealPlan);
        $handler(new MoveMeal('m-1', '2026-08-08', 'dinner'));

        self::assertSame('2026-08-08', $meal->date()->format('Y-m-d'));
        self::assertSame(MealSlot::DINNER, $meal->slot());
    }

    public function testAMoveLeavesTheRecipeAndServingsAlone(): void
    {
        $meal = self::meal();

        $handler = new MoveMealHandler($this->mealPlan($meal, destinationTaken: false));
        $handler(new MoveMeal('m-1', '2026-08-08', 'dinner'));

        self::assertSame('r-1', $meal->recipeId());
        self::assertSame(2, $meal->servings());
    }

    /**
     * The destination check excludes the meal being moved. Without that,
     * dropping a card back where it came from would be reported as a conflict
     * with itself.
     */
    public function testMovingAMealOntoItsOwnPositionSucceeds(): void
    {
        $meal = self::meal();

        $mealPlan = $this->createMock(MealPlanRepositoryInterface::class);
        $mealPlan->method('findById')->willReturn($meal);
        $mealPlan->expects(self::once())
            ->method('existsFor')
            ->with(self::anything(), MealSlot::LUNCH, 'r-1', 'm-1')
            ->willReturn(false);
        $mealPlan->expects(self::once())->method('save')->with($meal);

        $handler = new MoveMealHandler($mealPlan);
        $handler(new MoveMeal('m-1', '2026-08-05', 'lunch'));

        self::assertSame('2026-08-05', $meal->date()->format('Y-m-d'));
    }

    public function testRefusesAMoveOntoAnOccupiedPosition(): void
    {
        $meal = self::meal();

        $mealPlan = $this->mealPlan($meal, destinationTaken: true);
        $mealPlan->expects(self::never())->method('save');

        $handler = new MoveMealHandler($mealPlan);

        $this->expectException(MealAlreadyPlannedException::class);
        $handler(new MoveMeal('m-1', '2026-08-08', 'dinner'));
    }

    public function testARefusedMoveLeavesTheMealWhereItWas(): void
    {
        $meal = self::meal();

        $handler = new MoveMealHandler($this->mealPlan($meal, destinationTaken: true));

        try {
            $handler(new MoveMeal('m-1', '2026-08-08', 'dinner'));
            self::fail('Expected the move to be refused.');
        } catch (MealAlreadyPlannedException) {
            // The aggregate must not have been touched on the way to the
            // refusal — a caller still holding it would otherwise see a meal
            // that moved despite the error.
            self::assertSame('2026-08-05', $meal->date()->format('Y-m-d'));
            self::assertSame(MealSlot::LUNCH, $meal->slot());
        }
    }

    public function testThrowsWhenTheMealIsNotOnThePlan(): void
    {
        $mealPlan = $this->createMock(MealPlanRepositoryInterface::class);
        $mealPlan->method('findById')->willReturn(null);
        $mealPlan->expects(self::never())->method('save');

        $handler = new MoveMealHandler($mealPlan);

        $this->expectException(PlannedMealNotFoundException::class);
        $handler(new MoveMeal('missing', '2026-08-08', 'dinner'));
    }

    public function testRejectsAnImpossibleDestinationDate(): void
    {
        $meal = self::meal();

        $mealPlan = $this->mealPlan($meal, destinationTaken: false);
        $mealPlan->expects(self::never())->method('save');

        $handler = new MoveMealHandler($mealPlan);

        $this->expectException(InvalidArgumentException::class);
        $handler(new MoveMeal('m-1', '2026-02-31', 'dinner'));
    }

    private static function meal(): PlannedMeal
    {
        return new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 2);
    }

    private function mealPlan(PlannedMeal $meal, bool $destinationTaken): MealPlanRepositoryInterface&MockObject
    {
        $mealPlan = $this->createMock(MealPlanRepositoryInterface::class);
        $mealPlan->method('findById')->willReturn($meal);
        $mealPlan->method('existsFor')->willReturn($destinationTaken);

        return $mealPlan;
    }
}
