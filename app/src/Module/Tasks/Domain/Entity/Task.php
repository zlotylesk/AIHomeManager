<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Entity;

use App\Module\Tasks\Domain\Enum\TaskStatus;
use App\Module\Tasks\Domain\Event\TaskScheduled;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;

final class Task
{
    private TaskStatus $status;

    /** @var object[] */
    private array $recordedEvents = [];

    public function __construct(
        private readonly string $id,
        private readonly TaskTitle $title,
        private readonly TimeSlot $timeSlot,
        private ?string $googleEventId = null,
    ) {
        $this->status = TaskStatus::PENDING;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): TaskTitle
    {
        return $this->title;
    }

    public function timeSlot(): TimeSlot
    {
        return $this->timeSlot;
    }

    public function googleEventId(): ?string
    {
        return $this->googleEventId;
    }

    public function status(): TaskStatus
    {
        return $this->status;
    }

    public function schedule(): void
    {
        $this->status = TaskStatus::PENDING;
        $this->recordedEvents[] = new TaskScheduled(
            taskId: $this->id,
            title: $this->title,
            timeSlot: $this->timeSlot,
        );
    }

    public function complete(): void
    {
        $this->status = TaskStatus::COMPLETED;
    }

    public function cancel(): void
    {
        $this->status = TaskStatus::CANCELLED;
    }

    public function assignGoogleEventId(string $googleEventId): void
    {
        $this->googleEventId = $googleEventId;
    }

    /** @return object[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
