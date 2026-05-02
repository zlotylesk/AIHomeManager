<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Persistence;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTaskRepository implements TaskRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Task $task): void
    {
        $this->entityManager->persist($task);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Task
    {
        return $this->entityManager->find(Task::class, $id);
    }

    /** @return Task[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT t FROM '.Task::class.' t')->getResult();
    }

    /** @return Task[] */
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->entityManager->createQuery(
            'SELECT t FROM '.Task::class.' t WHERE t.timeSlot.startDateTime BETWEEN :from AND :to'
        )
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getResult();
    }
}
