<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\DTO;

final readonly class TaskDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public string $start,
        public string $end,
        public int $durationMinutes,
        public string $status,
        public ?string $googleEventId,
    ) {
    }
}
