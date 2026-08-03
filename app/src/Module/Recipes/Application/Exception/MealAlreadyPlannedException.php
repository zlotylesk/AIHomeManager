<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Exception;

use DomainException;

/**
 * The same recipe is already planned for this slot on this day.
 *
 * This is a conflict (409), not invalid input: nothing the caller sent is
 * malformed, the calendar simply already holds what they asked to add. It is
 * raised both when planning and when moving a meal onto an occupied position.
 */
final class MealAlreadyPlannedException extends DomainException
{
}
