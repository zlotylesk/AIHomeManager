<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\DTO;

final readonly class GoalProgressDTO
{
    public function __construct(
        public string $goalId,
        public string $type,
        public string $period,
        public int $target,
        public int $achieved,
        public int $percent,
        public bool $met,
    ) {
    }
}
