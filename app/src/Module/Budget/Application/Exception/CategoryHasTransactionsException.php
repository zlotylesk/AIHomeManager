<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Exception;

use DomainException;

/**
 * Deleting a category that still has at least one transaction attributed to
 * it. Maps to 409 Conflict — see the DeleteCategoryHandler docblock for why
 * this is a hard block rather than a cascade or a reassignment.
 */
final class CategoryHasTransactionsException extends DomainException
{
}
