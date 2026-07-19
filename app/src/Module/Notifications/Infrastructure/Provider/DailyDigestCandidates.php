<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Provider;

use App\Module\Notifications\Domain\Port\NotificationCandidateProviderInterface;
use App\Shared\Notification\NotificationRequest;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Summarises what the day holds, read via DBAL so no source module is imported.
 *
 * Deliberately quiet on an empty day: a digest that arrives every morning to say
 * "nothing" trains the user to ignore digests.
 *
 * NOTE: with every notification type enabled by default this overlaps the
 * individual reminders — the user turns off whichever half they do not want,
 * which is exactly what the per-type preference exists for.
 */
final readonly class DailyDigestCandidates implements NotificationCandidateProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function candidatesAt(DateTimeImmutable $at): array
    {
        $dayStart = $at->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $range = [
            'from' => $dayStart->format('Y-m-d H:i:s'),
            'to' => $dayEnd->format('Y-m-d H:i:s'),
        ];

        $items = [];

        $pendingTasks = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tasks WHERE status = :status AND time_start >= :from AND time_start < :to',
            ['status' => 'pending', ...$range],
        );

        if ($pendingTasks > 0) {
            $items[] = sprintf('Zadania na dziś: %d', $pendingTasks);
        }

        $article = $this->connection->fetchOne(
            'SELECT a.title FROM article_daily_picks p '
            .'INNER JOIN articles a ON a.id = p.article_id '
            .'WHERE p.picked_at >= :from AND p.picked_at < :to AND a.is_read = 0 '
            .'ORDER BY p.picked_at DESC LIMIT 1',
            $range,
        );

        if (false !== $article && null !== $article) {
            $items[] = sprintf('Artykuł dnia: %s', (string) $article);
        }

        if ([] === $items) {
            return [];
        }

        return [new NotificationRequest(
            type: 'daily_digest',
            subject: 'digest',
            window: $dayStart->format('Y-m-d'),
            payload: ['date' => $dayStart->format('Y-m-d'), 'items' => $items],
        )];
    }
}
