<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Domain;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Enum\TaskStatus;
use App\Module\Tasks\Domain\Event\TaskCancelled;
use App\Module\Tasks\Domain\Event\TaskCompleted;
use App\Module\Tasks\Domain\Event\TaskCreated;
use App\Module\Tasks\Domain\Event\TaskUpdated;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TaskAggregateTest extends TestCase
{
    private const string TASK_ID = 'task-uuid-1';

    private function makeTimeSlot(): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );
    }

    public function testCreateRecordsTaskCreatedEvent(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Buy groceries'), $this->makeTimeSlot());

        $events = $task->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TaskCreated::class, $events[0]);
        self::assertSame(self::TASK_ID, $events[0]->taskId);
    }

    public function testNewTaskHasPendingStatus(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Write unit tests'), $this->makeTimeSlot());

        self::assertSame(TaskStatus::PENDING, $task->status());
    }

    public function testUpdateChangesFieldsAndRecordsEvent(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Original'), $this->makeTimeSlot());
        $task->releaseEvents();

        $newTitle = new TaskTitle('Updated title');
        $newSlot = new TimeSlot(
            new DateTimeImmutable('2025-02-01 09:00:00'),
            new DateTimeImmutable('2025-02-01 11:00:00'),
        );
        $task->update($newTitle, $newSlot);

        self::assertTrue($task->title()->equals($newTitle));
        self::assertTrue($task->timeSlot()->equals($newSlot));
        $events = $task->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskUpdated::class, $events[0]);
    }

    public function testCompleteChangesStatusAndRecordsEvent(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());
        $task->releaseEvents();

        $task->complete();

        self::assertSame(TaskStatus::COMPLETED, $task->status());
        $events = $task->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskCompleted::class, $events[0]);
    }

    public function testCancelChangesStatusAndRecordsEvent(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());
        $task->releaseEvents();

        $task->cancel();

        self::assertSame(TaskStatus::CANCELLED, $task->status());
        $events = $task->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskCancelled::class, $events[0]);
    }

    public function testAssignGoogleEventIdSetsId(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());

        $task->assignGoogleEventId('google-event-id-123');

        self::assertSame('google-event-id-123', $task->googleEventId());
    }

    public function testNewTaskHasNullGoogleEventId(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());

        self::assertNull($task->googleEventId());
    }

    public function testReleaseEventsClearsQueue(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());

        $first = $task->releaseEvents();
        $second = $task->releaseEvents();

        self::assertCount(1, $first);
        self::assertCount(0, $second);
    }

    public function testCompleteThrowsWhenAlreadyCompleted(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());
        $task->complete();

        $this->expectException(DomainException::class);
        $task->complete();
    }

    public function testCompleteThrowsWhenCancelled(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());
        $task->cancel();

        $this->expectException(DomainException::class);
        $task->complete();
    }

    public function testCancelThrowsWhenAlreadyCompleted(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());
        $task->complete();

        $this->expectException(DomainException::class);
        $task->cancel();
    }

    public function testCancelThrowsWhenAlreadyCancelled(): void
    {
        $task = Task::create(self::TASK_ID, new TaskTitle('Task'), $this->makeTimeSlot());
        $task->cancel();

        $this->expectException(DomainException::class);
        $task->cancel();
    }
}
