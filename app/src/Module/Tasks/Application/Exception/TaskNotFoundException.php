<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Exception;

use DomainException;

final class TaskNotFoundException extends DomainException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Task "%s" not found.', $id));
    }
}
