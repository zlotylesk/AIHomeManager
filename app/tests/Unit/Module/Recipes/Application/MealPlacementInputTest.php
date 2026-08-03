<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application;

use App\Module\Recipes\Application\MealPlacementInput;
use App\Module\Recipes\Domain\Enum\MealSlot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MealPlacementInputTest extends TestCase
{
    public function testParsesADateAndASlot(): void
    {
        $placement = MealPlacementInput::fromRaw('2026-08-05', 'lunch');

        self::assertSame('2026-08-05', $placement->date->format('Y-m-d'));
        self::assertSame(MealSlot::LUNCH, $placement->slot);
    }

    public function testTheParsedDateCarriesNoTimeOfDay(): void
    {
        $placement = MealPlacementInput::fromRaw('2026-08-05', 'dinner');

        self::assertSame('00:00:00', $placement->date->format('H:i:s'));
    }

    /**
     * The one that matters: createFromFormat resolves this to 2026-03-03, so
     * without the round-trip check the meal is accepted and quietly lands on a
     * day the user never picked — missing from the day they were looking at,
     * with nothing reporting a problem.
     */
    public function testRejectsAnImpossibleDayInsteadOfRollingItOver(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MealPlacementInput::fromRaw('2026-02-31', 'lunch');
    }

    public function testRejectsAnImpossibleMonth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MealPlacementInput::fromRaw('2026-13-01', 'lunch');
    }

    public function testRejectsAnUnpaddedDate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MealPlacementInput::fromRaw('2026-8-5', 'lunch');
    }

    public function testRejectsAnUnknownSlotInsteadOfDefaultingToOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MealPlacementInput::fromRaw('2026-08-05', 'brunch');
    }

    public function testAcceptsALeapDayInALeapYear(): void
    {
        $placement = MealPlacementInput::fromRaw('2028-02-29', 'breakfast');

        self::assertSame('2028-02-29', $placement->date->format('Y-m-d'));
    }

    public function testRejectsALeapDayOutsideALeapYear(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MealPlacementInput::fromRaw('2026-02-29', 'breakfast');
    }
}
