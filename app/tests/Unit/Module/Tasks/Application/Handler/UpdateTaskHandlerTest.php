<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\UpdateTask;
use App\Module\Tasks\Application\Exception\TaskNotFoundException;
use App\Module\Tasks\Application\Handler\UpdateTaskHandler;
use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class UpdateTaskHandlerTest extends TestCase
{
    public function testUpdatesTaskTitleAndCallsCalendar(): void
    {
        $task = Task::create('t-1', new TaskTitle('Old'), new TimeSlot(
            new DateTimeImmutable('2025-06-01 09:00'),
            new DateTimeImmutable('2025-06-01 10:00'),
        ));
        $task->releaseEvents();

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::any())->method('findById')->with('t-1')->willReturn($task);
        $repo->expects(self::once())->method('save');

        $calendar = $this->createMock(CalendarServiceInterface::class);
        $calendar->expects(self::once())->method('updateEvent');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(fn (object $e) => new Envelope($e));

        $handler = new UpdateTaskHandler($repo, $calendar, $eventBus, new NullLogger());

        $handler(new UpdateTask(id: 't-1', title: 'New title'));

        self::assertSame('New title', $task->title()->value());
    }

    public function testThrowsWhenTaskNotFound(): void
    {
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $calendar = $this->createMock(CalendarServiceInterface::class);
        $eventBus = $this->createMock(MessageBusInterface::class);

        $handler = new UpdateTaskHandler($repo, $calendar, $eventBus, new NullLogger());

        $this->expectException(TaskNotFoundException::class);
        $handler(new UpdateTask(id: 'missing'));
    }
}
