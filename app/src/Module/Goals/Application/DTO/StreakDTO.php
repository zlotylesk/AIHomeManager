<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\DTO;

final readonly class StreakDTO
{
    public function __construct(
        public string $type,
        public int $currentLength,
        public int $longestLength,
        public ?string $lastActivityDate,
    ) {
    }
}
