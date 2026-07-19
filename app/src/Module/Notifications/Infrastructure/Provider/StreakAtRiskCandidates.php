<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Provider;

use App\Module\Notifications\Domain\Port\NotificationCandidateProviderInterface;
use App\Shared\Notification\NotificationRequest;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads the persisted Goals streaks via DBAL — importing no Goals class, so the
 * boundary stays deptrac-clean.
 *
 * A streak is "at risk" when it is alive, has had no activity today, and the day
 * is late enough that saying so is actionable. The cutoff matters: warning at
 * 08:00 that today has no activity yet would fire every single morning and mean
 * nothing, so the warning only becomes true in the evening.
 */
final readonly class StreakAtRiskCandidates implements NotificationCandidateProviderInterface
{
    /**
     * The hour from which "no activity today" stops being normal and starts
     * being a warning worth sending.
     */
    private const int RISK_FROM_HOUR = 18;

    public function __construct(private Connection $connection)
    {
    }

    public function candidatesAt(DateTimeImmutable $at): array
    {
        if ((int) $at->format('G') < self::RISK_FROM_HOUR) {
            return [];
        }

        $today = $at->format('Y-m-d');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, current_length, longest_length, last_activity_date FROM streaks '
            .'WHERE current_length > 0 '
            .'AND (last_activity_date IS NULL OR DATE(last_activity_date) < :today) '
            .'ORDER BY type ASC',
            ['today' => $today],
        );

        return array_map(
            static fn (array $row): NotificationRequest => new NotificationRequest(
                type: 'goal_streak_at_risk',
                subject: 'streak-'.$row['type'],
                window: $today,
                payload: [
                    'goalType' => (string) $row['type'],
                    'currentLength' => (int) $row['current_length'],
                    'longestLength' => (int) $row['longest_length'],
                ],
            ),
            $rows,
        );
    }
}
