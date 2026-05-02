<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Command;

final readonly class RemoveBook
{
    public function __construct(public string $id)
    {
    }
}
