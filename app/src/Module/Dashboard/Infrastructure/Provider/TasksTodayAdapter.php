<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Provider;

use App\Module\Dashboard\Domain\ReadModel\TodayTask;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads the day's pending tasks straight from the `tasks` table via DBAL — no
 * import of any Tasks class, keeping the Dashboard ← Tasks boundary
 * deptrac-clean.
 */
final readonly class TasksTodayAdapter
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return TodayTask[]
     */
    public function todaysTasks(DateTimeImmutable $day): array
    {
        $dayStart = $day->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, time_start, time_end FROM tasks '
            .'WHERE status = :status AND time_start >= :from AND time_start < :to '
            .'ORDER BY time_start ASC',
            [
                'status' => 'pending',
                'from' => $dayStart->format('Y-m-d H:i:s'),
                'to' => $dayEnd->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(
            static fn (array $row): TodayTask => new TodayTask(
                (string) $row['id'],
                (string) $row['title'],
                new DateTimeImmutable((string) $row['time_start']),
                new DateTimeImmutable((string) $row['time_end']),
            ),
            $rows,
        );
    }
}
