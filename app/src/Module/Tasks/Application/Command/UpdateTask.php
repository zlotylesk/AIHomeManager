<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Command;

final readonly class UpdateTask
{
    public function __construct(
        public string $id,
        public ?string $title = null,
        public ?string $start = null,
        public ?string $end = null,
    ) {
    }
}
