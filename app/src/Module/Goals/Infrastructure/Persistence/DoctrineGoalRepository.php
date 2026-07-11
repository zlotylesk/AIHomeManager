<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Persistence;

use App\Module\Goals\Domain\Entity\Goal;
use App\Module\Goals\Domain\Repository\GoalRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineGoalRepository implements GoalRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Goal $goal): void
    {
        $this->entityManager->persist($goal);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Goal
    {
        return $this->entityManager->find(Goal::class, $id);
    }

    /** @return Goal[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT g FROM '.Goal::class.' g')->getResult();
    }

    public function remove(Goal $goal): void
    {
        $this->entityManager->remove($goal);
        $this->entityManager->flush();
    }
}
