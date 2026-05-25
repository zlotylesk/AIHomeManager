<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Entity;

use App\Module\Tasks\Domain\Enum\TaskStatus;
use App\Module\Tasks\Domain\Event\TaskCancelled;
use App\Module\Tasks\Domain\Event\TaskCompleted;
use App\Module\Tasks\Domain\Event\TaskCreated;
use App\Module\Tasks\Domain\Event\TaskUpdated;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DomainException;

final class Task
{
    private TaskStatus $status;

    /** @var object[] */
    private array $recordedEvents = [];

    public function __construct(
        private readonly string $id,
        private TaskTitle $title,
        private TimeSlot $timeSlot,
        private ?string $googleEventId = null,
    ) {
        $this->status = TaskStatus::PENDING;
    }

    public static function create(string $id, TaskTitle $title, TimeSlot $timeSlot): self
    {
        $task = new self($id, $title, $timeSlot);
        $task->recordedEvents[] = new TaskCreated($id, $title, $timeSlot);

        return $task;
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

    public function update(TaskTitle $title, TimeSlot $timeSlot): void
    {
        $this->title = $title;
        $this->timeSlot = $timeSlot;
        $this->recordedEvents[] = new TaskUpdated($this->id, $title, $timeSlot);
    }

    public function complete(): void
    {
        if (TaskStatus::PENDING !== $this->status) {
            throw new DomainException(sprintf('Cannot complete task "%s" — current status is "%s", expected "pending".', $this->id, $this->status->value));
        }
        $this->status = TaskStatus::COMPLETED;
        $this->recordedEvents[] = new TaskCompleted($this->id);
    }

    public function cancel(): void
    {
        if (TaskStatus::PENDING !== $this->status) {
            throw new DomainException(sprintf('Cannot cancel task "%s" — current status is "%s", expected "pending".', $this->id, $this->status->value));
        }
        $this->status = TaskStatus::CANCELLED;
        $this->recordedEvents[] = new TaskCancelled($this->id);
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
