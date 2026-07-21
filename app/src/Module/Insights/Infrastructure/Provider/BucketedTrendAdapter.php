<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Provider;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\Port\TrendDataProviderInterface;
use App\Module\Insights\Domain\ValueObject\MetricPoint;
use App\Module\Insights\Domain\ValueObject\TrendSeries;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Shared machinery for the per-source-module trend adapters.
 *
 * Every adapter aggregates **per day in SQL** and folds days into week/month
 * buckets here in PHP. That split is deliberate: grouping in SQL keeps the read
 * cheap (a year of listening collapses to at most 366 rows instead of tens of
 * thousands), while leaving the week/month boundary to
 * {@see Granularity::bucketStart()} keeps a single source of truth for where a
 * week starts — a hand-written `WEEKDAY()` expression per adapter would be five
 * chances to disagree with the Domain.
 *
 * Gap filling also lives here rather than in each adapter, because the port
 * promises a point for every bucket in the window and a missed zero is exactly
 * the kind of hole that makes a chart draw a straight line over an idle month.
 */
abstract readonly class BucketedTrendAdapter implements TrendDataProviderInterface
{
    public function __construct(protected Connection $connection)
    {
    }

    public function supports(MetricType $metric): bool
    {
        return $this->metric() === $metric;
    }

    public function seriesFor(
        MetricType $metric,
        Granularity $granularity,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): TrendSeries {
        if (!$this->supports($metric)) {
            throw new InvalidArgumentException(sprintf('%s reads %s, not %s.', static::class, $this->metric()->value, $metric->value));
        }

        return $this->fillWindow($granularity, $from, $to, $this->valuesByBucket($granularity, $from, $to));
    }

    /**
     * The one metric this adapter can read.
     */
    abstract protected function metric(): MetricType;

    /**
     * The measured value per bucket, keyed by the bucket start's `Y-m-d`. Only
     * buckets with data need to appear — the missing ones are filled with zero.
     *
     * @return array<string, float>
     */
    abstract protected function valuesByBucket(
        Granularity $granularity,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;

    /**
     * Fold a per-day map into per-bucket sums.
     *
     * @param array<string, float> $daily value per `Y-m-d`
     *
     * @return array<string, float>
     */
    final protected function sumDaysIntoBuckets(Granularity $granularity, array $daily): array
    {
        $buckets = [];
        foreach ($daily as $day => $value) {
            $key = $granularity->bucketStart(new DateTimeImmutable($day))->format('Y-m-d');
            $buckets[$key] = ($buckets[$key] ?? 0.0) + $value;
        }

        return $buckets;
    }

    /**
     * Read a `day`/`value` result set into a per-day map.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, float>
     */
    final protected function fetchDailyTotals(string $sql, array $parameters): array
    {
        $daily = [];
        foreach ($this->connection->fetchAllAssociative($sql, $parameters) as $row) {
            $daily[(string) $row['day']] = (float) $row['value'];
        }

        return $daily;
    }

    /**
     * @param array<string, float> $valuesByBucket
     */
    final protected function fillWindow(
        Granularity $granularity,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $valuesByBucket,
    ): TrendSeries {
        $points = [];
        $cursor = $granularity->bucketStart($from);
        $lastBucket = $granularity->bucketStart($to)->getTimestamp();

        while ($cursor->getTimestamp() <= $lastBucket) {
            $points[] = new MetricPoint($cursor, $valuesByBucket[$cursor->format('Y-m-d')] ?? 0.0);
            $cursor = $granularity->nextBucketStart($cursor);
        }

        return new TrendSeries($this->metric(), $granularity, $points);
    }
}
