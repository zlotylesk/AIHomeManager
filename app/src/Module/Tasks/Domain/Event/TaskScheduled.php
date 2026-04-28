<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Event;

use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;

final class TaskScheduled
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $taskId,
        public readonly TaskTitle $title,
        public readonly TimeSlot $timeSlot,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }
}