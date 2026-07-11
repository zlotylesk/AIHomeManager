<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Application\CommandHandler;

use App\Module\Goals\Application\Command\CreateGoal;
use App\Module\Goals\Application\CommandHandler\CreateGoalHandler;
use App\Module\Goals\Domain\Entity\Goal;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\Repository\GoalRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreateGoalHandlerTest extends TestCase
{
    public function testCreatesGoalAndReturnsId(): void
    {
        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Goal $g): bool => GoalType::BOOK_PAGES === $g->type()
                && 50 === $g->target()->value()
                && Period::WEEKLY === $g->period()
        ));

        $handler = new CreateGoalHandler($repo);
        $id = $handler(new CreateGoal('book_pages', 50, 'weekly'));

        self::assertNotEmpty($id);
    }

    public function testThrowsOnUnknownType(): void
    {
        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateGoalHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateGoal('bogus', 50, 'weekly'));
    }

    public function testThrowsOnUnknownPeriod(): void
    {
        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateGoalHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateGoal('book_pages', 50, 'yearly'));
    }

    public function testThrowsOnNonPositiveTarget(): void
    {
        $repo = $this->createMock(GoalRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateGoalHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateGoal('book_pages', 0, 'weekly'));
    }
}
