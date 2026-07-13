<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Provider;

use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads the day's article pick straight from the `article_daily_picks` +
 * `articles` tables via DBAL — no import of any Articles class, keeping the
 * Dashboard ← Articles boundary deptrac-clean.
 */
final readonly class DailyArticleAdapter
{
    public function __construct(private Connection $connection)
    {
    }

    public function dailyArticle(DateTimeImmutable $day): ?DailyArticle
    {
        $dayStart = $day->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $row = $this->connection->fetchAssociative(
            'SELECT a.title, a.url, a.category, a.estimated_read_time, a.is_read '
            .'FROM article_daily_picks p '
            .'INNER JOIN articles a ON a.id = p.article_id '
            .'WHERE p.picked_at >= :from AND p.picked_at < :to '
            .'ORDER BY p.picked_at DESC LIMIT 1',
            [
                'from' => $dayStart->format('Y-m-d H:i:s'),
                'to' => $dayEnd->format('Y-m-d H:i:s'),
            ],
        );

        if (false === $row) {
            return null;
        }

        return new DailyArticle(
            (string) $row['title'],
            (string) $row['url'],
            null !== $row['category'] ? (string) $row['category'] : null,
            null !== $row['estimated_read_time'] ? (int) $row['estimated_read_time'] : null,
            (bool) $row['is_read'],
        );
    }
}
