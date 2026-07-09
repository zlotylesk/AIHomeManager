<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Activity;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads Articles activity (read articles, one unit each) straight from the
 * `articles` table via DBAL — no import of any Articles class, keeping the
 * Goals ← Articles boundary deptrac-clean.
 */
final readonly class ArticlesActivityAdapter implements ActivityProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT read_at FROM articles WHERE is_read = 1 AND read_at IS NOT NULL AND read_at BETWEEN :from AND :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): ActivityEvent => new ActivityEvent(
                GoalType::ARTICLES_READ,
                1,
                new DateTimeImmutable((string) $row['read_at']),
            ),
            $rows,
        );
    }
}
