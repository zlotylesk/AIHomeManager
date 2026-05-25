<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Command;

final readonly class CreateTask
{
    public function __construct(
        public string $title,
        public string $start,
        public string $end,
    ) {
    }
}
