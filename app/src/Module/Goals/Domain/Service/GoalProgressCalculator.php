<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Service;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use App\Module\Goals\Domain\ReadModel\GoalProgress;
use App\Module\Goals\Domain\ReadModel\StreakState;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use DateTimeImmutable;

/**
 * Pure progress- and streak-computation rules for goals. Takes an activity
 * stream (already read by an {@see \App\Module\Goals\Domain\Port\ActivityProviderInterface}
 * adapter) and folds it into read models — no I/O, no persistence, so the
 * continuity policy the {@see \App\Module\Goals\Domain\Entity\Streak} aggregate
 * deliberately left out lives here and is unit-testable in isolation.
 */
final class GoalProgressCalculator
{
    /**
     * The inclusive start of the rolling window the goal's target is measured
     * within, relative to $now: the day, the ISO week (from Monday), or the
     * calendar month.
     */
    public function windowStartFor(Period $period, DateTimeImmutable $now): DateTimeImmutable
    {
        return match ($period) {
            Period::DAILY => $now->setTime(0, 0),
            Period::WEEKLY => $now->modify('monday this week')->setTime(0, 0),
            Period::MONTHLY => $now->modify('first day of this month')->setTime(0, 0),
        };
    }

    /**
     * Sum the activity of the goal's type within its current period window and
     * compare it to the target.
     *
     * @param iterable<ActivityEvent> $events
     */
    public function progress(GoalType $type, GoalTarget $target, Period $period, iterable $events, DateTimeImmutable $now): GoalProgress
    {
        $windowStart = $this->windowStartFor($period, $now);

        $achieved = 0;
        foreach ($events as $event) {
            if ($event->type === $type && $event->occurredAt >= $windowStart && $event->occurredAt <= $now) {
                $achieved += $event->value;
            }
        }

        $targetValue = $target->value();
        $percent = min(100, (int) floor($achieved / $targetValue * 100));

        return new GoalProgress($targetValue, $achieved, $percent, $achieved >= $targetValue);
    }

    /**
     * Fold the activity of the given type into a day-continuity streak: the run
     * of consecutive calendar days with activity ending at the most recent active
     * day. The current run counts only while that day is today or yesterday (a
     * fully-missed day breaks it); the longest run ever seen is preserved.
     *
     * @param iterable<ActivityEvent> $events
     */
    public function streak(GoalType $type, iterable $events, DateTimeImmutable $now): StreakState
    {
        $activeDays = [];
        foreach ($events as $event) {
            if ($event->type === $type) {
                $activeDays[$event->occurredAt->format('Y-m-d')] = true;
            }
        }

        if ([] === $activeDays) {
            return new StreakState($type, 0, 0, null);
        }

        $dates = array_keys($activeDays);
        sort($dates);

        $longest = 1;
        $run = 1;
        for ($i = 1, $n = count($dates); $i < $n; ++$i) {
            $previous = new DateTimeImmutable($dates[$i - 1]);
            $current = new DateTimeImmutable($dates[$i]);

            $run = $previous->modify('+1 day')->format('Y-m-d') === $current->format('Y-m-d') ? $run + 1 : 1;
            $longest = max($longest, $run);
        }

        $lastDate = $dates[$n - 1];
        $isCurrent = $lastDate === $now->format('Y-m-d') || $lastDate === $now->modify('-1 day')->format('Y-m-d');

        return new StreakState($type, $isCurrent ? $run : 0, $longest, new DateTimeImmutable($lastDate));
    }
}
