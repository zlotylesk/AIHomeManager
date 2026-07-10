<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Application\CommandHandler;

use App\Module\Goals\Application\Command\UpdateGoal;
use App\Module\Goals\Application\CommandHandler\UpdateGoalHandler;
use App\Module\Goals\Application\Exception\GoalNotFoundException;
use App\Module\Goals\Domain\Entity\Goal;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\Repository\GoalRepositoryInterface;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UpdateGoalHandlerTest extends TestCase
{
    public function testUpdatesTargetAndPeriod(): void
    {
        $goal = new Goal('g-1', GoalType::BOOK_PAGES, new GoalTarget(50), Period::DAILY);

        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->method('findById')->willReturn($goal);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Goal $g): bool => 100 === $g->target()->value()
                && Period::MONTHLY === $g->period()
                && GoalType::BOOK_PAGES === $g->type()
        ));

        $handler = new UpdateGoalHandler($repo);
        $handler(new UpdateGoal('g-1', 100, 'monthly'));
    }

    public function testThrowsWhenGoalNotFound(): void
    {
        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateGoalHandler($repo);

        $this->expectException(GoalNotFoundException::class);
        $handler(new UpdateGoal('missing', 10, 'daily'));
    }

    public function testThrowsOnUnknownPeriodWithoutSaving(): void
    {
        $goal = new Goal('g-1', GoalType::BOOK_PAGES, new GoalTarget(50), Period::DAILY);

        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->method('findById')->willReturn($goal);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateGoalHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new UpdateGoal('g-1', 10, 'yearly'));
    }

    public function testThrowsOnNonPositiveTargetWithoutSaving(): void
    {
        $goal = new Goal('g-1', GoalType::BOOK_PAGES, new GoalTarget(50), Period::DAILY);

        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->method('findById')->willReturn($goal);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateGoalHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new UpdateGoal('g-1', 0, 'daily'));
    }
}
