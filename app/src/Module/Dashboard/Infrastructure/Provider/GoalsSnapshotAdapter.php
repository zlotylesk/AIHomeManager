<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Provider;

use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads goals and their persisted streaks straight from the `goals` + `streaks`
 * tables via DBAL — no import of any Goals class, keeping the Dashboard ← Goals
 * boundary deptrac-clean. Goals without a streak row surface with zero counters.
 */
final readonly class GoalsSnapshotAdapter
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return GoalSnapshot[]
     */
    public function goalSnapshots(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.type, g.target_value, g.period, '
            .'s.current_length, s.longest_length, s.last_activity_date '
            .'FROM goals g LEFT JOIN streaks s ON s.type = g.type '
            .'ORDER BY g.type ASC',
        );

        return array_map(
            static fn (array $row): GoalSnapshot => new GoalSnapshot(
                (string) $row['type'],
                (int) $row['target_value'],
                (string) $row['period'],
                null !== $row['current_length'] ? (int) $row['current_length'] : 0,
                null !== $row['longest_length'] ? (int) $row['longest_length'] : 0,
                null !== $row['last_activity_date']
                    ? new DateTimeImmutable((string) $row['last_activity_date'])
                    : null,
            ),
            $rows,
        );
    }
}
