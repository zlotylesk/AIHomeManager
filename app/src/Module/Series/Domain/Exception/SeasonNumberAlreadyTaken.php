<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Exception;

use DomainException;

/**
 * Renumbering a season to a value another season in the same series already
 * uses. A distinct type (vs. the plain DomainException used for "not found")
 * lets the controller answer 409 Conflict instead of 404.
 */
final class SeasonNumberAlreadyTaken extends DomainException
{
}
