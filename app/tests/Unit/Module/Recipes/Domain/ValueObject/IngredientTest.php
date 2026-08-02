<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Domain\ValueObject;

use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IngredientTest extends TestCase
{
    public function testConstructsWithNameQuantityAndUnit(): void
    {
        $ingredient = new Ingredient('Mąka pszenna', 500.0, MeasurementUnit::GRAM);

        self::assertSame('Mąka pszenna', $ingredient->name());
        self::assertSame(500.0, $ingredient->quantity());
        self::assertSame(MeasurementUnit::GRAM, $ingredient->unit());
    }

    public function testTrimsName(): void
    {
        $ingredient = new Ingredient('  Cukier  ', 2.0, MeasurementUnit::TABLESPOON);

        self::assertSame('Cukier', $ingredient->name());
    }

    public function testAcceptsFractionalQuantity(): void
    {
        $ingredient = new Ingredient('Mleko', 0.5, MeasurementUnit::LITRE);

        self::assertSame(0.5, $ingredient->quantity());
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Ingredient('   ', 1.0, MeasurementUnit::PIECE);
    }

    public function testRejectsTooLongName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Ingredient(str_repeat('a', Ingredient::MAX_NAME_LENGTH + 1), 1.0, MeasurementUnit::PIECE);
    }

    public function testAcceptsMultibyteNameAtMaxLength(): void
    {
        $name = str_repeat('ą', Ingredient::MAX_NAME_LENGTH);

        $ingredient = new Ingredient($name, 1.0, MeasurementUnit::PIECE);

        self::assertSame($name, $ingredient->name());
    }

    public function testRejectsZeroQuantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Ingredient('Sól', 0.0, MeasurementUnit::PINCH);
    }

    public function testRejectsNegativeQuantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Ingredient('Sól', -1.0, MeasurementUnit::PINCH);
    }

    /**
     * NAN and INF both survive a bare "> 0" check and would then poison every
     * sum the shopping list folds them into.
     */
    public function testRejectsNonFiniteQuantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Ingredient('Sól', NAN, MeasurementUnit::PINCH);
    }

    public function testRejectsInfiniteQuantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Ingredient('Sól', INF, MeasurementUnit::PINCH);
    }

    public function testMatchesIgnoresNameCaseAndSurroundingSpace(): void
    {
        $ingredient = new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM);

        self::assertTrue($ingredient->matches('  mąka  ', MeasurementUnit::GRAM));
    }

    public function testMatchesIgnoresQuantity(): void
    {
        $ingredient = new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM);
        $other = new Ingredient('Mąka', 999.0, MeasurementUnit::GRAM);

        self::assertTrue($ingredient->matches($other->name(), $other->unit()));
    }

    public function testDoesNotMatchDifferentUnit(): void
    {
        $ingredient = new Ingredient('Mleko', 200.0, MeasurementUnit::MILLILITRE);

        self::assertFalse($ingredient->matches('Mleko', MeasurementUnit::LITRE));
    }

    public function testEqualsComparesEveryField(): void
    {
        $ingredient = new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM);

        self::assertTrue($ingredient->equals(new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)));
        self::assertFalse($ingredient->equals(new Ingredient('Mąka', 300.0, MeasurementUnit::GRAM)));
        self::assertFalse($ingredient->equals(new Ingredient('Cukier', 200.0, MeasurementUnit::GRAM)));
        self::assertFalse($ingredient->equals(new Ingredient('Mąka', 200.0, MeasurementUnit::KILOGRAM)));
    }
}
