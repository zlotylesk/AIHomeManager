<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Command;

final readonly class DeleteTransaction
{
    public function __construct(public string $id)
    {
    }
}
