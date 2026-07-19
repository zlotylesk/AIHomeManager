<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Event;

use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use App\Shared\Notification\NotifiableEvent;
use DateTimeImmutable;

final readonly class TaskCreated implements NotifiableEvent
{
    use AnnouncesTodaysTask;

    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $taskId,
        public TaskTitle $title,
        public TimeSlot $timeSlot,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }
}
