<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Exception;

use DomainException;

final class TransactionNotFoundException extends DomainException
{
}
