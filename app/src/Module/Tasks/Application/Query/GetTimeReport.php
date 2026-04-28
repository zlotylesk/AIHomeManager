<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Query;

final readonly class GetTimeReport
{
    public function __construct(
        public \DateTimeImmutable $dateFrom,
        public \DateTimeImmutable $dateTo,
        public ?string $taskTitle = null,
    ) {}
}
