<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\CommandHandler;

use App\Module\Goals\Application\Command\DeleteGoal;
use App\Module\Goals\Application\Exception\GoalNotFoundException;
use App\Module\Goals\Domain\Repository\GoalRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteGoalHandler
{
    public function __construct(private GoalRepositoryInterface $goals)
    {
    }

    public function __invoke(DeleteGoal $command): void
    {
        $goal = $this->goals->findById($command->id);

        if (null === $goal) {
            throw new GoalNotFoundException(sprintf('Goal "%s" not found.', $command->id));
        }

        $this->goals->remove($goal);
    }
}
