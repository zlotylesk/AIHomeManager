<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Domain;

use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Enum\MealSlot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlannedMealTest extends TestCase
{
    public function testCarriesEveryFieldItWasPlannedWith(): void
    {
        $meal = new PlannedMeal(
            'm-1',
            new DateTimeImmutable('2026-08-05'),
            MealSlot::LUNCH,
            'r-1',
            4,
        );

        self::assertSame('m-1', $meal->id());
        self::assertSame('2026-08-05', $meal->date()->format('Y-m-d'));
        self::assertSame(MealSlot::LUNCH, $meal->slot());
        self::assertSame('r-1', $meal->recipeId());
        self::assertSame(4, $meal->servings());
    }

    /**
     * A meal is planned for a day, not for an instant. Without normalising, two
     * meals constructed at different times of the same day would disagree about
     * being on the same day — and everything above the aggregate (grouping a
     * week, moving a meal onto the same date) compares exactly that.
     */
    public function testDateIsNormalisedToMidnightWithoutShiftingTheDay(): void
    {
        $meal = new PlannedMeal(
            'm-1',
            new DateTimeImmutable('2026-08-05 23:47:11'),
            MealSlot::DINNER,
            'r-1',
            2,
        );

        self::assertSame('2026-08-05 00:00:00', $meal->date()->format('Y-m-d H:i:s'));
    }

    public function testTwoMealsPlannedAtDifferentTimesOfTheSameDayShareADate(): void
    {
        $morning = new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05 07:15:00'), MealSlot::BREAKFAST, 'r-1', 1);
        $evening = new PlannedMeal('m-2', new DateTimeImmutable('2026-08-05 21:40:00'), MealSlot::DINNER, 'r-2', 3);

        self::assertEquals($morning->date(), $evening->date());
    }

    public function testRejectsAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlannedMeal('   ', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 1);
    }

    public function testRejectsAMealThatReferencesNoRecipe(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, '  ', 1);
    }

    public function testRejectsZeroServings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 0);
    }

    public function testRejectsNegativeServings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', -2);
    }

    public function testASingleServingIsAllowed(): void
    {
        $meal = new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::SNACK, 'r-1', 1);

        self::assertSame(1, $meal->servings());
    }

    public function testMoveToChangesTheDayAndTheSlot(): void
    {
        $meal = new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 2);

        $meal->moveTo(new DateTimeImmutable('2026-08-08'), MealSlot::DINNER);

        self::assertSame('2026-08-08', $meal->date()->format('Y-m-d'));
        self::assertSame(MealSlot::DINNER, $meal->slot());
    }

    public function testMoveToLeavesTheIdentityRecipeAndServingsAlone(): void
    {
        $meal = new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 2);

        $meal->moveTo(new DateTimeImmutable('2026-08-08'), MealSlot::DINNER);

        self::assertSame('m-1', $meal->id());
        self::assertSame('r-1', $meal->recipeId());
        self::assertSame(2, $meal->servings());
    }

    /**
     * The move normalises exactly as the constructor does. Without it, a meal
     * dragged to "next Tuesday" straight from a clock would compare unequal to
     * one freshly planned for the same day.
     */
    public function testMoveToNormalisesTheDestinationDateToMidnight(): void
    {
        $meal = new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 2);

        $meal->moveTo(new DateTimeImmutable('2026-08-08 16:42:07'), MealSlot::DINNER);

        self::assertSame('2026-08-08 00:00:00', $meal->date()->format('Y-m-d H:i:s'));
    }
}
