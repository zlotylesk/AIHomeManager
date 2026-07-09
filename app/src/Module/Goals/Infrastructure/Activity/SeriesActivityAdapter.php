<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Activity;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads Series activity (watched episodes, one unit each) straight from the
 * `series_episodes` table via DBAL — no import of any Series class, keeping the
 * Goals ← Series boundary deptrac-clean.
 */
final readonly class SeriesActivityAdapter implements ActivityProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT watched_at FROM series_episodes WHERE watched = 1 AND watched_at IS NOT NULL AND watched_at BETWEEN :from AND :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): ActivityEvent => new ActivityEvent(
                GoalType::SERIES_EPISODES,
                1,
                new DateTimeImmutable((string) $row['watched_at']),
            ),
            $rows,
        );
    }
}
