<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

final readonly class CreateSeries
{
    public function __construct(
        public string $title,
    ) {}
}