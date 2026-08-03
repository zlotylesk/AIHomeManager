<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * One slot of one day, holding every recipe planned for it.
 *
 * A slot with nothing in it is still reported, with an empty list. That is not
 * padding: the order of the slots through a day (breakfast → lunch → dinner →
 * snack) is domain knowledge, so a response carrying only the occupied ones
 * would force every client to know the vocabulary and its sequence in order to
 * lay out a grid. Returning all of them in enum order means a renderer draws
 * what it received, and a slot added to the enum later appears without a
 * frontend change.
 */
final readonly class MealPlanSlotDTO
{
    /** @param list<PlannedMealDTO> $meals */
    public function __construct(
        public string $slot,
        public array $meals,
    ) {
    }
}
