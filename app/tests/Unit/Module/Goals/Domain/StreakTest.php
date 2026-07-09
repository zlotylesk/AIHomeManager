<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Domain;

use App\Module\Goals\Domain\Entity\Streak;
use App\Module\Goals\Domain\Enum\GoalType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StreakTest extends TestCase
{
    public function testStartsEmptyByDefault(): void
    {
        $streak = new Streak('s-0001', GoalType::ARTICLES_READ);

        self::assertSame('s-0001', $streak->id());
        self::assertSame(GoalType::ARTICLES_READ, $streak->type());
        self::assertSame(0, $streak->currentLength());
        self::assertSame(0, $streak->longestLength());
        self::assertNull($streak->lastActivityDate());
    }

    public function testConstructsWithProvidedState(): void
    {
        $date = new DateTimeImmutable('2026-07-01');
        $streak = new Streak('s-0002', GoalType::BOOK_PAGES, 3, 7, $date);

        self::assertSame(3, $streak->currentLength());
        self::assertSame(7, $streak->longestLength());
        self::assertSame($date, $streak->lastActivityDate());
    }

    public function testThrowsWhenIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Streak id cannot be empty.');

        new Streak('   ', GoalType::BOOK_PAGES);
    }

    public function testThrowsWhenCurrentLengthNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current streak length cannot be negative.');

        new Streak('s-0003', GoalType::BOOK_PAGES, -1);
    }

    public function testThrowsWhenLongestLengthNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Longest streak length cannot be negative.');

        new Streak('s-0004', GoalType::BOOK_PAGES, 0, -1);
    }

    public function testThrowsWhenLongestSmallerThanCurrent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Longest streak length cannot be smaller than the current length.');

        new Streak('s-0005', GoalType::BOOK_PAGES, 5, 3);
    }

    public function testExtendIncrementsCurrentAndPromotesLongest(): void
    {
        $streak = new Streak('s-0006', GoalType::YOUTUBE_VIDEOS, 2, 2);

        $streak->extend(new DateTimeImmutable('2026-07-05'));

        self::assertSame(3, $streak->currentLength());
        self::assertSame(3, $streak->longestLength());
    }

    public function testExtendUpdatesLastActivityDate(): void
    {
        $streak = new Streak('s-0007', GoalType::YOUTUBE_VIDEOS);
        $date = new DateTimeImmutable('2026-07-06');

        $streak->extend($date);

        self::assertSame($date, $streak->lastActivityDate());
    }

    public function testExtendDoesNotLowerLongestWhenCurrentStaysBelowIt(): void
    {
        $streak = new Streak('s-0008', GoalType::MUSIC_ALBUMS, 1, 10);

        $streak->extend(new DateTimeImmutable('2026-07-07'));

        self::assertSame(2, $streak->currentLength());
        self::assertSame(10, $streak->longestLength());
    }

    public function testResetClearsCurrentButKeepsLongestAndLastActivity(): void
    {
        $date = new DateTimeImmutable('2026-07-08');
        $streak = new Streak('s-0009', GoalType::SERIES_EPISODES, 4, 9, $date);

        $streak->reset();

        self::assertSame(0, $streak->currentLength());
        self::assertSame(9, $streak->longestLength());
        self::assertSame($date, $streak->lastActivityDate());
    }
}
