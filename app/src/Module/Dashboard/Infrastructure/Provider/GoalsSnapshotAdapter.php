<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Provider;

use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use App\Shared\Activity\ActivityStreak;
use App\Shared\Activity\StreakReaderInterface;
use Doctrine\DBAL\Connection;

/**
 * Reads goals straight from the `goals` table via DBAL — no import of any Goals
 * class, keeping the Dashboard ← Goals boundary deptrac-clean.
 *
 * The streak beside each goal comes from the shared {@see StreakReaderInterface}
 * (HMAI-412), which is the same source `/api/goals/streaks` serves. It used to
 * be a LEFT JOIN onto the `streaks` table, and that table is only written by the
 * nightly recompute — so the cockpit was a night behind the goals page, and a
 * goal created today showed zeroes here because the join simply found no row.
 * A goal whose type has no streak yet still reports honest zeroes, as before.
 */
final readonly class GoalsSnapshotAdapter
{
    public function __construct(
        private Connection $connection,
        private StreakReaderInterface $streaks,
    ) {
    }

    /**
     * @return GoalSnapshot[]
     */
    public function goalSnapshots(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, target_value, period FROM goals ORDER BY type ASC',
        );

        $streaks = $this->streaks->streaksByType();

        return array_map(
            static function (array $row) use ($streaks): GoalSnapshot {
                $type = (string) $row['type'];
                // A goal whose type has no activity at all yet: honest zeroes and
                // no date, the same empty state this reported before.
                $streak = $streaks[$type] ?? new ActivityStreak($type, 0, 0, null);

                return new GoalSnapshot(
                    $type,
                    (int) $row['target_value'],
                    (string) $row['period'],
                    $streak->currentLength,
                    $streak->longestLength,
                    $streak->lastActivityDate,
                );
            },
            $rows,
        );
    }
}
