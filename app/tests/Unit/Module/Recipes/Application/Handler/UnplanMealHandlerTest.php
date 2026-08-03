<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application\Handler;

use App\Module\Recipes\Application\Command\UnplanMeal;
use App\Module\Recipes\Application\Exception\PlannedMealNotFoundException;
use App\Module\Recipes\Application\Handler\UnplanMealHandler;
use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Enum\MealSlot;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UnplanMealHandlerTest extends TestCase
{
    public function testRemovesThePlannedMeal(): void
    {
        $meal = new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 2);

        $mealPlan = $this->createMock(MealPlanRepositoryInterface::class);
        $mealPlan->method('findById')->willReturn($meal);
        $mealPlan->expects(self::once())->method('remove')->with($meal);

        $handler = new UnplanMealHandler($mealPlan);
        $handler(new UnplanMeal('m-1'));
    }

    /**
     * Not silently idempotent: the caller clicked a card it could see, so a
     * miss means the plan moved under them.
     */
    public function testThrowsWhenTheMealIsNotOnThePlan(): void
    {
        $mealPlan = $this->createMock(MealPlanRepositoryInterface::class);
        $mealPlan->method('findById')->willReturn(null);
        $mealPlan->expects(self::never())->method('remove');

        $handler = new UnplanMealHandler($mealPlan);

        $this->expectException(PlannedMealNotFoundException::class);
        $handler(new UnplanMeal('missing'));
    }
}
