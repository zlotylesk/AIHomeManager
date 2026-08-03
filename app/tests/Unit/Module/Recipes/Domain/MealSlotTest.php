<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Domain;

use App\Module\Recipes\Domain\Enum\MealSlot;
use PHPUnit\Framework\TestCase;

/**
 * Pins the backing values of MealSlot. They reach the `meal_plan.slot` column
 * and take part in the (date, slot, recipe_id) uniqueness rule, so changing one
 * would relabel stored plans rather than just a caption — in particular the
 * LUNCH/DINNER pair, which maps to obiad/kolacja and is the easy one to flip.
 */
final class MealSlotTest extends TestCase
{
    public function testBackingValues(): void
    {
        $values = [];
        foreach (MealSlot::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"BREAKFAST":"breakfast","LUNCH":"lunch","DINNER":"dinner","SNACK":"snack"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }

    public function testValuesAreUnique(): void
    {
        $values = array_map(static fn (MealSlot $case): string => $case->value, MealSlot::cases());

        self::assertSame($values, array_values(array_unique($values)));
    }
}
