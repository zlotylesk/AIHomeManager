<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application;

use App\Module\Recipes\Domain\Enum\MealSlot;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Where a meal sits in the calendar: the day and the slot, parsed once.
 *
 * Both `PlanMeal` and `MoveMeal` carry exactly this pair as primitives, so the
 * parse lives here rather than in each handler — the IngredientInput /
 * TransactionInput precedent. Duplicating it is how one of the two would
 * eventually end up tolerating a shape the other rejects.
 *
 * The date parse is strict, and that is the whole point of the round-trip
 * comparison below: `createFromFormat` is lenient and ROLLS OVER an impossible
 * date rather than rejecting it, so "2026-02-31" parses happily to 2026-03-03.
 * Left unchecked, a mistyped day is accepted and the meal quietly appears on a
 * date nobody chose — it is simply missing from the day the user was looking
 * at, with nothing anywhere reporting a problem. The same lenient parse is what
 * the Budget module had to fix after it had already booked money into the wrong
 * month.
 *
 * The slot is likewise rejected rather than defaulted: falling back to, say,
 * breakfast would put the meal in a slot the user never picked, and they would
 * discover it only by reading the whole week.
 */
final readonly class MealPlacementInput
{
    private function __construct(
        public DateTimeImmutable $date,
        public MealSlot $slot,
    ) {
    }

    public static function fromRaw(string $date, string $slot): self
    {
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (false === $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('Invalid meal date "%s", expected YYYY-MM-DD.', $date));
        }

        $parsedSlot = MealSlot::tryFrom($slot)
            ?? throw new InvalidArgumentException(sprintf('Unknown meal slot "%s".', $slot));

        return new self($parsedDate, $parsedSlot);
    }
}
