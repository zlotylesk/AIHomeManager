<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Insights\Domain\Enum;

use App\Module\Insights\Domain\Enum\Granularity;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GranularityTest extends TestCase
{
    public function testWeekBucketStartsOnMonday(): void
    {
        $wednesday = new DateTimeImmutable('2026-07-22 13:45:10');

        self::assertSame(
            '2026-07-20 00:00:00',
            Granularity::WEEK->bucketStart($wednesday)->format('Y-m-d H:i:s'),
        );
    }

    public function testWeekBucketOfAMondayIsThatMonday(): void
    {
        $monday = new DateTimeImmutable('2026-07-20 23:59:59');

        self::assertSame(
            '2026-07-20 00:00:00',
            Granularity::WEEK->bucketStart($monday)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * ISO weeks run Monday–Sunday, so a Sunday belongs to the week that started
     * six days earlier — not to the one about to begin.
     */
    public function testSundayBelongsToTheWeekThatAlreadyStarted(): void
    {
        $sunday = new DateTimeImmutable('2026-07-26 10:00:00');

        self::assertSame(
            '2026-07-20 00:00:00',
            Granularity::WEEK->bucketStart($sunday)->format('Y-m-d H:i:s'),
        );
    }

    public function testMonthBucketStartsOnTheFirstDay(): void
    {
        $moment = new DateTimeImmutable('2026-07-22 13:45:10');

        self::assertSame(
            '2026-07-01 00:00:00',
            Granularity::MONTH->bucketStart($moment)->format('Y-m-d H:i:s'),
        );
    }

    public function testNextWeekBucketIsSevenDaysLater(): void
    {
        $bucket = new DateTimeImmutable('2026-07-20 00:00:00');

        self::assertSame(
            '2026-07-27 00:00:00',
            Granularity::WEEK->nextBucketStart($bucket)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Stepping by "+1 month" from a 31-day month would skip February entirely;
     * the month step must land on the next calendar month regardless of length.
     */
    public function testNextMonthBucketSurvivesShortMonths(): void
    {
        $january = new DateTimeImmutable('2026-01-01 00:00:00');

        $february = Granularity::MONTH->nextBucketStart($january);
        self::assertSame('2026-02-01 00:00:00', $february->format('Y-m-d H:i:s'));

        self::assertSame(
            '2026-03-01 00:00:00',
            Granularity::MONTH->nextBucketStart($february)->format('Y-m-d H:i:s'),
        );
    }

    public function testNextBucketNormalizesAMomentInsideTheBucket(): void
    {
        $midWeek = new DateTimeImmutable('2026-07-22 13:45:10');

        self::assertSame(
            '2026-07-27 00:00:00',
            Granularity::WEEK->nextBucketStart($midWeek)->format('Y-m-d H:i:s'),
        );
    }
}
