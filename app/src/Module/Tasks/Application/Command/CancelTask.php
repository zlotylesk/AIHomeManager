<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Command;

final readonly class CancelTask
{
    public function __construct(public string $id)
    {
    }
}
