<?php

declare(strict_types=1);

namespace App\Module\Insights\Application\Query;

use App\Module\Insights\Domain\Enum\Granularity;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Asks for every habit metric over one window, bucketed by one granularity.
 *
 * The window is bounded on purpose. Each bucket becomes a point in memory, a row
 * in the JSON payload and a mark on the chart, so an unbounded range (say weekly
 * since 1990) is a slow query, an unreadable chart and a large cache entry all at
 * once. {@see MAX_BUCKETS} still covers more than two years of weeks or ten years
 * of months, which is far past what a habit trend is read for.
 */
final readonly class GetTrends
{
    public const int MAX_BUCKETS = 120;

    public function __construct(
        public Granularity $granularity,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($from > $to) {
            throw new InvalidArgumentException(sprintf('Trend window starts after it ends (%s > %s).', $from->format('Y-m-d'), $to->format('Y-m-d')));
        }

        $buckets = $this->bucketCount();
        if ($buckets > self::MAX_BUCKETS) {
            throw new InvalidArgumentException(sprintf('Trend window spans more than %d %s buckets.', self::MAX_BUCKETS, $granularity->value));
        }
    }

    /**
     * How many buckets the window covers, counted only as far as the cap — the
     * exact size of an over-long range does not matter, only that it is over.
     */
    public function bucketCount(): int
    {
        $cursor = $this->granularity->bucketStart($this->from);
        $lastBucket = $this->granularity->bucketStart($this->to)->getTimestamp();

        $count = 0;
        while ($cursor->getTimestamp() <= $lastBucket) {
            ++$count;
            if ($count > self::MAX_BUCKETS) {
                break;
            }
            $cursor = $this->granularity->nextBucketStart($cursor);
        }

        return $count;
    }
}
