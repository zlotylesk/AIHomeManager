<?php

declare(strict_types=1);

namespace App\Module\Recipes\Domain\Enum;

/**
 * The closed set of units an ingredient quantity may be expressed in.
 *
 * Deliberately an enum rather than free text: the shopping list (HMAI-391)
 * aggregates ingredients by (name, unit), so "g" and "gram" typed on two
 * different recipes would silently become two separate lines of the same
 * shopping item. A closed vocabulary is what makes that aggregation honest.
 *
 * The values are canonical identifiers, not display labels — Polish labels
 * live in the frontend's presentation helpers, like every other enum in the
 * project (GoalType, TransactionType, MealSlot).
 */
enum MeasurementUnit: string
{
    case GRAM = 'g';
    case KILOGRAM = 'kg';
    case MILLILITRE = 'ml';
    case LITRE = 'l';
    case PIECE = 'piece';
    case TABLESPOON = 'tablespoon';
    case TEASPOON = 'teaspoon';
    case CUP = 'cup';
    case PINCH = 'pinch';
}
