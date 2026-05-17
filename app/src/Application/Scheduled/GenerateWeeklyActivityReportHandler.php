<?php

declare(strict_types=1);

namespace App\Application\Scheduled;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * HMAI-35: Aggregates the previous 7-day window directly via DBAL.
 *
 * Query-side numbers, not aggregate hydration — we never need the entities
 * here, only counts. Matches the CQRS convention for read-side handlers.
 */
#[AsMessageHandler]
final readonly class GenerateWeeklyActivityReportHandler
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateWeeklyActivityReport $command): void
    {
        $weekStart = new DateTimeImmutable('-7 days')->format('Y-m-d 00:00:00');
        $weekStartDate = new DateTimeImmutable('-7 days')->format('Y-m-d');

        $readArticles = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM articles WHERE is_read = 1 AND read_at >= :since',
            ['since' => $weekStart],
        );

        $pagesRead = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(pages_read), 0) FROM book_reading_sessions WHERE date >= :since',
            ['since' => $weekStartDate],
        );

        $completedTasks = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tasks WHERE status = 'completed' AND time_start >= :since",
            ['since' => $weekStart],
        );

        // series_episodes has no updated_at column (HMAI-35 review), so we report
        // the cumulative count of rated episodes — still useful as a "library
        // depth" indicator alongside the weekly read/completion metrics.
        $ratedEpisodesTotal = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM series_episodes WHERE rating_value IS NOT NULL',
        );

        $this->logger->info('Scheduled task completed', [
            'scheduled_task' => 'weekly_report',
            'window_start' => $weekStart,
            'read_articles' => $readArticles,
            'pages_read' => $pagesRead,
            'completed_tasks' => $completedTasks,
            'rated_episodes_total' => $ratedEpisodesTotal,
        ]);
    }
}
