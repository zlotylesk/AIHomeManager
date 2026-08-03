<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application;

use App\Module\Recipes\Application\Query\GetMealPlan;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GetMealPlanTest extends TestCase
{
    public function testCountsBothEndsOfTheWindow(): void
    {
        $query = new GetMealPlan(new DateTimeImmutable('2026-08-03'), new DateTimeImmutable('2026-08-09'));

        self::assertSame(7, $query->dayCount());
    }

    public function testASingleDayIsAValidWindow(): void
    {
        $query = new GetMealPlan(new DateTimeImmutable('2026-08-03'), new DateTimeImmutable('2026-08-03'));

        self::assertSame(1, $query->dayCount());
    }

    /**
     * A window built from a clock carries a time of day. Left unnormalised,
     * `to` would land mid-afternoon and the day count — and the last day of
     * the plan — would depend on what time the request was made.
     */
    public function testTheWindowIgnoresTheTimeOfDayItWasBuiltAt(): void
    {
        $query = new GetMealPlan(
            new DateTimeImmutable('2026-08-03 18:30:00'),
            new DateTimeImmutable('2026-08-09 02:15:00'),
        );

        self::assertSame(7, $query->dayCount());
        self::assertSame('2026-08-03 00:00:00', $query->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-09 00:00:00', $query->to->format('Y-m-d H:i:s'));
    }

    public function testRejectsAnInvertedWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GetMealPlan(new DateTimeImmutable('2026-08-09'), new DateTimeImmutable('2026-08-03'));
    }

    public function testAcceptsTheLongestAllowedWindow(): void
    {
        $from = new DateTimeImmutable('2026-08-03');
        $to = $from->modify(sprintf('+%d days', GetMealPlan::MAX_DAYS - 1));

        self::assertSame(GetMealPlan::MAX_DAYS, new GetMealPlan($from, $to)->dayCount());
    }

    public function testRejectsAWindowOneDayTooLong(): void
    {
        $from = new DateTimeImmutable('2026-08-03');
        $to = $from->modify(sprintf('+%d days', GetMealPlan::MAX_DAYS));

        $this->expectException(InvalidArgumentException::class);

        new GetMealPlan($from, $to);
    }
}
