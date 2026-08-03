<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Query;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The meal plan for an inclusive `[from, to]` window — a week, a month, or
 * whatever range the calendar is showing.
 *
 * The window guards its own size. Every day in the range costs a row in the
 * response whether or not anything is planned for it (the handler fills the
 * gaps so the client needs no date arithmetic), which means the payload is
 * proportional to the window rather than to the data — an unbounded range
 * would be expensive precisely when it returns nothing. The cap sits at a
 * quarter, comfortably beyond the week or month a calendar shows and beyond
 * any plausible "plan ahead", so a range that exceeds it is a mistake worth
 * reporting rather than a request worth serving slowly (the GetTrends
 * MAX_BUCKETS precedent).
 */
final readonly class GetMealPlan
{
    public const int MAX_DAYS = 92;

    public DateTimeImmutable $from;
    public DateTimeImmutable $to;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        // Normalised for the same reason the aggregate normalises: the window
        // is a range of days, and a caller building it from a clock would
        // otherwise clip part of the last day.
        $this->from = $from->setTime(0, 0);
        $this->to = $to->setTime(0, 0);

        if ($this->from > $this->to) {
            throw new InvalidArgumentException('The meal plan window must start no later than it ends.');
        }

        $days = $this->dayCount();

        if ($days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('The meal plan window covers %d days, more than the %d allowed.', $days, self::MAX_DAYS));
        }
    }

    /** The number of days in the window, both ends included. */
    public function dayCount(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }
}
