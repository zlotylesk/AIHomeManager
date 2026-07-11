<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Application\CommandHandler;

use App\Module\Goals\Application\Command\DeleteGoal;
use App\Module\Goals\Application\CommandHandler\DeleteGoalHandler;
use App\Module\Goals\Application\Exception\GoalNotFoundException;
use App\Module\Goals\Domain\Entity\Goal;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\Repository\GoalRepositoryInterface;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use PHPUnit\Framework\TestCase;

final class DeleteGoalHandlerTest extends TestCase
{
    public function testRemovesGoal(): void
    {
        $goal = new Goal('g-1', GoalType::SERIES_EPISODES, new GoalTarget(5), Period::WEEKLY);

        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->method('findById')->willReturn($goal);
        $repo->expects(self::once())->method('remove')->with($goal);

        $handler = new DeleteGoalHandler($repo);
        $handler(new DeleteGoal('g-1'));
    }

    public function testThrowsWhenGoalNotFound(): void
    {
        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('remove');

        $handler = new DeleteGoalHandler($repo);

        $this->expectException(GoalNotFoundException::class);
        $handler(new DeleteGoal('missing'));
    }
}
