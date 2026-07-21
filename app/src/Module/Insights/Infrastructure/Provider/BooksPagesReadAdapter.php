<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Provider;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use DateTimeImmutable;

/**
 * Reading pace: pages read per bucket, straight from `book_reading_sessions`
 * via DBAL. Raw SQL imports no Books class, so the Insights ← Books boundary
 * stays deptrac-clean (the Goals HMAI-250 / Dashboard HMAI-258 precedent).
 */
final readonly class BooksPagesReadAdapter extends BucketedTrendAdapter
{
    protected function metric(): MetricType
    {
        return MetricType::BOOKS_PAGES_READ;
    }

    protected function valuesByBucket(Granularity $granularity, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->sumDaysIntoBuckets($granularity, $this->fetchDailyTotals(
            'SELECT date AS day, SUM(pages_read) AS value
             FROM book_reading_sessions
             WHERE date BETWEEN :from AND :to
             GROUP BY date',
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ));
    }
}
