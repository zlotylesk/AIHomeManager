<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\CancelTask;
use App\Module\Tasks\Application\Exception\TaskNotFoundException;
use App\Module\Tasks\Application\Handler\CancelTaskHandler;
use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Enum\TaskStatus;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CancelTaskHandlerTest extends TestCase
{
    public function testCancelsTaskAndDispatchesEvent(): void
    {
        $task = Task::create('t-1', new TaskTitle('Task'), new TimeSlot(
            new DateTimeImmutable('2025-06-01 09:00'),
            new DateTimeImmutable('2025-06-01 10:00'),
        ));
        $task->releaseEvents();

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn($task);
        $repo->expects(self::once())->method('save');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::atLeastOnce())->method('dispatch')->willReturnCallback(
            fn (object $e) => new Envelope($e)
        );

        $handler = new CancelTaskHandler($repo, $eventBus, new NullLogger());
        $handler(new CancelTask('t-1'));

        self::assertSame(TaskStatus::CANCELLED, $task->status());
    }

    public function testThrowsWhenTaskNotFound(): void
    {
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $eventBus = $this->createMock(MessageBusInterface::class);

        $handler = new CancelTaskHandler($repo, $eventBus, new NullLogger());

        $this->expectException(TaskNotFoundException::class);
        $handler(new CancelTask('missing'));
    }
}
