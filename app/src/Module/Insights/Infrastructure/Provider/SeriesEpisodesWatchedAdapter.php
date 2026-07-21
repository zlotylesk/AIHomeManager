<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Provider;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use DateTimeImmutable;

/**
 * Watching pattern: episodes watched per bucket, read from `series_episodes`
 * via DBAL. An episode marked watched without a `watched_at` (the flag can be
 * set without a date) carries no point in time, so it cannot be placed on a
 * trend line and is left out rather than guessed onto today.
 */
final readonly class SeriesEpisodesWatchedAdapter extends BucketedTrendAdapter
{
    protected function metric(): MetricType
    {
        return MetricType::SERIES_EPISODES_WATCHED;
    }

    protected function valuesByBucket(Granularity $granularity, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->sumDaysIntoBuckets($granularity, $this->fetchDailyTotals(
            'SELECT DATE(watched_at) AS day, COUNT(*) AS value
             FROM series_episodes
             WHERE watched = 1 AND watched_at IS NOT NULL AND watched_at BETWEEN :from AND :to
             GROUP BY DATE(watched_at)',
            ['from' => $from->format('Y-m-d 00:00:00'), 'to' => $to->format('Y-m-d 23:59:59')],
        ));
    }
}
