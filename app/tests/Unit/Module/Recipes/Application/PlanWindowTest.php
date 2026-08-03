<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application;

use App\Module\Recipes\Application\PlanWindow;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlanWindowTest extends TestCase
{
    public function testCountsBothEnds(): void
    {
        $window = new PlanWindow(new DateTimeImmutable('2026-08-03'), new DateTimeImmutable('2026-08-09'));

        self::assertSame(7, $window->dayCount());
        self::assertSame('2026-08-03', $window->fromDate());
        self::assertSame('2026-08-09', $window->toDate());
    }

    public function testASingleDayIsAValidWindow(): void
    {
        $window = new PlanWindow(new DateTimeImmutable('2026-08-03'), new DateTimeImmutable('2026-08-03'));

        self::assertSame(1, $window->dayCount());
    }

    /**
     * A window built from a clock carries a time of day. Left unnormalised,
     * `to` would land mid-afternoon and the rest of the last day would fall
     * outside the range.
     */
    public function testIgnoresTheTimeOfDayItWasBuiltAt(): void
    {
        $window = new PlanWindow(
            new DateTimeImmutable('2026-08-03 18:30:00'),
            new DateTimeImmutable('2026-08-09 02:15:00'),
        );

        self::assertSame(7, $window->dayCount());
        self::assertSame('2026-08-03 00:00:00', $window->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-09 00:00:00', $window->to->format('Y-m-d H:i:s'));
    }

    public function testRejectsAnInvertedWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlanWindow(new DateTimeImmutable('2026-08-09'), new DateTimeImmutable('2026-08-03'));
    }

    public function testAcceptsTheLongestAllowedWindow(): void
    {
        $from = new DateTimeImmutable('2026-08-03');
        $to = $from->modify(sprintf('+%d days', PlanWindow::MAX_DAYS - 1));

        self::assertSame(PlanWindow::MAX_DAYS, new PlanWindow($from, $to)->dayCount());
    }

    public function testRejectsAWindowOneDayTooLong(): void
    {
        $from = new DateTimeImmutable('2026-08-03');
        $to = $from->modify(sprintf('+%d days', PlanWindow::MAX_DAYS));

        $this->expectException(InvalidArgumentException::class);

        new PlanWindow($from, $to);
    }
}
