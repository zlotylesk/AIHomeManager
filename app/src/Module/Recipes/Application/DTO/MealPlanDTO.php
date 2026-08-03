<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * The meal plan for a window, grouped by day and then by slot.
 *
 * The window is echoed back because the response is gap-filled: without `from`
 * and `to` a client could not tell a fully-empty plan from a request that was
 * interpreted differently than it meant.
 */
final readonly class MealPlanDTO
{
    /** @param list<MealPlanDayDTO> $days */
    public function __construct(
        public string $from,
        public string $to,
        public array $days,
    ) {
    }
}
