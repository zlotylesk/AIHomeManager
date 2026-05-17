<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * HMAI-39: Seeds a handful of scheduled tasks anchored around "today" so
 * `/api/tasks/time-report` returns meaningful aggregates right after load.
 *
 * Tasks are not exposed via a create/list API yet (HMAI-43); fixtures are
 * the only path to populate the time-report view for manual exploration.
 */
final class TaskFixtures extends Fixture
{
    public function __construct(private readonly TaskRepositoryInterface $repository)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $today = new DateTimeImmutable('today');

        $seeds = [
            ['fixture-task-1', 'Morning deep-work block', $today->modify('+9 hours'), $today->modify('+11 hours')],
            ['fixture-task-2', 'Code review window', $today->modify('+14 hours'), $today->modify('+15 hours')],
            ['fixture-task-3', 'Weekly planning session', $today->modify('+16 hours'), $today->modify('+16 hours +30 minutes')],
            ['fixture-task-4', 'Yesterday\'s daily standup', $today->modify('-1 day +10 hours'), $today->modify('-1 day +10 hours +15 minutes')],
        ];

        foreach ($seeds as [$id, $title, $start, $end]) {
            $task = new Task(
                id: $id,
                title: new TaskTitle($title),
                timeSlot: new TimeSlot($start, $end),
            );
            $task->schedule();

            $this->repository->save($task);
        }
    }
}
