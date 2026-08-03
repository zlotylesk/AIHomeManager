<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Exception;

use DomainException;

/**
 * The recipe cannot be deleted because the meal plan still points at it.
 *
 * A conflict (409) rather than a validation error: the request is well formed
 * and the recipe exists — it is the state of the calendar that refuses. See
 * `MealPlanRepositoryInterface` for why this blocks instead of cascading.
 */
final class RecipeIsPlannedException extends DomainException
{
}
