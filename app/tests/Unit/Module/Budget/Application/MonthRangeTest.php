<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application;

use App\Module\Budget\Application\MonthRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The month parse must be strict. PHP's own createFromFormat is not: it rolls
 * an out-of-range month over into a neighbouring year rather than rejecting
 * it, so before the epic review `?month=2026-13` answered 200 with January
 * 2027's figures labelled "2026-13" — a wrong answer presented as a correct
 * one.
 */
final class MonthRangeTest extends TestCase
{
    public function testResolvesTheHalfOpenDayRange(): void
    {
        $range = MonthRange::fromMonth('2026-07');

        self::assertSame('2026-07-01', $range->startDate());
        self::assertSame('2026-08-01', $range->endExclusiveDate());
    }

    /**
     * The end is exclusive and month-aware, so neither a 28-day February nor a
     * 31-day December drops or double-counts its last day.
     */
    public function testHandlesShortMonthsAndTheYearBoundary(): void
    {
        self::assertSame('2026-03-01', MonthRange::fromMonth('2026-02')->endExclusiveDate());
        self::assertSame('2025-03-01', MonthRange::fromMonth('2025-02')->endExclusiveDate());
        self::assertSame('2027-01-01', MonthRange::fromMonth('2026-12')->endExclusiveDate());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidMonths(): array
    {
        return [
            'month 13 must not roll into next January' => ['2026-13'],
            'month 00 must not roll back into December' => ['2026-00'],
            'unpadded month is not the documented YYYY-MM shape' => ['2026-7'],
            'a full date is not a month' => ['2026-07-15'],
            'no separator' => ['202607'],
            'empty' => [''],
            'not a date at all' => ['lipiec'],
        ];
    }

    #[DataProvider('invalidMonths')]
    public function testRejectsAnythingThatIsNotExactlyThatMonth(string $month): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected YYYY-MM');

        MonthRange::fromMonth($month);
    }
}
