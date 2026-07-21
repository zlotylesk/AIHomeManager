<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Insights\Domain\ValueObject;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\ValueObject\MetricPoint;
use App\Module\Insights\Domain\ValueObject\TrendSeries;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TrendSeriesTest extends TestCase
{
    public function testHoldsOrderedPoints(): void
    {
        $series = new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            self::week('2026-07-06', 30.0),
            self::week('2026-07-13', 45.0),
            self::week('2026-07-20', 0.0),
        ]);

        self::assertFalse($series->isEmpty());
        self::assertSame(3, $series->count());
        self::assertSame(75.0, $series->total());
        self::assertSame(25.0, $series->average());
        self::assertSame('2026-07-20', $series->latest()?->bucketStart->format('Y-m-d'));
    }

    public function testEmptySeriesFoldsToZeroWithoutDividingByZero(): void
    {
        $series = new TrendSeries(MetricType::MUSIC_TRACKS_PLAYED, Granularity::MONTH, []);

        self::assertTrue($series->isEmpty());
        self::assertSame(0, $series->count());
        self::assertSame(0.0, $series->total());
        self::assertSame(0.0, $series->average());
        self::assertNull($series->latest());
    }

    /**
     * An inactive week is a zero, not an absent point — otherwise the average
     * would silently describe only the weeks something happened.
     */
    public function testZeroBucketsDragTheAverageDown(): void
    {
        $series = new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            self::week('2026-07-06', 60.0),
            self::week('2026-07-13', 0.0),
        ]);

        self::assertSame(30.0, $series->average());
    }

    public function testRejectsPointNotAlignedToItsBucket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not aligned to the start of its week bucket');

        new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            new MetricPoint(new DateTimeImmutable('2026-07-22 00:00:00'), 10.0),
        ]);
    }

    public function testRejectsPointCarryingATimeOfDay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            new MetricPoint(new DateTimeImmutable('2026-07-20 08:00:00'), 10.0),
        ]);
    }

    public function testRejectsDescendingPoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must ascend by bucket start');

        new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            self::week('2026-07-20', 10.0),
            self::week('2026-07-13', 20.0),
        ]);
    }

    public function testRejectsTwoPointsForTheSameBucket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one point per bucket');

        new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            self::week('2026-07-20', 10.0),
            self::week('2026-07-20', 20.0),
        ]);
    }

    public function testRejectsRateAboveOneHundred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the percent maximum');

        new TrendSeries(MetricType::TASKS_COMPLETION_RATE, Granularity::WEEK, [
            self::week('2026-07-20', 101.0),
        ]);
    }

    public function testCountMetricIsNotCappedAtOneHundred(): void
    {
        $series = new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            self::week('2026-07-20', 640.0),
        ]);

        self::assertSame(640.0, $series->total());
    }

    /**
     * Summing completion rates would put a nonsense figure on the dashboard, so
     * the headline of a rate is its average.
     */
    public function testHeadlineSumsCountsButAveragesRates(): void
    {
        $pages = new TrendSeries(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, [
            self::week('2026-07-13', 40.0),
            self::week('2026-07-20', 60.0),
        ]);
        $rate = new TrendSeries(MetricType::TASKS_COMPLETION_RATE, Granularity::WEEK, [
            self::week('2026-07-13', 40.0),
            self::week('2026-07-20', 60.0),
        ]);

        self::assertSame(100.0, $pages->headline());
        self::assertSame(50.0, $rate->headline());
    }

    private static function week(string $monday, float $value): MetricPoint
    {
        return new MetricPoint(new DateTimeImmutable($monday.' 00:00:00'), $value);
    }
}
