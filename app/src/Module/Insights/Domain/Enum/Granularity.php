<?php

declare(strict_types=1);

namespace App\Module\Insights\Domain\Enum;

use DateTimeImmutable;

/**
 * The size of one bucket on the trend chart. Only the two the epic asks for —
 * a day-level trend of a single-user system is mostly noise, and offering a
 * granularity no aggregator implements would be a promise nothing keeps.
 *
 * The bucket arithmetic lives here rather than in each adapter so that all of
 * them agree on where a week starts (the Goals
 * {@see \App\Module\Goals\Domain\Service\GoalProgressCalculator::windowStartFor}
 * precedent: ISO week, from Monday).
 */
enum Granularity: string
{
    case WEEK = 'week';
    case MONTH = 'month';

    /**
     * The inclusive start of the bucket the given moment falls into: the ISO
     * week's Monday, or the calendar month's first day — both at midnight.
     */
    public function bucketStart(DateTimeImmutable $moment): DateTimeImmutable
    {
        return match ($this) {
            self::WEEK => $moment->modify('monday this week')->setTime(0, 0),
            self::MONTH => $moment->modify('first day of this month')->setTime(0, 0),
        };
    }

    /**
     * The start of the bucket following the given one — the step a gap-filling
     * aggregator walks the window with. Month-aware, so a 31-day month never
     * skips or repeats a bucket.
     */
    public function nextBucketStart(DateTimeImmutable $bucketStart): DateTimeImmutable
    {
        return match ($this) {
            self::WEEK => $this->bucketStart($bucketStart)->modify('+7 days'),
            self::MONTH => $this->bucketStart($bucketStart)->modify('first day of next month'),
        };
    }
}
