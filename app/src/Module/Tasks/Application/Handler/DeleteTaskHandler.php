<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\DeleteTask;
use App\Module\Tasks\Application\Exception\TaskNotFoundException;
use App\Module\Tasks\Domain\Event\TaskDeleted;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteTaskHandler
{
    public function __construct(
        private TaskRepositoryInterface $repository,
        private CalendarServiceInterface $calendarService,
        #[Target('event.bus')]
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteTask $command): void
    {
        $task = $this->repository->findById($command->id);

        if (null === $task) {
            throw new TaskNotFoundException($command->id);
        }

        $googleEventId = $task->googleEventId();

        if (null !== $googleEventId) {
            $this->calendarService->deleteEvent($googleEventId);
        }

        $this->repository->remove($task);

        $this->eventBus->dispatch(new TaskDeleted($command->id, $googleEventId));

        $this->logger->info('Task deleted', ['id' => $command->id]);
    }
}
