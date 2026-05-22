<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Domain;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Enum\TaskStatus;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

    public function testTaskHasNoEventRecordingInfrastructure(): void
    {
        // HMAI-134 (P1) — Task previously carried releaseEvents() +
        // recordedEvents while no Application handler drained the queue:
        // Tasks has no command handlers at all today, only the
        // GetTimeReportHandler query handler. Mirrors HMAI-59 for Articles.
        //
        // Re-introducing the pair must come with handler wiring in the SAME
        // PR — change this test then. TaskScheduled event class is kept as a
        // contract for that future wiring (Series/Books pattern).
        $reflection = new ReflectionClass(Task::class);

        self::assertFalse(
            $reflection->hasMethod('releaseEvents'),
            'Task::releaseEvents() must not be re-introduced without wiring dispatch in every Application handler — see HMAI-134.',
        );
        self::assertFalse(
            $reflection->hasProperty('recordedEvents'),
            'Task::$recordedEvents must not be re-introduced without wiring dispatch in every Application handler — see HMAI-134.',
        );
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
