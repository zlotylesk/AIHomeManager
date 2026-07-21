<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Provider;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use DateTimeImmutable;

/**
 * Discipline: the share of scheduled tasks actually completed per bucket, read
 * from `tasks` via DBAL.
 *
 * Two decisions the number depends on:
 *
 * - **Cancelled tasks count for neither side.** They were deliberately called
 *   off, so counting them as failures would punish the user for tidying up
 *   their own schedule; the denominator is what was still meant to happen
 *   (pending + completed).
 * - **The ratio is folded from bucket totals, not averaged from daily rates.**
 *   A day with one task done is 100%, and averaging that against a day with
 *   nine tasks and six done would weigh the two equally. Summing first gives
 *   the honest 7/10.
 *
 * A bucket with no tasks at all reports 0% rather than being omitted — the port
 * forbids holes, and an absent bucket would let the chart draw a straight line
 * across an idle stretch, which reads as continuity rather than as a gap.
 */
final readonly class TasksCompletionRateAdapter extends BucketedTrendAdapter
{
    protected function metric(): MetricType
    {
        return MetricType::TASKS_COMPLETION_RATE;
    }

    protected function valuesByBucket(Granularity $granularity, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DATE(time_start) AS day,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM tasks
             WHERE status <> 'cancelled' AND time_start BETWEEN :from AND :to
             GROUP BY DATE(time_start)",
            ['from' => $from->format('Y-m-d 00:00:00'), 'to' => $to->format('Y-m-d 23:59:59')],
        );

        /** @var array<string, array{total: int, completed: int}> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $key = $granularity->bucketStart(new DateTimeImmutable((string) $row['day']))->format('Y-m-d');
            $buckets[$key] ??= ['total' => 0, 'completed' => 0];
            $buckets[$key]['total'] += (int) $row['total'];
            $buckets[$key]['completed'] += (int) $row['completed'];
        }

        return array_map(
            static fn (array $bucket): float => 0 === $bucket['total']
                ? 0.0
                : round($bucket['completed'] / $bucket['total'] * 100, 2),
            $buckets,
        );
    }
}
