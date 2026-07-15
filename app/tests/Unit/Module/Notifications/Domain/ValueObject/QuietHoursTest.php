<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Domain\ValueObject;

use App\Module\Notifications\Domain\ValueObject\QuietHours;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuietHoursTest extends TestCase
{
    public function testConstructsFromTimes(): void
    {
        $quietHours = QuietHours::fromTimes('22:00', '07:30');

        self::assertSame('22:00', $quietHours->start());
        self::assertSame('07:30', $quietHours->end());
    }

    public function testAcceptsTheDayBoundaries(): void
    {
        $quietHours = QuietHours::fromTimes('00:00', '23:59');

        self::assertSame('00:00', $quietHours->start());
        self::assertSame('23:59', $quietHours->end());
    }

    #[DataProvider('malformedTimes')]
    public function testThrowsWhenTimeIsMalformed(string $time): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quiet hours time must be in HH:MM format');

        QuietHours::fromTimes($time, '07:00');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTimes(): iterable
    {
        yield 'empty' => [''];
        yield 'hour out of range' => ['24:00'];
        yield 'minute out of range' => ['22:60'];
        yield 'missing leading zero' => ['9:00'];
        yield 'seconds included' => ['22:00:00'];
        yield 'not a time' => ['evening'];
    }

    public function testThrowsWhenStartEqualsEnd(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quiet hours start and end must differ.');

        QuietHours::fromTimes('22:00', '22:00');
    }

    public function testSameDayWindowIsNotOvernight(): void
    {
        self::assertFalse(QuietHours::fromTimes('09:00', '17:00')->isOvernight());
    }

    public function testWindowWrappingMidnightIsOvernight(): void
    {
        self::assertTrue(QuietHours::fromTimes('22:00', '07:00')->isOvernight());
    }

    public function testCoversInstantsInsideSameDayWindow(): void
    {
        $quietHours = QuietHours::fromTimes('09:00', '17:00');

        self::assertTrue($quietHours->covers(new DateTimeImmutable('2026-07-15 12:30')));
        self::assertFalse($quietHours->covers(new DateTimeImmutable('2026-07-15 08:59')));
        self::assertFalse($quietHours->covers(new DateTimeImmutable('2026-07-15 21:00')));
    }

    public function testStartIsInclusiveAndEndIsExclusive(): void
    {
        $quietHours = QuietHours::fromTimes('09:00', '17:00');

        self::assertTrue($quietHours->covers(new DateTimeImmutable('2026-07-15 09:00')));
        self::assertFalse($quietHours->covers(new DateTimeImmutable('2026-07-15 17:00')));
    }

    public function testCoversBothSidesOfMidnightForAnOvernightWindow(): void
    {
        $quietHours = QuietHours::fromTimes('22:00', '07:00');

        self::assertTrue($quietHours->covers(new DateTimeImmutable('2026-07-15 23:30')), 'before midnight');
        self::assertTrue($quietHours->covers(new DateTimeImmutable('2026-07-16 00:00')), 'midnight itself');
        self::assertTrue($quietHours->covers(new DateTimeImmutable('2026-07-16 06:59')), 'after midnight');
        self::assertFalse($quietHours->covers(new DateTimeImmutable('2026-07-16 07:00')), 'end is exclusive');
        self::assertFalse($quietHours->covers(new DateTimeImmutable('2026-07-15 12:00')), 'midday is outside');
    }

    public function testEqualsComparesTheWindow(): void
    {
        $quietHours = QuietHours::fromTimes('22:00', '07:00');

        self::assertTrue($quietHours->equals(QuietHours::fromTimes('22:00', '07:00')));
        self::assertFalse($quietHours->equals(QuietHours::fromTimes('22:00', '08:00')));
        self::assertFalse($quietHours->equals(QuietHours::fromTimes('23:00', '07:00')));
    }
}
