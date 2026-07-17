<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Exception;

use DomainException;

final class MovieNotFoundException extends DomainException
{
}
