<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\UpdateTask;
use App\Module\Tasks\Application\Exception\TaskNotFoundException;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateTaskHandler
{
    public function __construct(
        private TaskRepositoryInterface $repository,
        private CalendarServiceInterface $calendarService,
        #[Target('event.bus')]
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdateTask $command): void
    {
        $task = $this->repository->findById($command->id);

        if (null === $task) {
            throw new TaskNotFoundException($command->id);
        }

        $title = null !== $command->title
            ? new TaskTitle($command->title)
            : $task->title();

        $start = null !== $command->start
            ? new DateTimeImmutable($command->start)
            : $task->timeSlot()->startDateTime();

        $end = null !== $command->end
            ? new DateTimeImmutable($command->end)
            : $task->timeSlot()->endDateTime();

        $task->update($title, new TimeSlot($start, $end));

        $this->calendarService->updateEvent($task);

        $this->repository->save($task);

        foreach ($task->releaseEvents() as $event) {
            $this->eventBus->dispatch($event);
        }

        $this->logger->info('Task updated', ['id' => $command->id]);
    }
}
