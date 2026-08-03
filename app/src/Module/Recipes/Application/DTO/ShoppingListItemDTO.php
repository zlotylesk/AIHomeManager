<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * One line of the shopping list: how much of one thing, in one unit.
 *
 * The quantity is the **raw** sum, deliberately not rounded here. The
 * `Ingredient` value object already states that rounding is a presentation
 * concern, and the presentation layer is the one that knows what the unit
 * deserves — 333.333 g rounds to whole grams, 0.667 l does not round to whole
 * litres. Rounding at this level would bake one precision into the API for
 * every unit at once. The consequence to be aware of when rendering: a sum of
 * floats can surface as 0.30000000000000004, so HMAI-393 must format rather
 * than print.
 *
 * Two units of the same ingredient stay two lines. Converting between them is
 * out of scope for the MVP, and guessing (are 2 "cups" of flour 250 g?) would
 * put an invented number on a shopping list.
 */
final readonly class ShoppingListItemDTO
{
    public function __construct(
        public string $name,
        public string $unit,
        public float $quantity,
    ) {
    }
}
