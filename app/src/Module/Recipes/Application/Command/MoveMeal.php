<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Command;

/**
 * Relocate a planned meal to another day and/or slot.
 *
 * Both the date and the slot are always supplied, even when only one of them
 * changes: dragging a card in a calendar knows exactly where it landed, and a
 * "leave this one as it was" encoding would have to distinguish absent from
 * unchanged for no gain.
 *
 * The recipe and the servings are deliberately not part of a move — see
 * `PlannedMeal::moveTo()`.
 */
final readonly class MoveMeal
{
    public function __construct(
        public string $id,
        public string $date,
        public string $slot,
    ) {
    }
}
