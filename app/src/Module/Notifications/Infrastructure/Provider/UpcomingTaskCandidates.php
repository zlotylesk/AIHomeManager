<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Provider;

use App\Module\Notifications\Domain\Port\NotificationCandidateProviderInterface;
use App\Shared\Notification\NotificationRequest;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads today's still-pending tasks straight from the `tasks` table via DBAL —
 * importing no Tasks class, so the Notifications ← Tasks boundary stays
 * deptrac-clean (the Dashboard/Goals adapter precedent).
 *
 * This is what catches the task the reactive rail could not: one scheduled days
 * ago, whose TaskCreated event fired long before the day arrived. A task created
 * today is announced by both rails, and the shared dedup identity
 * (subject "task-{id}" + window "{date}") collapses them into one.
 */
final readonly class UpcomingTaskCandidates implements NotificationCandidateProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function candidatesAt(DateTimeImmutable $at): array
    {
        $dayStart = $at->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, time_start FROM tasks '
            .'WHERE status = :status AND time_start >= :from AND time_start < :to '
            .'ORDER BY time_start ASC',
            [
                'status' => 'pending',
                'from' => $dayStart->format('Y-m-d H:i:s'),
                'to' => $dayEnd->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(
            static function (array $row) use ($dayStart): NotificationRequest {
                $startsAt = new DateTimeImmutable((string) $row['time_start']);

                return new NotificationRequest(
                    type: 'task_due',
                    subject: 'task-'.$row['id'],
                    window: $dayStart->format('Y-m-d'),
                    payload: [
                        'title' => (string) $row['title'],
                        'dueAt' => $startsAt->format('Y-m-d H:i'),
                    ],
                );
            },
            $rows,
        );
    }
}
