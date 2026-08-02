<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Domain;

use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use PHPUnit\Framework\TestCase;

/**
 * Pins the backing values of MeasurementUnit — the shopping list groups
 * ingredients by (name, unit), so these strings are a persistence and
 * aggregation contract, not just labels.
 */
final class MeasurementUnitTest extends TestCase
{
    public function testBackingValues(): void
    {
        $values = [];
        foreach (MeasurementUnit::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"GRAM":"g","KILOGRAM":"kg","MILLILITRE":"ml","LITRE":"l","PIECE":"piece","TABLESPOON":"tablespoon","TEASPOON":"teaspoon","CUP":"cup","PINCH":"pinch"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }

    public function testValuesAreUnique(): void
    {
        $values = array_map(static fn (MeasurementUnit $case): string => $case->value, MeasurementUnit::cases());

        self::assertSame($values, array_values(array_unique($values)));
    }
}
