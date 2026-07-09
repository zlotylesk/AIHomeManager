<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Activity;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads YouTubeProgress activity (watched videos, one unit each) straight from
 * the `videos` table via DBAL — no import of any YouTubeProgress class, keeping
 * the Goals ← YouTubeProgress boundary deptrac-clean.
 */
final readonly class YouTubeActivityAdapter implements ActivityProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT watched_at FROM videos WHERE watched_at IS NOT NULL AND watched_at BETWEEN :from AND :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): ActivityEvent => new ActivityEvent(
                GoalType::YOUTUBE_VIDEOS,
                1,
                new DateTimeImmutable((string) $row['watched_at']),
            ),
            $rows,
        );
    }
}
