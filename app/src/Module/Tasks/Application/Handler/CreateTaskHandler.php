<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\CreateTask;
use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateTaskHandler
{
    public function __construct(
        private TaskRepositoryInterface $repository,
        private CalendarServiceInterface $calendarService,
        #[Target('event.bus')]
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateTask $command): string
    {
        $id = Uuid::v4()->toRfc4122();

        $task = Task::create(
            $id,
            new TaskTitle($command->title),
            new TimeSlot(
                new DateTimeImmutable($command->start),
                new DateTimeImmutable($command->end),
            ),
        );

        $googleEventId = $this->calendarService->createEvent($task);
        if ('' !== $googleEventId) {
            $task->assignGoogleEventId($googleEventId);
        }

        $this->repository->save($task);

        foreach ($task->releaseEvents() as $event) {
            $this->eventBus->dispatch($event);
        }

        $this->logger->info('Task created', ['id' => $id, 'title' => $command->title]);

        return $id;
    }
}
