<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Domain;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Enum\TaskStatus;
use App\Module\Tasks\Domain\Event\TaskScheduled;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TaskAggregateTest extends TestCase
{
    private const string TASK_ID = 'task-uuid-1';

    private function makeTask(): Task
    {
        return new Task(
            id: self::TASK_ID,
            title: new TaskTitle('Write unit tests'),
            timeSlot: new TimeSlot(
                new DateTimeImmutable('2025-01-01 09:00:00'),
                new DateTimeImmutable('2025-01-01 10:00:00'),
            ),
        );
    }

    public function testNewTaskHasPendingStatus(): void
    {
        $task = $this->makeTask();

        self::assertSame(TaskStatus::PENDING, $task->status());
    }

    public function testScheduleRecordsTaskScheduledEvent(): void
    {
        $task = $this->makeTask();

        $task->schedule();
        $events = $task->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TaskScheduled::class, $events[0]);
    }

    public function testScheduledEventContainsCorrectData(): void
    {
        $task = $this->makeTask();

        $task->schedule();
        $events = $task->releaseEvents();

        /** @var TaskScheduled $event */
        $event = $events[0];
        self::assertSame(self::TASK_ID, $event->taskId);
        self::assertSame('Write unit tests', $event->title->value());
        self::assertInstanceOf(DateTimeImmutable::class, $event->occurredAt);
    }

    public function testScheduleSetsStatusToPending(): void
    {
        $task = $this->makeTask();
        $task->complete();

        $task->schedule();

        self::assertSame(TaskStatus::PENDING, $task->status());
    }

    public function testCompleteChangesStatusToCompleted(): void
    {
        $task = $this->makeTask();

        $task->complete();

        self::assertSame(TaskStatus::COMPLETED, $task->status());
    }

    public function testCancelChangesStatusToCancelled(): void
    {
        $task = $this->makeTask();

        $task->cancel();

        self::assertSame(TaskStatus::CANCELLED, $task->status());
    }

    public function testReleaseEventsClearsCollection(): void
    {
        $task = $this->makeTask();
        $task->schedule();

        $task->releaseEvents();

        self::assertEmpty($task->releaseEvents());
    }

    public function testAssignGoogleEventIdSetsId(): void
    {
        $task = $this->makeTask();

        $task->assignGoogleEventId('google-event-id-123');

        self::assertSame('google-event-id-123', $task->googleEventId());
    }

    public function testNewTaskHasNullGoogleEventId(): void
    {
        $task = $this->makeTask();

        self::assertNull($task->googleEventId());
    }
}
