<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Command;

/**
 * Take one planned meal off the calendar. Identified by the plan entry's own
 * id, not by (date, slot, recipe) — a slot holds a list, so the caller is
 * pointing at a row it can already see.
 */
final readonly class UnplanMeal
{
    public function __construct(public string $id)
    {
    }
}
