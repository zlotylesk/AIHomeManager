<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\Command;

final readonly class DeleteGoal
{
    public function __construct(public string $id)
    {
    }
}
