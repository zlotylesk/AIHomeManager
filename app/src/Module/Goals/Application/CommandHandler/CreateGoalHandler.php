<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\CommandHandler;

use App\Module\Goals\Application\Command\CreateGoal;
use App\Module\Goals\Domain\Entity\Goal;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\Repository\GoalRepositoryInterface;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateGoalHandler
{
    public function __construct(private GoalRepositoryInterface $goals)
    {
    }

    public function __invoke(CreateGoal $command): string
    {
        $type = GoalType::tryFrom($command->type)
            ?? throw new InvalidArgumentException(sprintf('Unknown goal type "%s".', $command->type));
        $period = Period::tryFrom($command->period)
            ?? throw new InvalidArgumentException(sprintf('Unknown goal period "%s".', $command->period));

        $goal = new Goal(
            id: Uuid::v4()->toRfc4122(),
            type: $type,
            target: new GoalTarget($command->target),
            period: $period,
        );

        $this->goals->save($goal);

        return $goal->id();
    }
}
