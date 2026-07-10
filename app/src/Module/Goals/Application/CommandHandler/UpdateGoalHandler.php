<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\CommandHandler;

use App\Module\Goals\Application\Command\UpdateGoal;
use App\Module\Goals\Application\Exception\GoalNotFoundException;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\Repository\GoalRepositoryInterface;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateGoalHandler
{
    public function __construct(private GoalRepositoryInterface $goals)
    {
    }

    public function __invoke(UpdateGoal $command): void
    {
        $goal = $this->goals->findById($command->id);

        if (null === $goal) {
            throw new GoalNotFoundException(sprintf('Goal "%s" not found.', $command->id));
        }

        $period = Period::tryFrom($command->period)
            ?? throw new InvalidArgumentException(sprintf('Unknown goal period "%s".', $command->period));

        $goal->changeTarget(new GoalTarget($command->target));
        $goal->reschedule($period);

        $this->goals->save($goal);
    }
}
