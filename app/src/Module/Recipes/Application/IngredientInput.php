<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application;

use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use InvalidArgumentException;

/**
 * Ingredient value objects built from the raw command input. One place resolves
 * the measurement unit, so CreateRecipe and UpdateRecipe do not duplicate the
 * parsing (the TransactionInput precedent).
 *
 * The unit is rejected rather than defaulted when it is not one the module
 * knows: the shopping list groups by (name, unit), so quietly falling back to
 * "piece" would put a mistyped unit in its own line of the list and report a
 * quantity nobody asked for.
 */
final readonly class IngredientInput
{
    /** @param list<Ingredient> $ingredients */
    private function __construct(public array $ingredients)
    {
    }

    /** @param list<array{name: string, quantity: float, unit: string}> $raw */
    public static function fromRaw(array $raw): self
    {
        $ingredients = [];

        foreach ($raw as $item) {
            $unit = MeasurementUnit::tryFrom($item['unit'])
                ?? throw new InvalidArgumentException(sprintf('Unknown measurement unit "%s".', $item['unit']));

            $ingredients[] = new Ingredient($item['name'], $item['quantity'], $unit);
        }

        return new self($ingredients);
    }
}
