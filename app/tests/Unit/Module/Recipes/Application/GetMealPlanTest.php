<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application;

use App\Module\Recipes\Application\PlanWindow;
use App\Module\Recipes\Application\Query\GetMealPlan;
use App\Module\Recipes\Application\Query\GetShoppingList;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The window rules themselves are pinned by PlanWindowTest; what matters here
 * is that both reads over the plan actually go through it, so neither can
 * drift into accepting a range the other refuses.
 */
final class GetMealPlanTest extends TestCase
{
    public function testTheCalendarCarriesAPlanWindow(): void
    {
        $query = new GetMealPlan(new DateTimeImmutable('2026-08-03'), new DateTimeImmutable('2026-08-09'));

        self::assertSame(7, $query->window->dayCount());
        self::assertSame('2026-08-03', $query->window->fromDate());
        self::assertSame('2026-08-09', $query->window->toDate());
    }

    public function testTheShoppingListCarriesAPlanWindow(): void
    {
        $query = new GetShoppingList(new DateTimeImmutable('2026-08-03'), new DateTimeImmutable('2026-08-09'));

        self::assertSame(7, $query->window->dayCount());
    }

    public function testTheCalendarRejectsAnInvertedWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GetMealPlan(new DateTimeImmutable('2026-08-09'), new DateTimeImmutable('2026-08-03'));
    }

    public function testTheShoppingListRejectsAnInvertedWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GetShoppingList(new DateTimeImmutable('2026-08-09'), new DateTimeImmutable('2026-08-03'));
    }

    public function testTheCalendarRejectsAnOverlongWindow(): void
    {
        $from = new DateTimeImmutable('2026-08-03');

        $this->expectException(InvalidArgumentException::class);

        new GetMealPlan($from, $from->modify(sprintf('+%d days', PlanWindow::MAX_DAYS)));
    }

    public function testTheShoppingListRejectsAnOverlongWindow(): void
    {
        $from = new DateTimeImmutable('2026-08-03');

        $this->expectException(InvalidArgumentException::class);

        new GetShoppingList($from, $from->modify(sprintf('+%d days', PlanWindow::MAX_DAYS)));
    }
}
