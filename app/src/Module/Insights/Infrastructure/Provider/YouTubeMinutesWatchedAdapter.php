<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Provider;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use DateTimeImmutable;

/**
 * Watching pattern, the other half: YouTube minutes per bucket, read from
 * `videos` via DBAL.
 *
 * The measure is the watched video's full duration, because the watchlist
 * records *that a video was watched*, not how far into it the viewer got — so
 * this is "minutes of material completed", and a video abandoned halfway is
 * simply never marked watched.
 */
final readonly class YouTubeMinutesWatchedAdapter extends BucketedTrendAdapter
{
    protected function metric(): MetricType
    {
        return MetricType::YOUTUBE_MINUTES_WATCHED;
    }

    protected function valuesByBucket(Granularity $granularity, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->sumDaysIntoBuckets($granularity, $this->fetchDailyTotals(
            'SELECT DATE(watched_at) AS day, SUM(duration_seconds) / 60 AS value
             FROM videos
             WHERE watched_at IS NOT NULL AND watched_at BETWEEN :from AND :to
             GROUP BY DATE(watched_at)',
            ['from' => $from->format('Y-m-d 00:00:00'), 'to' => $to->format('Y-m-d 23:59:59')],
        ));
    }
}
