<?php

declare(strict_types=1);

namespace App\Module\Insights\Domain\Enum;

/**
 * What a metric's value actually measures. It is the carrier of two rules the
 * rest of the module reads off a series instead of re-deriving per metric: the
 * allowed value range, and which fold over a series is meaningful — summing a
 * completion rate is nonsense, summing pages read is exactly the point.
 * Backed values are the stable serialization contract.
 */
enum MetricUnit: string
{
    case COUNT = 'count';
    case MINUTES = 'minutes';
    case PERCENT = 'percent';

    /**
     * Whether the buckets of a series add up to something meaningful. A rate is
     * a snapshot per bucket, so it averages rather than sums.
     */
    public function isCumulative(): bool
    {
        return self::PERCENT !== $this;
    }

    /**
     * The upper bound a single bucket may carry, or null when unbounded.
     */
    public function maxValue(): ?float
    {
        return self::PERCENT === $this ? 100.0 : null;
    }
}
