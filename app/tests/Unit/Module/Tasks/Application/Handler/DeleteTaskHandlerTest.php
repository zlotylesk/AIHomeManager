<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\DeleteTask;
use App\Module\Tasks\Application\Exception\TaskNotFoundException;
use App\Module\Tasks\Application\Handler\DeleteTaskHandler;
use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Event\TaskDeleted;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteTaskHandlerTest extends TestCase
{
    public function testDeletesTaskAndRemovesGoogleEventAndDispatchesEvent(): void
    {
        $task = Task::create('t-1', new TaskTitle('Task'), new TimeSlot(
            new DateTimeImmutable('2025-06-01 09:00'),
            new DateTimeImmutable('2025-06-01 10:00'),
        ));
        $task->assignGoogleEventId('google-abc');

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn($task);
        $repo->expects(self::once())->method('remove');

        $calendar = $this->createMock(CalendarServiceInterface::class);
        $calendar->expects(self::once())->method('deleteEvent')->with('google-abc');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with(self::callback(
            fn (object $e) => $e instanceof TaskDeleted && 't-1' === $e->taskId && 'google-abc' === $e->googleEventId
        ))->willReturnCallback(fn (object $e) => new Envelope($e));

        $handler = new DeleteTaskHandler($repo, $calendar, $eventBus, new NullLogger());
        $handler(new DeleteTask('t-1'));
    }

    public function testDeletesTaskWithoutGoogleEventIdDoesNotCallCalendar(): void
    {
        $task = Task::create('t-1', new TaskTitle('Task'), new TimeSlot(
            new DateTimeImmutable('2025-06-01 09:00'),
            new DateTimeImmutable('2025-06-01 10:00'),
        ));

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn($task);
        $repo->expects(self::once())->method('remove');

        $calendar = $this->createMock(CalendarServiceInterface::class);
        $calendar->expects(self::never())->method('deleteEvent');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with(self::callback(
            fn (object $e) => $e instanceof TaskDeleted && null === $e->googleEventId
        ))->willReturnCallback(fn (object $e) => new Envelope($e));

        $handler = new DeleteTaskHandler($repo, $calendar, $eventBus, new NullLogger());
        $handler(new DeleteTask('t-1'));
    }

    public function testThrowsWhenTaskNotFound(): void
    {
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $calendar = $this->createMock(CalendarServiceInterface::class);
        $eventBus = $this->createMock(MessageBusInterface::class);

        $handler = new DeleteTaskHandler($repo, $calendar, $eventBus, new NullLogger());

        $this->expectException(TaskNotFoundException::class);
        $handler(new DeleteTask('missing'));
    }
}
