<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Command;

final readonly class DeleteTask
{
    public function __construct(public string $id)
    {
    }
}
