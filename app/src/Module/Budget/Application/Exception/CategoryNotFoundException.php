<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Exception;

use DomainException;

/**
 * Thrown both when a Category itself is missing (update/delete miss) and when
 * a Transaction references a categoryId that does not resolve to one — one
 * canonical exception for "this category does not exist" either way.
 */
final class CategoryNotFoundException extends DomainException
{
}
