<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Provider;

use App\Module\Notifications\Domain\Port\NotificationCandidateProviderInterface;
use App\Shared\Notification\NotificationRequest;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads the day's article pick via DBAL — importing no Articles class, so the
 * boundary stays deptrac-clean.
 *
 * This is a scheduler trigger rather than a reactive one on purpose: the pick is
 * created lazily inside the Articles read path, so an event emitted there would
 * only ever fire when somebody opened the page — never proactively, which is the
 * opposite of what a notification is for. The sweep instead announces whatever
 * pick exists for the day, and stays silent when none has been made yet.
 *
 * An article already read needs no announcement.
 */
final readonly class DailyArticleCandidates implements NotificationCandidateProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function candidatesAt(DateTimeImmutable $at): array
    {
        $dayStart = $at->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $row = $this->connection->fetchAssociative(
            'SELECT a.id, a.title, a.url, a.category, a.estimated_read_time '
            .'FROM article_daily_picks p '
            .'INNER JOIN articles a ON a.id = p.article_id '
            .'WHERE p.picked_at >= :from AND p.picked_at < :to AND a.is_read = 0 '
            .'ORDER BY p.picked_at DESC LIMIT 1',
            [
                'from' => $dayStart->format('Y-m-d H:i:s'),
                'to' => $dayEnd->format('Y-m-d H:i:s'),
            ],
        );

        if (false === $row) {
            return [];
        }

        $payload = [
            'title' => (string) $row['title'],
            'url' => (string) $row['url'],
        ];

        if (null !== $row['category']) {
            $payload['category'] = (string) $row['category'];
        }

        if (null !== $row['estimated_read_time']) {
            $payload['readTime'] = (int) $row['estimated_read_time'];
        }

        return [new NotificationRequest(
            type: 'article_daily',
            subject: 'article-'.$row['id'],
            window: $dayStart->format('Y-m-d'),
            payload: $payload,
        )];
    }
}
