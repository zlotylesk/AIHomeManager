<?php

declare(strict_types=1);

namespace App\Module\Recipes\Domain\ValueObject;

use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use InvalidArgumentException;

/**
 * One line of a recipe's ingredient list: what, how much, in which unit.
 *
 * The quantity is a float, deliberately unlike the Budget module's Money,
 * which stores whole minor units precisely to avoid rounding drift. The two
 * are not the same problem: a ledger must reconcile exactly, whereas an
 * ingredient quantity is inherently fractional (0.5 l) and is scaled by a
 * servings ratio when the shopping list is built (HMAI-391) — 2/3 of 500 g
 * has no exact integer representation in any unit a cook would recognise.
 * Rounding is therefore a presentation concern, not a storage one.
 */
final readonly class Ingredient
{
    public const int MAX_NAME_LENGTH = 120;

    private string $name;

    private float $quantity;

    public function __construct(string $name, float $quantity, private MeasurementUnit $unit)
    {
        $normalizedName = trim($name);

        if ('' === $normalizedName) {
            throw new InvalidArgumentException('Ingredient name cannot be empty.');
        }

        if (mb_strlen($normalizedName) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('Ingredient name cannot exceed %d characters.', self::MAX_NAME_LENGTH));
        }

        // NAN and INF would both pass a bare "> 0" check and then poison every
        // sum the shopping list folds them into.
        if (!is_finite($quantity)) {
            throw new InvalidArgumentException('Ingredient quantity must be a finite number.');
        }

        if ($quantity <= 0.0) {
            throw new InvalidArgumentException('Ingredient quantity must be greater than zero.');
        }

        $this->name = $normalizedName;
        $this->quantity = $quantity;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function quantity(): float
    {
        return $this->quantity;
    }

    public function unit(): MeasurementUnit
    {
        return $this->unit;
    }

    /**
     * Whether this line refers to the same shopping item as the given
     * (name, unit) pair — the identity the recipe deduplicates on and the
     * shopping list groups by. The name is matched case-insensitively, so
     * "Mąka" and "mąka" are one item; the quantity is deliberately not part
     * of the comparison.
     */
    public function matches(string $name, MeasurementUnit $unit): bool
    {
        return $unit === $this->unit
            && mb_strtolower(trim($name)) === mb_strtolower($this->name);
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->quantity === $other->quantity
            && $this->unit === $other->unit;
    }
}
