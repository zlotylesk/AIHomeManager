<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Domain;

use App\Module\Goals\Domain\Entity\Goal;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GoalTest extends TestCase
{
    public function testConstructsWithProvidedAttributes(): void
    {
        $goal = new Goal(
            'g-0001',
            GoalType::BOOK_PAGES,
            new GoalTarget(100),
            Period::WEEKLY,
        );

        self::assertSame('g-0001', $goal->id());
        self::assertSame(GoalType::BOOK_PAGES, $goal->type());
        self::assertSame(100, $goal->target()->value());
        self::assertSame(Period::WEEKLY, $goal->period());
    }

    public function testThrowsWhenIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Goal id cannot be empty.');

        new Goal('', GoalType::BOOK_PAGES, new GoalTarget(10), Period::DAILY);
    }

    public function testThrowsWhenIdIsWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Goal("  \t", GoalType::BOOK_PAGES, new GoalTarget(10), Period::DAILY);
    }

    public function testChangeTargetReplacesTarget(): void
    {
        $goal = new Goal('g-0002', GoalType::SERIES_EPISODES, new GoalTarget(5), Period::DAILY);

        $goal->changeTarget(new GoalTarget(12));

        self::assertSame(12, $goal->target()->value());
    }

    public function testRescheduleChangesPeriod(): void
    {
        $goal = new Goal('g-0003', GoalType::MUSIC_ALBUMS, new GoalTarget(3), Period::DAILY);

        $goal->reschedule(Period::MONTHLY);

        self::assertSame(Period::MONTHLY, $goal->period());
    }
}
