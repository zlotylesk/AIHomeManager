<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Exception;

use DomainException;

final class BookNotFoundException extends DomainException
{
}
