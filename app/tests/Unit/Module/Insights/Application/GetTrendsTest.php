<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Insights\Application;

use App\Module\Insights\Application\Query\GetTrends;
use App\Module\Insights\Domain\Enum\Granularity;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GetTrendsTest extends TestCase
{
    public function testCountsTheBucketsTheWindowSpans(): void
    {
        $query = new GetTrends(
            Granularity::WEEK,
            new DateTimeImmutable('2026-07-01'),
            new DateTimeImmutable('2026-07-31'),
        );

        // 2026-07-01 is a Wednesday, so the window opens in the 2026-06-29 week
        // and closes in the 2026-07-27 one — five buckets, not four.
        self::assertSame(5, $query->bucketCount());
    }

    public function testASingleDayIsOneBucket(): void
    {
        $day = new DateTimeImmutable('2026-07-15');

        self::assertSame(1, new GetTrends(Granularity::WEEK, $day, $day)->bucketCount());
        self::assertSame(1, new GetTrends(Granularity::MONTH, $day, $day)->bucketCount());
    }

    public function testRejectsAWindowThatEndsBeforeItStarts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('starts after it ends');

        new GetTrends(
            Granularity::WEEK,
            new DateTimeImmutable('2026-07-31'),
            new DateTimeImmutable('2026-07-01'),
        );
    }

    /**
     * Every bucket costs a point in memory, a row in the payload and a mark on
     * the chart, so an unbounded range is refused rather than served slowly.
     */
    public function testRejectsAWindowSpanningMoreBucketsThanTheCap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than 120 week buckets');

        new GetTrends(
            Granularity::WEEK,
            new DateTimeImmutable('2020-01-01'),
            new DateTimeImmutable('2026-01-01'),
        );
    }

    public function testTheCapItselfIsAccepted(): void
    {
        $from = new DateTimeImmutable('2026-07-06');
        $to = $from->modify('+'.(7 * (GetTrends::MAX_BUCKETS - 1)).' days');

        self::assertSame(GetTrends::MAX_BUCKETS, new GetTrends(Granularity::WEEK, $from, $to)->bucketCount());
    }

    public function testMonthlyGranularityStretchesMuchFurtherThanWeekly(): void
    {
        $query = new GetTrends(
            Granularity::MONTH,
            new DateTimeImmutable('2020-01-01'),
            new DateTimeImmutable('2026-01-01'),
        );

        self::assertSame(73, $query->bucketCount());
    }
}
