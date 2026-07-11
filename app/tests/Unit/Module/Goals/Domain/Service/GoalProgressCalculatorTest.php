<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Domain\Service;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use App\Module\Goals\Domain\Service\GoalProgressCalculator;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GoalProgressCalculatorTest extends TestCase
{
    private GoalProgressCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GoalProgressCalculator();
    }

    private function event(GoalType $type, int $value, string $at): ActivityEvent
    {
        return new ActivityEvent($type, $value, new DateTimeImmutable($at));
    }

    public function testWindowStartForEachPeriod(): void
    {
        $now = new DateTimeImmutable('2026-07-10 15:30:00');

        self::assertSame('2026-07-10 00:00:00', $this->calculator->windowStartFor(Period::DAILY, $now)->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-06 00:00:00', $this->calculator->windowStartFor(Period::WEEKLY, $now)->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-01 00:00:00', $this->calculator->windowStartFor(Period::MONTHLY, $now)->format('Y-m-d H:i:s'));
    }

    public function testDailyProgressSumsTodaysMatchingActivityOnly(): void
    {
        $now = new DateTimeImmutable('2026-07-10 15:00:00');
        $events = [
            $this->event(GoalType::BOOK_PAGES, 30, '2026-07-10 09:00:00'),   // in
            $this->event(GoalType::BOOK_PAGES, 20, '2026-07-10 00:00:00'),   // in (inclusive boundary)
            $this->event(GoalType::BOOK_PAGES, 100, '2026-07-09 23:59:59'),  // out (yesterday)
            $this->event(GoalType::BOOK_PAGES, 5, '2026-07-10 16:00:00'),    // out (after now)
            $this->event(GoalType::SERIES_EPISODES, 7, '2026-07-10 10:00:00'), // out (other type)
        ];

        $progress = $this->calculator->progress(GoalType::BOOK_PAGES, new GoalTarget(50), Period::DAILY, $events, $now);

        self::assertSame(50, $progress->achieved);
        self::assertSame(50, $progress->target);
        self::assertSame(100, $progress->percent);
        self::assertTrue($progress->isMet);
    }

    public function testProgressPercentIsFlooredAndBelowTarget(): void
    {
        $now = new DateTimeImmutable('2026-07-10 15:00:00');
        $events = [$this->event(GoalType::BOOK_PAGES, 30, '2026-07-10 09:00:00')];

        $progress = $this->calculator->progress(GoalType::BOOK_PAGES, new GoalTarget(50), Period::DAILY, $events, $now);

        self::assertSame(30, $progress->achieved);
        self::assertSame(60, $progress->percent);
        self::assertFalse($progress->isMet);
    }

    public function testProgressPercentCappedAtHundred(): void
    {
        $now = new DateTimeImmutable('2026-07-10 15:00:00');
        $events = [$this->event(GoalType::ARTICLES_READ, 120, '2026-07-10 09:00:00')];

        $progress = $this->calculator->progress(GoalType::ARTICLES_READ, new GoalTarget(50), Period::DAILY, $events, $now);

        self::assertSame(100, $progress->percent);
        self::assertTrue($progress->isMet);
    }

    public function testWeeklyWindowIncludesMondayBoundaryExcludesPreviousWeek(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');
        $monday = $now->modify('monday this week')->setTime(0, 0);
        $events = [
            new ActivityEvent(GoalType::SERIES_EPISODES, 2, $monday),                      // in (inclusive)
            new ActivityEvent(GoalType::SERIES_EPISODES, 1, $monday->modify('+2 days')),   // in
            new ActivityEvent(GoalType::SERIES_EPISODES, 9, $monday->modify('-1 second')), // out (last week)
        ];

        $progress = $this->calculator->progress(GoalType::SERIES_EPISODES, new GoalTarget(5), Period::WEEKLY, $events, $now);

        self::assertSame(3, $progress->achieved);
        self::assertSame(60, $progress->percent);
        self::assertFalse($progress->isMet);
    }

    public function testMonthlyWindowIncludesFirstDayExcludesPreviousMonth(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');
        $firstOfMonth = $now->modify('first day of this month')->setTime(0, 0);
        $events = [
            new ActivityEvent(GoalType::YOUTUBE_VIDEOS, 4, $firstOfMonth),                       // in (inclusive)
            new ActivityEvent(GoalType::YOUTUBE_VIDEOS, 3, $firstOfMonth->modify('-1 second')),  // out (June)
        ];

        $progress = $this->calculator->progress(GoalType::YOUTUBE_VIDEOS, new GoalTarget(10), Period::MONTHLY, $events, $now);

        self::assertSame(4, $progress->achieved);
        self::assertSame(40, $progress->percent);
    }

    public function testStreakCountsConsecutiveDaysEndingToday(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');
        $events = [
            $this->event(GoalType::BOOK_PAGES, 5, '2026-07-08 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 5, '2026-07-09 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 5, '2026-07-10 10:00:00'),
        ];

        $streak = $this->calculator->streak(GoalType::BOOK_PAGES, $events, $now);

        self::assertSame(3, $streak->currentLength);
        self::assertSame(3, $streak->longestLength);
        self::assertSame('2026-07-10', $streak->lastActivityDate?->format('Y-m-d'));
    }

    public function testStreakBreakResetsCurrentButKeepsLongest(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');
        $events = [
            // a run of 4 earlier in the month
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-01 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-02 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-03 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-04 10:00:00'),
            // gap, then a run of 2 ending today
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-09 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-10 10:00:00'),
        ];

        $streak = $this->calculator->streak(GoalType::BOOK_PAGES, $events, $now);

        self::assertSame(2, $streak->currentLength);
        self::assertSame(4, $streak->longestLength);
    }

    public function testStreakStaysAliveWhenLastActivityWasYesterday(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');
        $events = [
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-08 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-09 10:00:00'),
        ];

        $streak = $this->calculator->streak(GoalType::BOOK_PAGES, $events, $now);

        self::assertSame(2, $streak->currentLength);
        self::assertSame(2, $streak->longestLength);
    }

    public function testStreakBrokenWhenLastActivityOlderThanYesterday(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');
        $events = [
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-07 10:00:00'),
            $this->event(GoalType::BOOK_PAGES, 1, '2026-07-08 10:00:00'),
        ];

        $streak = $this->calculator->streak(GoalType::BOOK_PAGES, $events, $now);

        self::assertSame(0, $streak->currentLength);
        self::assertSame(2, $streak->longestLength);
        self::assertSame('2026-07-08', $streak->lastActivityDate?->format('Y-m-d'));
    }

    public function testStreakDeduplicatesSameDayAndIgnoresOtherTypes(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');
        $events = [
            $this->event(GoalType::BOOK_PAGES, 5, '2026-07-10 09:00:00'),
            $this->event(GoalType::BOOK_PAGES, 5, '2026-07-10 18:00:00'), // same day
            $this->event(GoalType::BOOK_PAGES, 5, '2026-07-09 10:00:00'),
            $this->event(GoalType::SERIES_EPISODES, 5, '2026-07-10 10:00:00'), // other type
        ];

        $streak = $this->calculator->streak(GoalType::BOOK_PAGES, $events, $now);

        self::assertSame(2, $streak->currentLength);
        self::assertSame(2, $streak->longestLength);
    }

    public function testNoActivityYieldsZeroStreak(): void
    {
        $now = new DateTimeImmutable('2026-07-10 12:00:00');

        $streak = $this->calculator->streak(GoalType::MUSIC_ALBUMS, [], $now);

        self::assertSame(0, $streak->currentLength);
        self::assertSame(0, $streak->longestLength);
        self::assertNull($streak->lastActivityDate);
    }
}
