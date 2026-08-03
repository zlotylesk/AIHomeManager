<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Query;

use App\Module\Recipes\Application\PlanWindow;
use DateTimeImmutable;

/**
 * Everything that has to be bought to cook the meals planned for an inclusive
 * `[from, to]` window.
 *
 * The window shares its rules with `GetMealPlan` through `PlanWindow`, which
 * is what keeps "the week I am looking at" and "the shopping for that week"
 * from ever meaning two different ranges.
 */
final readonly class GetShoppingList
{
    public PlanWindow $window;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        $this->window = new PlanWindow($from, $to);
    }
}
