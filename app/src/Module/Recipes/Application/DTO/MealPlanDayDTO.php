<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * One day of the plan, with every slot of that day.
 *
 * Days with nothing planned are present too, for the same reason Insights emits
 * a zero point for an idle bucket: a calendar shows the whole week regardless,
 * and omitting the empty days would push the job of working out which dates are
 * missing — month lengths, leap years and all — onto the client.
 */
final readonly class MealPlanDayDTO
{
    /** @param list<MealPlanSlotDTO> $slots */
    public function __construct(
        public string $date,
        public array $slots,
    ) {
    }
}
