<?php

declare(strict_types=1);

namespace App\Module\Recipes\Domain\Enum;

/**
 * The four points in a day a meal can be planned for.
 *
 * The values are canonical English identifiers, not display labels — Polish
 * labels live in the frontend's presentation helpers (HMAI-393), like every
 * other enum in the project. The mapping is spelled out here because two of the
 * four are easy to get backwards in translation:
 *
 *   śniadanie  → BREAKFAST
 *   obiad      → LUNCH   (the main midday meal)
 *   kolacja    → DINNER  (the evening meal)
 *   przekąska  → SNACK
 *
 * That mapping is load-bearing rather than cosmetic: the backing value is what
 * reaches the database, so "correcting" LUNCH and DINNER once plans exist would
 * silently relabel every stored meal rather than just changing a caption.
 */
enum MealSlot: string
{
    case BREAKFAST = 'breakfast';
    case LUNCH = 'lunch';
    case DINNER = 'dinner';
    case SNACK = 'snack';
}
