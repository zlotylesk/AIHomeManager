<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Insights\Domain\ValueObject;

use App\Module\Insights\Domain\ValueObject\MetricPoint;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MetricPointTest extends TestCase
{
    public function testExposesBucketAndValue(): void
    {
        $point = new MetricPoint(new DateTimeImmutable('2026-07-20 00:00:00'), 128.0);

        self::assertSame('2026-07-20', $point->bucketStart->format('Y-m-d'));
        self::assertSame(128.0, $point->value);
    }

    public function testAcceptsZeroBecauseAnInactiveBucketIsStillAPoint(): void
    {
        self::assertSame(0.0, new MetricPoint(new DateTimeImmutable('2026-07-20'), 0.0)->value);
    }

    public function testRejectsNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Metric value must not be negative');

        new MetricPoint(new DateTimeImmutable('2026-07-20'), -1.0);
    }

    public function testRejectsNonFiniteValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Metric value must be a finite number.');

        new MetricPoint(new DateTimeImmutable('2026-07-20'), \INF);
    }

    public function testEqualsIsValueBased(): void
    {
        $point = new MetricPoint(new DateTimeImmutable('2026-07-20 00:00:00'), 5.0);

        self::assertTrue($point->equals(new MetricPoint(new DateTimeImmutable('2026-07-20 00:00:00'), 5.0)));
        self::assertFalse($point->equals(new MetricPoint(new DateTimeImmutable('2026-07-20 00:00:00'), 6.0)));
        self::assertFalse($point->equals(new MetricPoint(new DateTimeImmutable('2026-07-27 00:00:00'), 5.0)));
    }
}
