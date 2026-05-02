<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Domain\ValueObject;

use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TimeSlotTest extends TestCase
{
    public function testDurationReturnsCorrectMinutes(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 10:30:00'),
        );

        self::assertSame(90, $slot->duration());
    }

    public function testDurationForExactOneHour(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2025-01-01 08:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );

        self::assertSame(60, $slot->duration());
    }

    public function testThrowsWhenEndTimeEqualsStartTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeSlot(
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );
    }

    public function testThrowsWhenEndTimeBeforeStartTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeSlot(
            new DateTimeImmutable('2025-01-01 10:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );
    }

    public function testStartAndEndDateTimesAreAccessible(): void
    {
        $start = new DateTimeImmutable('2025-01-01 09:00:00');
        $end = new DateTimeImmutable('2025-01-01 10:00:00');

        $slot = new TimeSlot($start, $end);

        self::assertEquals($start, $slot->startDateTime());
        self::assertEquals($end, $slot->endDateTime());
    }
}
