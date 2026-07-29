<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Exception;

use DomainException;

/**
 * Creating or renaming a category to a name another category of the same
 * type already uses. A distinct type (vs. CategoryNotFoundException) lets
 * the controller answer 409 Conflict instead of 404 (the Series
 * SeasonNumberAlreadyTaken precedent).
 */
final class CategoryNameAlreadyTakenException extends DomainException
{
}
