<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\DTO;

final readonly class TaskTimeDTO
{
    public function __construct(
        public string $taskId,
        public string $title,
        public int $minutes,
    ) {}
}
