<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Provider;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use DateTimeImmutable;

/**
 * Listening habit: tracks played per bucket, read from
 * `music_listening_sessions` via DBAL.
 *
 * One row is one play — the table is already deduplicated by
 * `artist|title|playedAt|source`, so counting rows counts listens. The nullable
 * `play_count` column is deliberately ignored: only the manual log endpoint
 * ever sets it, while the Last.fm poll that produces virtually the whole
 * history leaves it null, so summing it would report a trend that collapses to
 * zero for every scrobbled week.
 */
final readonly class MusicTracksPlayedAdapter extends BucketedTrendAdapter
{
    protected function metric(): MetricType
    {
        return MetricType::MUSIC_TRACKS_PLAYED;
    }

    protected function valuesByBucket(Granularity $granularity, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->sumDaysIntoBuckets($granularity, $this->fetchDailyTotals(
            'SELECT DATE(played_at) AS day, COUNT(*) AS value
             FROM music_listening_sessions
             WHERE played_at BETWEEN :from AND :to
             GROUP BY DATE(played_at)',
            ['from' => $from->format('Y-m-d 00:00:00'), 'to' => $to->format('Y-m-d 23:59:59')],
        ));
    }
}
