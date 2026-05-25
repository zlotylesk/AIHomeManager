<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Handler;

use App\Module\Tasks\Application\Command\CompleteTask;
use App\Module\Tasks\Application\Exception\TaskNotFoundException;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CompleteTaskHandler
{
    public function __construct(
        private TaskRepositoryInterface $repository,
        #[Target('event.bus')]
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteTask $command): void
    {
        $task = $this->repository->findById($command->id);

        if (null === $task) {
            throw new TaskNotFoundException($command->id);
        }

        $task->complete();

        $this->repository->save($task);

        foreach ($task->releaseEvents() as $event) {
            $this->eventBus->dispatch($event);
        }

        $this->logger->info('Task completed', ['id' => $command->id]);
    }
}
