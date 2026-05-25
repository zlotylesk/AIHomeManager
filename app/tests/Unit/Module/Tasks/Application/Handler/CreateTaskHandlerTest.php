<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\CreateTask;
use App\Module\Tasks\Application\Handler\CreateTaskHandler;
use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateTaskHandlerTest extends TestCase
{
    public function testCreatesTaskAndAssignsGoogleEventId(): void
    {
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Task $t) => 'google-123' === $t->googleEventId()
                && 'Buy milk' === $t->title()->value()
        ));

        $calendar = $this->createMock(CalendarServiceInterface::class);
        $calendar->expects(self::once())->method('createEvent')->willReturn('google-123');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->willReturnCallback(
            fn (object $event) => new Envelope($event)
        );

        $handler = new CreateTaskHandler($repo, $calendar, $eventBus, new NullLogger());

        $id = $handler(new CreateTask('Buy milk', '2025-06-01T09:00:00+02:00', '2025-06-01T10:00:00+02:00'));

        self::assertNotEmpty($id);
    }

    public function testGracefulDegradeWhenCalendarReturnsEmpty(): void
    {
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Task $t) => null === $t->googleEventId()
        ));

        $calendar = $this->createMock(CalendarServiceInterface::class);
        $calendar->method('createEvent')->willReturn('');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(fn (object $e) => new Envelope($e));

        $handler = new CreateTaskHandler($repo, $calendar, $eventBus, new NullLogger());

        $id = $handler(new CreateTask('Task', '2025-06-01T09:00:00+02:00', '2025-06-01T10:00:00+02:00'));

        self::assertNotEmpty($id);
    }
}
