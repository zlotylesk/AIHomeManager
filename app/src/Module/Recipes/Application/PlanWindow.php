<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The inclusive `[from, to]` range of days a plan read covers, and the one
 * place that range is validated.
 *
 * Both reads over the plan take the same window — the calendar (`GetMealPlan`)
 * and the shopping list (`GetShoppingList`) — so the rules live here rather
 * than being restated per query. Duplicated window validation is how one
 * reader would eventually end up accepting a range the other rejects, or
 * clipping a day the other keeps (the Budget `MonthRange` lesson, where the
 * same parse copy-pasted into three readers is what let a leniency bug spread).
 *
 * Both ends are normalised to midnight. A window built from a clock otherwise
 * carries a time of day, and `to` landing mid-afternoon would quietly drop the
 * rest of the last day — the calendar would be a day short and the shopping
 * list would miss whatever was planned for that evening.
 *
 * The size cap exists because the calendar's payload is proportional to the
 * *window* rather than to the data: it reports every day and every slot,
 * filled or not, so an unbounded range would be most expensive precisely when
 * it returns nothing. A quarter is well beyond any calendar view or plausible
 * "plan ahead", so exceeding it is a mistake worth reporting rather than a
 * request worth serving slowly (the `GetTrends` MAX_BUCKETS precedent).
 */
final readonly class PlanWindow
{
    public const int MAX_DAYS = 92;

    public DateTimeImmutable $from;
    public DateTimeImmutable $to;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        $this->from = $from->setTime(0, 0);
        $this->to = $to->setTime(0, 0);

        if ($this->from > $this->to) {
            throw new InvalidArgumentException('The plan window must start no later than it ends.');
        }

        $days = $this->dayCount();

        if ($days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('The plan window covers %d days, more than the %d allowed.', $days, self::MAX_DAYS));
        }
    }

    /** The number of days in the window, both ends included. */
    public function dayCount(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }
}
