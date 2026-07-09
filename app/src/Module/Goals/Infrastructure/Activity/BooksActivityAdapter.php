<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Activity;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads Books activity (pages read per reading session) straight from the
 * `book_reading_sessions` table via DBAL. Reading the table with raw SQL imports
 * no Books class, so the Goals ← Books boundary stays deptrac-clean — the same
 * DBAL-for-reads rule the query handlers follow project-wide.
 */
final readonly class BooksActivityAdapter implements ActivityProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT date, pages_read FROM book_reading_sessions WHERE date BETWEEN :from AND :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): ActivityEvent => new ActivityEvent(
                GoalType::BOOK_PAGES,
                (int) $row['pages_read'],
                new DateTimeImmutable((string) $row['date']),
            ),
            $rows,
        );
    }
}
