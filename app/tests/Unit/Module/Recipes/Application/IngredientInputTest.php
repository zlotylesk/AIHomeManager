<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application;

use App\Module\Recipes\Application\IngredientInput;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IngredientInputTest extends TestCase
{
    public function testBuildsIngredientsInOrder(): void
    {
        $input = IngredientInput::fromRaw([
            ['name' => 'Mąka', 'quantity' => 200.0, 'unit' => 'g'],
            ['name' => 'Mleko', 'quantity' => 0.5, 'unit' => 'l'],
        ]);

        self::assertCount(2, $input->ingredients);
        self::assertSame('Mąka', $input->ingredients[0]->name());
        self::assertSame(200.0, $input->ingredients[0]->quantity());
        self::assertSame(MeasurementUnit::GRAM, $input->ingredients[0]->unit());
        self::assertSame(MeasurementUnit::LITRE, $input->ingredients[1]->unit());
    }

    public function testEmptyInputYieldsNoIngredients(): void
    {
        self::assertSame([], IngredientInput::fromRaw([])->ingredients);
    }

    /**
     * The unit is rejected rather than defaulted: the shopping list groups by
     * (name, unit), so a silent fallback would give a mistyped unit its own
     * line and report a quantity nobody asked for.
     */
    public function testRejectsUnknownUnit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IngredientInput::fromRaw([['name' => 'Sól', 'quantity' => 1.0, 'unit' => 'szczypta']]);
    }

    public function testPropagatesTheValueObjectsOwnValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IngredientInput::fromRaw([['name' => 'Sól', 'quantity' => 0.0, 'unit' => 'g']]);
    }
}
