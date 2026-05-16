<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tasks;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use App\Module\Tasks\Infrastructure\Persistence\DoctrineTaskRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TaskRepositoryTest extends KernelTestCase
{
    private DoctrineTaskRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineTaskRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE tasks');
    }

    public function testSaveAndFindById(): void
    {
        $task = new Task(
            id: 'b0000001-0000-0000-0000-000000000001',
            title: new TaskTitle('Buy groceries'),
            timeSlot: new TimeSlot(
                new DateTimeImmutable('2025-03-01 08:00:00'),
                new DateTimeImmutable('2025-03-01 09:00:00'),
            ),
        );

        $this->repository->save($task);
        $this->em->clear();

        $found = $this->repository->findById('b0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('b0000001-0000-0000-0000-000000000001', $found->id());
        self::assertSame('Buy groceries', $found->title()->value());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $result = $this->repository->findById('00000000-0000-0000-0000-000000000000');

        self::assertNull($result);
    }

    public function testFindAllReturnsAllSavedTasks(): void
    {
        $this->repository->save(new Task(
            id: 'b0000002-0000-0000-0000-000000000001',
            title: new TaskTitle('Task A'),
            timeSlot: new TimeSlot(
                new DateTimeImmutable('2025-03-01 08:00:00'),
                new DateTimeImmutable('2025-03-01 09:00:00'),
            ),
        ));
        $this->repository->save(new Task(
            id: 'b0000002-0000-0000-0000-000000000002',
            title: new TaskTitle('Task B'),
            timeSlot: new TimeSlot(
                new DateTimeImmutable('2025-03-02 08:00:00'),
                new DateTimeImmutable('2025-03-02 09:00:00'),
            ),
        ));
        $this->em->clear();

        $all = $this->repository->findAll();

        self::assertCount(2, $all);
    }

    public function testFindByDateRangeReturnsTasksWithinRange(): void
    {
        $this->repository->save(new Task(
            id: 'b0000003-0000-0000-0000-000000000001',
            title: new TaskTitle('In range'),
            timeSlot: new TimeSlot(
                new DateTimeImmutable('2025-05-15 10:00:00'),
                new DateTimeImmutable('2025-05-15 11:00:00'),
            ),
        ));
        $this->repository->save(new Task(
            id: 'b0000003-0000-0000-0000-000000000002',
            title: new TaskTitle('Out of range'),
            timeSlot: new TimeSlot(
                new DateTimeImmutable('2025-06-01 10:00:00'),
                new DateTimeImmutable('2025-06-01 11:00:00'),
            ),
        ));
        $this->em->clear();

        $results = $this->repository->findByDateRange(
            new DateTimeImmutable('2025-05-01'),
            new DateTimeImmutable('2025-05-31'),
        );

        self::assertCount(1, $results);
        self::assertSame('In range', $results[0]->title()->value());
    }

    public function testFindByDateRangeReturnsEmptyWhenNoTasksInRange(): void
    {
        $results = $this->repository->findByDateRange(
            new DateTimeImmutable('2020-01-01'),
            new DateTimeImmutable('2020-01-31'),
        );

        self::assertSame([], $results);
    }

    public function testFindByDateRangeIncludesTaskAtLowerBoundary(): void
    {
        // BETWEEN is inclusive on both sides. Pin that contract via the embedded
        // VO column path (`t.timeSlot.startDateTime`) — a mapping regression on
        // the embeddable would either drop boundary matches or fail outright.
        $boundary = new DateTimeImmutable('2025-07-01 00:00:00');
        $this->repository->save(new Task(
            id: 'b0000004-0000-0000-0000-000000000001',
            title: new TaskTitle('Starts at lower boundary'),
            timeSlot: new TimeSlot($boundary, $boundary->modify('+1 hour')),
        ));
        $this->em->clear();

        $results = $this->repository->findByDateRange(
            $boundary,
            new DateTimeImmutable('2025-07-31 23:59:59'),
        );

        self::assertCount(1, $results);
        self::assertSame('Starts at lower boundary', $results[0]->title()->value());
    }

    public function testFindByDateRangeIncludesTaskAtUpperBoundary(): void
    {
        $boundary = new DateTimeImmutable('2025-07-31 23:59:59');
        $this->repository->save(new Task(
            id: 'b0000004-0000-0000-0000-000000000002',
            title: new TaskTitle('Starts at upper boundary'),
            timeSlot: new TimeSlot($boundary, $boundary->modify('+1 hour')),
        ));
        $this->em->clear();

        $results = $this->repository->findByDateRange(
            new DateTimeImmutable('2025-07-01 00:00:00'),
            $boundary,
        );

        self::assertCount(1, $results);
        self::assertSame('Starts at upper boundary', $results[0]->title()->value());
    }

    public function testFindByDateRangeExcludesTaskStartingOneSecondPastUpperBoundary(): void
    {
        // Negative boundary: one second past the upper bound must be excluded.
        // Catches off-by-one regressions if the query ever switches to '<=' + custom comparison.
        $upper = new DateTimeImmutable('2025-07-31 23:59:59');
        $this->repository->save(new Task(
            id: 'b0000004-0000-0000-0000-000000000003',
            title: new TaskTitle('One second past upper'),
            timeSlot: new TimeSlot($upper->modify('+1 second'), $upper->modify('+2 seconds')),
        ));
        $this->em->clear();

        $results = $this->repository->findByDateRange(
            new DateTimeImmutable('2025-07-01 00:00:00'),
            $upper,
        );

        self::assertSame([], $results);
    }
}
