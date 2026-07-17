<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

final readonly class DeleteMovie
{
    public function __construct(public string $id)
    {
    }
}
