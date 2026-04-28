<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\DTO;

final readonly class TimeReportDTO
{
    public function __construct(
        public int $totalMinutes,
        public float $totalHours,
        /** @var TaskTimeDTO[] */
        public array $breakdown,
    ) {}
}
