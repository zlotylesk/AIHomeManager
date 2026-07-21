<?php

declare(strict_types=1);

namespace App\Module\Insights\Domain\ValueObject;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use InvalidArgumentException;

/**
 * One metric plotted over time: what is measured, how wide a bucket is, and the
 * ordered points themselves.
 *
 * The invariants exist because a chart lies quietly when they are broken. Points
 * must sit exactly on a bucket boundary (a Wednesday labelled as a week would
 * silently shift the whole line), must ascend with no repeated bucket (two rows
 * for the same week means one of them was double-counted upstream), and must
 * respect the unit's range (a completion rate arriving as 0.42 instead of 42
 * would flatten the chart rather than fail).
 *
 * A bucket with no activity belongs here as a zero point. Dropping it would let
 * the renderer draw a straight line across the gap and make {@see average()}
 * report the average of the *active* buckets — which is not the trend anyone
 * asked for. Filling the window is therefore the provider's job, pinned by the
 * {@see \App\Module\Insights\Domain\Port\TrendDataProviderInterface} contract.
 */
final readonly class TrendSeries
{
    /** @var list<MetricPoint> */
    public array $points;

    /**
     * @param list<MetricPoint> $points ascending by bucket start, one per bucket
     */
    public function __construct(
        public MetricType $metric,
        public Granularity $granularity,
        array $points,
    ) {
        $maxValue = $metric->unit()->maxValue();
        $previous = null;

        foreach ($points as $point) {
            $aligned = $granularity->bucketStart($point->bucketStart);
            if ($point->bucketStart->getTimestamp() !== $aligned->getTimestamp()) {
                throw new InvalidArgumentException(sprintf('Metric point %s is not aligned to the start of its %s bucket (%s).', $point->bucketStart->format('Y-m-d H:i:s'), $granularity->value, $aligned->format('Y-m-d H:i:s')));
            }

            if (null !== $previous && $point->bucketStart->getTimestamp() <= $previous) {
                throw new InvalidArgumentException('Metric points must ascend by bucket start, with one point per bucket.');
            }

            if (null !== $maxValue && $point->value > $maxValue) {
                throw new InvalidArgumentException(sprintf('Metric value %s exceeds the %s maximum of %s.', (string) $point->value, $metric->unit()->value, (string) $maxValue));
            }

            $previous = $point->bucketStart->getTimestamp();
        }

        $this->points = $points;
    }

    public function isEmpty(): bool
    {
        return [] === $this->points;
    }

    public function count(): int
    {
        return count($this->points);
    }

    public function total(): float
    {
        return array_sum(array_map(static fn (MetricPoint $point): float => $point->value, $this->points));
    }

    public function average(): float
    {
        return $this->isEmpty() ? 0.0 : $this->total() / $this->count();
    }

    public function latest(): ?MetricPoint
    {
        return $this->points[$this->count() - 1] ?? null;
    }

    /**
     * The single number that honestly summarizes this series: the total for a
     * cumulative metric, the average for a rate. Callers that render "one big
     * figure" should read this instead of picking a fold themselves, which is
     * how a summed percentage ends up on a dashboard.
     */
    public function headline(): float
    {
        return $this->metric->unit()->isCumulative() ? $this->total() : $this->average();
    }
}
